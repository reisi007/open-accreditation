<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FailedMailResource;
use App\Jobs\SendMandantMail;
use App\Models\FailedJob;
use App\Support\MandantContext;
use App\Support\QueuedMailPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Position 45 (2026-10-02): the dead-letter surface for undelivered mandant
 * mails — list and manual requeue.
 *
 *   GET  /api/admin/failed-mails              (list)
 *   POST /api/admin/failed-mails/{id}/requeue (move back onto the queue)
 *
 * Route-gated by `can:mails.dlq.manage` (mandant_admin; super_admin bypasses).
 * The MANDANT SCOPE is enforced here, not by the gate:
 *
 *  - `super_admin` sees and requeues every dead letter.
 *  - `mandant_admin` sees and requeues only rows whose `mandant_id` is his
 *    current mandant. A foreign id is a 404 — the same shape the tenant CRUD
 *    uses, and the reason the scope cannot be a silent WHERE: a failure list
 *    contains recipient addresses, so a cross-mandant read is a real leak.
 *
 * Requeue is exactly the framework's `queue:retry` (push the stored payload
 * back onto its connection/queue, then drop the dead letter), exposed to the
 * app because `queue:retry` is a shell command for people with deploy access.
 * The payload's `attempts` is reset so the re-queued job gets a fresh budget,
 * AND the mail job's `deliveryId` is re-stamped, so the requeue is a genuinely
 * fresh delivery rather than one the idempotency guard would refuse (see
 * `SendMandantMail` and `prepareForRequeue()`). The action is logged: a manual
 * requeue is a human decision and must be attributable (there is no automatic
 * path back out of `dead`).
 *
 * ## The `{message}` body is localized
 *
 * The UI shows this body verbatim (`frontend/src/logic/serverActionMessage.ts`),
 * so it used to be a hardcoded German literal that an `en` admin read as
 * German. It is now `mails.queued` — the SAME key the two resend endpoints use,
 * because the contract is identical: a delivery job was written, and this
 * process cannot know whether the relay ever answered.
 *
 * @see lang/de/mails.php
 */
class FailedMailController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = FailedJob::query()
            ->whereNotNull('mandant_id')
            ->orderByDesc('failed_at')
            ->orderByDesc('id');

        if (! $user->isSuperAdmin()) {
            $query->where('mandant_id', $this->currentMandantId());
        }

        // `whereNotNull('mandant_id')` already narrows to jobs the provider
        // identified as mandant mail; the payload check makes the discriminator
        // explicit rather than incidental (a future non-mail job must not leak
        // into this list because it happens to carry a mandant).
        $jobs = $query->get()
            ->filter(static fn (FailedJob $job): bool => $job->isMailJob())
            ->values();

        return FailedMailResource::collection($jobs);
    }

    public function requeue(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = FailedJob::query()->find($id);
        abort_if($job === null, 404);

        if (! $user->isSuperAdmin()) {
            abort_unless(
                $job->mandant_id !== null && (int) $job->mandant_id === $this->currentMandantId(),
                404,
            );
        }

        abort_unless($job->isMailJob(), 404);

        Queue::connection($job->connection)->pushRaw(
            $this->prepareForRequeue($job->payload),
            $job->queue,
        );

        $job->delete();

        Log::info('Failed mail requeued', [
            'failed_job_id' => $job->id,
            'mandant_id' => $job->mandant_id,
            'actor_id' => $user->getKey(),
        ]);

        return response()->json(['message' => __('mails.queued')]);
    }

    private function currentMandantId(): int
    {
        $mandantId = MandantContext::currentId();
        abort_if($mandantId === null, 404, 'No mandant context for this request.');

        return $mandantId;
    }

    /**
     * Prepare a stored payload to go back onto the queue: a fresh attempt
     * budget AND a fresh delivery identity.
     *
     * 1. **Attempts.** The database driver keeps attempts in the `jobs` row (a
     *    fresh row already starts at 0); the payload reset mirrors
     *    `Illuminate\Queue\Console\RetryCommand::resetAttempts()` for drivers
     *    that carry it in the payload.
     * 2. **`deliveryId`.** `SendMandantMail` refuses a send whose claim is
     *    still held (worker crash between claim and ack), by THROWING so the
     *    refusal lands in `failed_jobs` instead of vanishing. A dead letter can
     *    therefore exist WITH a live claim, and re-pushing the payload
     *    verbatim would requeue a job that is guaranteed to be refused again.
     *    Stamping a fresh id is the documented contract of the field ("fresh
     *    on every dispatch") and it is what makes the human's requeue the
     *    escape hatch from the guard rather than another trip into it. Measured
     *    before this change: the requeued job carried the SAME id.
     *
     * The re-serialization is safe because the restricted unserialize in
     * {@see QueuedMailPayload} only ever yields a `SendMandantMail` whose
     * properties are scalars and arrays (the mail itself is the pre-serialized
     * `mailablePayload` string) — `MailDeadLetterTest` pins that the mail
     * survives the round trip byte for byte apart from the id.
     */
    private function prepareForRequeue(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        if (array_key_exists('attempts', $decoded)) {
            $decoded['attempts'] = 0;
        }

        $mailJob = QueuedMailPayload::mailJob($payload);

        if ($mailJob !== null) {
            $mailJob->deliveryId = (string) Str::uuid();
            $decoded['data']['command'] = serialize($mailJob);
        }

        return (string) json_encode($decoded);
    }
}

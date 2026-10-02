<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FailedMailResource;
use App\Models\FailedJob;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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
 * and the action is logged — a manual requeue is a human decision and must be
 * attributable (there is no automatic path back out of `dead`).
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
            $this->resetAttempts($job->payload),
            $job->queue,
        );

        $job->delete();

        Log::info('Failed mail requeued', [
            'failed_job_id' => $job->id,
            'mandant_id' => $job->mandant_id,
            'actor_id' => $user->getKey(),
        ]);

        return response()->json(['message' => 'E-Mail wurde erneut in die Warteschlange gestellt.']);
    }

    private function currentMandantId(): int
    {
        $mandantId = MandantContext::currentId();
        abort_if($mandantId === null, 404, 'No mandant context for this request.');

        return $mandantId;
    }

    /**
     * Reset the payload's attempt counter so the requeued job starts with a
     * fresh backoff budget. The database driver keeps attempts in the `jobs`
     * row (a fresh row already starts at 0); the payload reset mirrors
     * `Illuminate\Queue\Console\RetryCommand::resetAttempts()` for drivers that
     * carry it in the payload.
     */
    private function resetAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        if (array_key_exists('attempts', $decoded)) {
            $decoded['attempts'] = 0;
        }

        return (string) json_encode($decoded);
    }
}

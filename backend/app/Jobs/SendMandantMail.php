<?php

namespace App\Jobs;

use App\Exceptions\MailDeliveryAlreadyClaimedException;
use App\Models\Mandant;
use App\Services\MandantMailerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Position 45 (2026-10-02): asynchronous, retried, dead-lettered mandant mail
 * delivery.
 *
 * `MandantMailerService::send()` no longer dials the relay inline — it
 * dispatches this job, and the job performs the actual send. That decouples
 * "the status transition persisted" from "the SMTP server answered" while
 * keeping BOTH facts: the decision and the delivery order are written in one
 * transaction (`config/queue.php` → `after_commit => true`), and a failed
 * delivery lands in `failed_jobs` instead of vanishing.
 *
 * ## Retries are capped, the end state is terminal
 *
 * `$tries = 5` with an explicit `backoff()`. After the last attempt the job is
 * dead-lettered (`failed_jobs`); there is NO code path that moves it back into
 * the queue automatically. The only way out is the admin requeue
 * (`POST /api/admin/failed-mails/{id}/requeue`, permission
 * `mails.dlq.manage`). This is deliberate: `SendReminders` is a recurring
 * producer, so "retry until it works" would grow without bound under a
 * permanently dead relay.
 *
 * ## The idempotency guard, and the polarity it forces
 *
 * A queue introduces exactly one new duplicate-delivery shape: the worker sends
 * the mail, dies before the job is acknowledged (`jobs` row deleted / acked),
 * and the job is re-run after `retry_after`. The guard is a test-and-set claim
 * keyed by `deliveryId` — the same shape as the conditional `where(...)` in
 * `AllocationRules::markStatus()`.
 *
 * **The claim is bounded and atomic.** `Cache::add($claim, true,
 * self::CLAIM_TTL_SECONDS)` passes a TTL on purpose, and that is not
 * cosmetics. `Illuminate\Cache\Repository::add()` only delegates to the store's
 * own `add()` **when a TTL is given**; without one it falls through to
 * `get()` + `put()` → `forever()`, i.e. `insert … on conflict do update set …`,
 * an unconditional upsert rather than a test-and-set. With the TTL the store
 * emits a single `insert or ignore` (SQLite) / `insert … on conflict do
 * nothing` (Postgres), which is the atomic part. The TTL is also what keeps the
 * `cache` table bounded: without it every delivered mail leaves a row that
 * expires in **10 years** (`now + 315360000`), and nothing prunes it.
 *
 * **On a claim hit the job THROWS — it does not return.** Returning normally
 * was the bug this replaced: the worker then treats the job as done and
 * DELETES it, so a claim left behind by a worker that died between "claimed"
 * and "sent" produced no mail, no `failed_jobs` row and no log line. Throwing
 * keeps the refusal visible — the job retries under its own cap and, if the
 * claim is still held after the last attempt, ends up in `failed_jobs` where a
 * human can see and requeue it. "No mail should be lost" allows a visible
 * ambiguity; it does not allow a silent one.
 *
 * **Recovery is the human's requeue.** `FailedMailController::prepareForRequeue()`
 * mints a FRESH `deliveryId`, so a requeued dead letter carries a new claim key
 * and delivers on its first attempt. That is deliberately independent of the
 * claim TTL: waiting for a window to expire is not a designed recovery path, it
 * is arithmetic on two unrelated numbers.
 *
 * **Why this and not plain at-least-once?** At-least-once (no guard) removes
 * the duplicate but throws away Nutzerentscheidung 5, which required the guard
 * to be born with the queue. What it buys is bounded, *deliberate* duplicates
 * instead of an accidental one per crash, and every duplicate here is preceded
 * by a visible `failed_jobs` entry plus a manual, logged decision.
 *
 * On a delivery EXCEPTION the claim is released before the exception
 * propagates, so a transient relay failure still retries normally.
 *
 * The claim lives on the default cache store. In production that is the
 * `database` store (`CACHE_STORE=database`, the same durable store the JWT
 * blacklist uses), not the per-process `array` store the test suite pins; the
 * deploy does not clear it (`deployment/entrypoint.sh`, pinned by a test).
 */
final class SendMandantMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds a delivery claim survives.
     *
     * One invariant and one direction:
     *
     *  - It MUST exceed the queue's `retry_after` (90 s by default, pinned in
     *    `deployment/docker-compose.yml`). A crash-before-ack re-run only
     *    happens once the job becomes available again, so a claim that expires
     *    before that would let the duplicate through. `SendMandantMailTest`
     *    pins the inequality so raising `retry_after` forces a decision here.
     *  - It MUST be finite. An unbounded claim leaves one `cache` row per
     *    delivered mail that nothing ever prunes.
     *
     * One hour is far above `retry_after` and below anything an operator would
     * call an outage; a manual requeue does not have to wait for it (§ the class
     * docblock — requeue mints a fresh `deliveryId`).
     */
    public const CLAIM_TTL_SECONDS = 3600;

    /**
     * Delivery attempts before the job is dead-lettered. The supervisor runs
     * `queue:work`, but the job's own `$tries` wins over the CLI default, so the
     * cap travels with the payload instead of the worker invocation.
     */
    public int $tries = 5;

    /**
     * Stable across retries of the SAME queued job (it is serialized into the
     * payload), and fresh on every dispatch — i.e. on every admin requeue, which
     * is exactly the granularity the idempotency guard needs. The requeue
     * re-stamps it explicitly (see
     * `App\Http\Controllers\Api\Admin\FailedMailController::prepareForRequeue()`).
     */
    public string $deliveryId;

    /**
     * Denormalized for the dead-letter surface: reading them off the payload
     * must not require reviving the mail. See `App\Support\QueuedMailPayload`.
     */
    public string $mailableClass;

    public ?string $recipient;

    /**
     * The mail, pre-serialized at dispatch time.
     *
     * Deliberately an opaque string rather than a typed `Mailable` property:
     * the dead-letter surface must be able to read the job's scalars
     * (`mandantId`, `mailableClass`, `recipient`) WITHOUT reviving the mail —
     * a typed `Mailable` property forces the whole object graph (and, via
     * `SerializesModels`, the applicant) to be restored just to list a failure.
     * Serializing eagerly keeps `SerializesModels`' model-identifier semantics
     * (the mailable is unserialized and its models restored in `handle()`).
     */
    public string $mailablePayload;

    public function __construct(
        public int $mandantId,
        Mailable $mailable,
        ?string $deliveryId = null,
    ) {
        $this->deliveryId = $deliveryId ?? (string) Str::uuid();
        $this->mailableClass = $mailable::class;
        $this->recipient = $this->firstRecipient($mailable);
        $this->mailablePayload = serialize($mailable);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(MandantMailerService $mailer): void
    {
        $claim = $this->claimKey();

        // Atomic test-and-set. `false` means an earlier attempt of this very
        // delivery already holds the claim — the worker died between claiming
        // and completing the send (SIGKILL on a hanging relay, OOM, a container
        // restart mid-delivery). This must NOT be swallowed: returning normally
        // here made the worker DELETE the job, and the mail vanished with no
        // `failed_jobs` row and no log line. Throw instead, so the refusal
        // surfaces (retry → dead letter) and a human can requeue it.
        if (! Cache::add($claim, true, self::CLAIM_TTL_SECONDS)) {
            throw MailDeliveryAlreadyClaimedException::forDelivery($this->deliveryId, self::CLAIM_TTL_SECONDS);
        }

        try {
            $mandant = Mandant::query()->find($this->mandantId);

            if ($mandant === null) {
                // Nothing to deliver, nothing to retry: the mandant is gone. The
                // claim is released because no send is coming, and the deletion
                // leaves a breadcrumb instead of ending in silence.
                Cache::forget($claim);

                Log::info('Mandant mail skipped: the mandant no longer exists', [
                    'mandant_id' => $this->mandantId,
                    'mailable' => $this->mailableClass,
                    'delivery_id' => $this->deliveryId,
                ]);

                return;
            }

            $mailer->deliver($mandant, $this->reviveMailable());
        } catch (Throwable $e) {
            // Release the claim so the queue's retry (and, ultimately, the
            // manual requeue) can attempt the delivery again.
            Cache::forget($claim);

            throw $e;
        }
    }

    /**
     * Called by the queue when the job is finally dead-lettered. This is the
     * audited form of the old inline `Log::warning`: a delivery that never
     * happened is recorded, with the mandant it belonged to. It also fires for
     * a {@see MailDeliveryAlreadyClaimedException}, which is the one case where
     * the mail may well have gone out — the log is the place that says so.
     */
    public function failed(?Throwable $e): void
    {
        Log::warning('Mandant mail dispatch failed', [
            'mandant_id' => $this->mandantId,
            'mailable' => $this->mailableClass,
            'delivery_id' => $this->deliveryId,
            'refused_as_duplicate' => $e instanceof MailDeliveryAlreadyClaimedException,
            'error' => $e?->getMessage(),
        ]);
    }

    private function claimKey(): string
    {
        return 'mail-delivery:'.$this->deliveryId;
    }

    /**
     * Revive the mail from its payload. `allowed_classes => true` is safe here:
     * the string was produced by us at dispatch time (see the property
     * docblock), never by a request, and the mailables need their model classes
     * to restore through `SerializesModels`.
     */
    private function reviveMailable(): Mailable
    {
        $mailable = unserialize($this->mailablePayload);

        if (! $mailable instanceof Mailable) {
            throw new \UnexpectedValueException('The queued mail payload did not contain a mailable.');
        }

        return $mailable;
    }

    private function firstRecipient(Mailable $mailable): ?string
    {
        $first = $mailable->to[0] ?? null;

        if (is_array($first)) {
            $address = $first['address'] ?? null;

            return is_string($address) ? $address : null;
        }

        return is_string($first) ? $first : null;
    }
}

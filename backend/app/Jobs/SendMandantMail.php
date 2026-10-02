<?php

namespace App\Jobs;

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
 * ## Idempotency guard against a worker crash before the ack
 *
 * A queue introduces exactly one new duplicate-delivery shape: the worker sends
 * the mail, dies before the job is acknowledged (`jobs` row deleted / acked),
 * and the job is re-run after `retry_after`. The guard is an atomic claim
 * (`Cache::add`, the test-and-set analog of the conditional `where(...)` in
 * `AllocationRules::markStatus()`): the second run finds the claim and returns
 * without sending. On a delivery EXCEPTION the claim is released before the
 * exception propagates, so a transient relay failure still retries normally —
 * only a run that returned successfully leaves the claim behind.
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
     * Delivery attempts before the job is dead-lettered. The supervisor runs
     * `queue:work`, but the job's own `$tries` wins over the CLI default, so the
     * cap travels with the payload instead of the worker invocation.
     */
    public int $tries = 5;

    /**
     * Stable across retries of the SAME queued job (it is serialized into the
     * payload), and fresh on every dispatch — i.e. on every admin requeue. That
     * is exactly the granularity the idempotency guard needs.
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
        // delivery already completed (crash-before-ack) — do not send again.
        if (! Cache::add($claim, true)) {
            return;
        }

        try {
            $mandant = Mandant::query()->find($this->mandantId);

            if ($mandant === null) {
                // Nothing to deliver, nothing to retry: the mandant is gone.
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
     * happened is recorded, with the mandant it belonged to.
     */
    public function failed(?Throwable $e): void
    {
        Log::warning('Mandant mail dispatch failed', [
            'mandant_id' => $this->mandantId,
            'mailable' => $this->mailableClass,
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

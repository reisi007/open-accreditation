<?php

namespace Tests\Unit;

use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use App\Services\MandantMailerService;
use App\Support\QueuedMailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PlainTestMailable;
use Tests\TestCase;
use Throwable;

/**
 * Position 45 (2026-10-02): the delivery job's own guarantees.
 *
 * 1. Retries are CAPPED (`$tries` + `backoff()`), so a permanently dead relay
 *    ends in the dead-letter store instead of an unbounded retry loop.
 * 2. The idempotency guard closes the one duplicate-delivery shape a queue
 *    introduces: a worker that sent the mail and died before the ack. The
 *    second run finds the claim and sends nothing.
 *
 * The guard is an atomic test-and-set (`Cache::add`), the analogue of the
 * conditional `where(...)` in `AllocationRules::markStatus()`: the second run
 * "matches zero rows". It is keyed by the job's `deliveryId`, which is stable
 * across retries of the SAME queued job and fresh on every new dispatch.
 */
class SendMandantMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_retry_budget_is_capped_with_a_backoff(): void
    {
        $job = new SendMandantMail(1, (new PlainTestMailable)->to('a@example.test'));

        $this->assertGreaterThan(0, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }

    public function test_a_second_execution_of_the_same_job_does_not_send_again(): void
    {
        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));

        $job->handle(app(MandantMailerService::class));
        $job->handle(app(MandantMailerService::class));

        // MUTATION: drop the `Cache::add` guard in `handle()` and this becomes 2.
        Mail::assertSent(PlainTestMailable::class, 1);
    }

    public function test_a_failed_attempt_releases_the_claim_so_a_retry_can_send(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => ['host' => '127.0.0.1', 'port' => 1],
        ]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));

        try {
            $job->handle(app(MandantMailerService::class));
        } catch (Throwable) {
            // the transport failure is expected and deliberately propagated
        }

        $this->assertFalse(
            Cache::has('mail-delivery:'.$job->deliveryId),
            'a failed attempt must not leave a claim behind, or the queue retry would be a no-op',
        );
    }

    public function test_the_dead_letter_scalars_survive_serialization(): void
    {
        $job = new SendMandantMail(7, (new PlainTestMailable)->to('victim@example.test'));

        $restored = unserialize(serialize($job), ['allowed_classes' => [SendMandantMail::class]]);

        $this->assertInstanceOf(SendMandantMail::class, $restored);
        $this->assertSame(7, $restored->mandantId);
        $this->assertSame(PlainTestMailable::class, $restored->mailableClass);
        $this->assertSame('victim@example.test', $restored->recipient);
        $this->assertSame($job->deliveryId, $restored->deliveryId);
    }

    public function test_the_payload_reader_extracts_the_mandant_without_reviving_the_mail(): void
    {
        $job = new SendMandantMail(11, (new PlainTestMailable)->to('victim@example.test'));

        $payload = json_encode([
            'uuid' => 'uuid-1',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => SendMandantMail::class,
                'command' => serialize($job),
            ],
        ]);

        $this->assertSame(11, QueuedMailPayload::mandantId($payload));
        $this->assertSame(PlainTestMailable::class, QueuedMailPayload::mailJob($payload)?->mailableClass);
    }
}

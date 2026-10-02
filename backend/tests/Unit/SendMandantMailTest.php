<?php

namespace Tests\Unit;

use App\Exceptions\MailDeliveryAlreadyClaimedException;
use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use App\Services\MandantMailerService;
use App\Support\QueuedMailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * 3. The guard REFUSES LOUDLY. Returning normally would make the worker delete
 *    the job, and a claim left by a worker that died between "claimed" and
 *    "sent" would take the mail with it: no mail, no `failed_jobs` row, no log.
 *    The end-to-end form of that (claim → no send → visible in `failed_jobs`)
 *    lives in `MailDeadLetterTest`; the refusal itself is here.
 * 4. The claim is ATOMIC and BOUNDED. `Cache::add()` is only a test-and-set
 *    when it is given a TTL — without one `Illuminate\Cache\Repository::add()`
 *    falls through to `get()` + `put()` → `forever()`, an unconditional upsert.
 *    With the TTL the database store writes a single `insert or ignore`
 *    (SQLite) / `insert … on conflict do nothing` (Postgres). The TTL is also
 *    what keeps the `cache` table from growing a 10-year row per delivered mail.
 *
 * The guard is keyed by the job's `deliveryId`, which is stable across retries
 * of the SAME queued job and fresh on every new dispatch (including the manual
 * DLQ requeue, which re-stamps it — see `MailDeadLetterTest`).
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

    /**
     * MUTATION (throw → return): drop the `throw` and this test fails on the
     * `expectException` instead — and, worse, the job would be deleted.
     */
    public function test_a_second_execution_of_the_same_job_refuses_to_send_again(): void
    {
        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));

        $job->handle(app(MandantMailerService::class));

        try {
            $job->handle(app(MandantMailerService::class));
            $this->fail('the second run must refuse, not return normally — a normal return deletes the job');
        } catch (MailDeliveryAlreadyClaimedException $e) {
            $this->assertStringContainsString($job->deliveryId, $e->getMessage());
        }

        Mail::assertSent(PlainTestMailable::class, 1);
    }

    /**
     * MUTATION (remove the guard): without the `Cache::add` claim this fails,
     * because the "second run" then sends and the assertion sees 2 mails.
     */
    public function test_a_claim_from_a_dead_worker_makes_the_next_run_refuse(): void
    {
        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));

        // Exactly the state a worker leaves behind when it is killed between
        // claiming the delivery and completing the send (SIGKILL on a hanging
        // relay, OOM, a container restart mid-delivery).
        $this->assertTrue(Cache::add('mail-delivery:'.$job->deliveryId, true, SendMandantMail::CLAIM_TTL_SECONDS));

        try {
            $job->handle(app(MandantMailerService::class));
            $this->fail('a held claim must produce a visible failure, not a silent skip');
        } catch (MailDeliveryAlreadyClaimedException) {
            // expected
        }

        Mail::assertNothingSent();

        $this->assertTrue(
            Cache::has('mail-delivery:'.$job->deliveryId),
            'a refused run must NOT release the claim — releasing it would turn the next retry into a duplicate send',
        );
    }

    /**
     * MUTATION (drop the TTL from `Cache::add`): the stored expiration becomes
     * `now + 315360000` (ten years) instead of ~now + CLAIM_TTL_SECONDS, and
     * the two assertions below both fail.
     */
    public function test_the_claim_is_bounded_instead_of_living_for_ten_years(): void
    {
        Mail::fake();

        config(['cache.default' => 'database']);

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));

        $job->handle(app(MandantMailerService::class));

        $row = DB::table('cache')->where('key', 'like', '%mail-delivery:'.$job->deliveryId)->first();

        $this->assertNotNull($row, 'the claim must live in the configured store');

        $ttl = (int) $row->expiration - now()->getTimestamp();

        $this->assertGreaterThan(0, $ttl, 'a claim that is already expired cannot suppress anything');
        $this->assertLessThanOrEqual(
            SendMandantMail::CLAIM_TTL_SECONDS,
            $ttl,
            'the claim must expire within CLAIM_TTL_SECONDS; a longer life is what turns every delivered mail into a ten-year `cache` row',
        );
    }

    /**
     * MUTATION (drop the TTL): `Repository::add()` then never reaches the
     * store's own `add()` and writes `insert … on conflict … do update set …`
     * instead of an ignore — an unconditional upsert, i.e. NOT a test-and-set.
     * This is the measured shape on both engines.
     */
    public function test_the_claim_is_written_with_an_atomic_insert_not_an_overwriting_upsert(): void
    {
        Mail::fake();

        config(['cache.default' => 'database']);

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $job->handle(app(MandantMailerService::class));

        $writes = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_starts_with(strtolower(trim($q['query'])), 'insert')
                && (bool) array_filter(
                    array_map('strval', $q['bindings']),
                    static fn (string $binding): bool => str_contains($binding, 'mail-delivery:'.$job->deliveryId),
                ),
        ));

        DB::disableQueryLog();

        $this->assertCount(1, $writes, 'the claim must be exactly one write statement');

        $this->assertStringNotContainsString(
            'do update set',
            $writes[0]['query'],
            'an upsert overwrites an existing claim, so two workers racing on the same deliveryId both get `true` and both send',
        );

        // SQLite compiles it as `insert or ignore`, Postgres as
        // `insert … on conflict do nothing`; both refuse the second writer.
        $this->assertMatchesRegularExpression(
            '/insert (or ignore )?.*(on conflict do nothing)?/i',
            $writes[0]['query'],
        );
    }

    /**
     * The claim window must outlast the window in which a crashed job comes
     * back: a `retry_after` LONGER than the claim would let the duplicate
     * through. This fails loudly if an operator raises `DB_QUEUE_RETRY_AFTER`
     * past the constant instead of making the decision consciously.
     */
    public function test_the_claim_window_outlives_the_queue_retry_window(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(
            $retryAfter,
            SendMandantMail::CLAIM_TTL_SECONDS,
            'the claim must outlive `retry_after`, otherwise the crash-before-ack re-run finds an expired claim and sends a duplicate',
        );
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

    /**
     * The one "nothing to deliver" exit must still leave a trace: a mandant
     * deleted after the mail was ordered is ordinary, but it must not be a
     * silent end — and it must not burn the claim either.
     */
    public function test_a_deleted_mandant_is_recorded_and_claims_nothing(): void
    {
        Log::spy();

        $job = new SendMandantMail(4242, (new PlainTestMailable)->to('a@example.test'));

        $job->handle(app(MandantMailerService::class));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Mandant mail skipped: the mandant no longer exists'
                && $context['mandant_id'] === 4242);

        $this->assertFalse(
            Cache::has('mail-delivery:'.$job->deliveryId),
            'a mandant that no longer exists must not leave a claim behind that says "already sent"',
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

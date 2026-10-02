<?php

namespace Tests\Unit;

use App\Exceptions\MailDeliveryAlreadyClaimedException;
use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use App\Services\MandantMailerService;
use App\Support\QueuedMailPayload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
 *    what makes an old claim INVISIBLE; it is not what keeps the `cache` table
 *    small, and the class no longer claims that (measured:
 *    {@see test_an_expired_claim_is_invisible_but_its_row_survives_until_something_reads_it()}).
 * 5. The claim window outlives the WHOLE retry budget, not just `retry_after`.
 *    Necessary, not sufficient — see
 *    {@see test_the_claim_window_outlives_the_whole_retry_budget()}, which is
 *    where "the guard refuses loudly" stops being a promise.
 *
 * The guard is keyed by the job's `deliveryId`, which is stable across retries
 * of the SAME queued job and fresh on every new dispatch (including the manual
 * DLQ requeue, which re-stamps it — see `MailDeadLetterTest`).
 */
class SendMandantMailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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
     * through.
     *
     * WHAT THIS ACTUALLY SEES: the suite's own
     * `config('queue.connections.database.retry_after')` — it does NOT read
     * `deployment/docker-compose.yml`, so raising `DB_QUEUE_RETRY_AFTER` there
     * does not turn this red. What it does cover is a change in
     * `config/queue.php` or in the suite's resolved value; the compose value is
     * pinned elsewhere (the supervisor's `QUEUE_WORKER_TIMEOUT < retry_after`
     * guard, `deployment/backend-supervisor.sh:107-111`).
     *
     * This inequality is NECESSARY AND NOT SUFFICIENT — the job's own `backoff()`
     * decides when the LAST attempt runs, not `retry_after`. The test that
     * carries the guarantee is
     * {@see test_the_claim_window_outlives_the_whole_retry_budget()}.
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

    /**
     * THE load-bearing inequality: `CLAIM_TTL_SECONDS > array_sum(backoff())`.
     *
     * `> retry_after` is not enough, and the measured reason is the job's own
     * schedule. With the old TTL of 3600 s the attempts land at
     * t = 0 / 60 / 360 / 1260 / 4860; the claim died at t = 3600, so attempt 5
     * found no claim and SENT — measured `jobs=0`, `failed_jobs=0`, no log. The
     * guard did not prevent the duplicate, it postponed it past the end of its
     * own retry budget and made it invisible. That is worse than no guard: the
     * old "return normally" behaviour at least showed up in `failed_jobs`.
     *
     * The second half is the same thing as a STATE rather than as arithmetic:
     * the job is re-run at exactly `array_sum(backoff())` — the moment of its
     * last attempt — and must be refused with a SECOND mail unsent. A TTL below
     * the sum makes `DatabaseStore::add()`'s internal read expire (and lazily
     * delete) the row, so the insert succeeds and the second mail goes out.
     *
     * MUTATION (TTL 21600 → 3600): the `assertGreaterThan` below fails with
     * "CLAIM_TTL_SECONDS must exceed the entire retry budget"; with that
     * assertion removed the behavioural half fails on `assertSent(…, 1)`.
     */
    public function test_the_claim_window_outlives_the_whole_retry_budget(): void
    {
        Mail::fake();

        config(['cache.default' => 'database']);

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));
        $budget = array_sum($job->backoff());

        // PREMISE: the sum of the backoff IS the span of the retry budget, so
        // the inequality below is measured against the whole budget and not
        // against a number that only looks like one. Without this, raising
        // `$tries` without extending `backoff()` would silently shrink the
        // window this test claims to protect.
        $this->assertCount(
            $job->tries - 1,
            $job->backoff(),
            'PREMISE: one backoff entry per retry, so array_sum(backoff()) is the span between attempt 1 and the last one. '
            .'Otherwise this test would be asserting against a number that no longer describes the schedule.',
        );

        $this->assertGreaterThan(
            $budget,
            SendMandantMail::CLAIM_TTL_SECONDS,
            'CLAIM_TTL_SECONDS must exceed the ENTIRE retry budget (array_sum(backoff())), not just `retry_after`: '
            .'a claim that expires before the last attempt lets that attempt through, and the duplicate it permits '
            .'is invisible — no `failed_jobs` row, no log.',
        );

        // Attempt 1 delivers and leaves the claim behind — the state a worker
        // that died before the ack leaves.
        $job->handle(app(MandantMailerService::class));
        Mail::assertSent(PlainTestMailable::class, 1);

        // Attempt 5, on schedule.
        Carbon::setTestNow(now()->addSeconds($budget));

        try {
            $job->handle(app(MandantMailerService::class));
            $this->fail('the last attempt must still be inside the claim window and must refuse, not deliver a second time');
        } catch (MailDeliveryAlreadyClaimedException) {
            // expected — the claim outlived the budget
        }

        Mail::assertSent(PlainTestMailable::class, 1);
    }

    /**
     * WHAT THE TTL DOES AND DOES NOT BUY (Befund, `low`): an expired claim is
     * gone from every READ, but its ROW is still in `cache`.
     *
     * `DatabaseStore::many()` deletes expired rows lazily, on a read of that key
     * (`vendor/…/Cache/DatabaseStore.php:147-157`). `add()` reads first
     * (`:214-218`). So an expired claim does release the guard — that is exactly
     * how the duplicate of {@see test_the_claim_window_outlives_the_whole_retry_budget()}
     * used to get through — but nothing sweeps the rows on its own, and for a
     * DELIVERED mail nobody ever reads that key again: the `deliveryId` is
     * fresh, there is no follow-up claim. One `cache` row per delivered mail
     * therefore survives forever.
     *
     * Measured here in both directions, with two claims that differ in exactly
     * one thing: `probe` is read after its TTL, the delivery's own claim is
     * not.
     *
     * There is also nothing that could prune it: Laravel 13.33.0 has NO
     * `cache:prune` command (measured — `php artisan list` knows only
     * `cache:prune-stale-tags`, which is Redis-only and reaps stale TAGS), and
     * no scheduled task touches the cache table. Both are asserted as premises,
     * because a premise that is not measured is how `features/mail-delivery.md`
     * came to promise a `cache:prune` that does not exist.
     *
     * MUTATION (add a scheduled/`cache:prune` reaper for the cache table):
     * this test goes red and the honest wording in `features/mail-delivery.md`
     * §4.3 has to be revisited as a decision rather than as a description.
     */
    public function test_an_expired_claim_is_invisible_but_its_row_survives_until_something_reads_it(): void
    {
        Mail::fake();

        config(['cache.default' => 'database']);

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('a@example.test'));
        $job->handle(app(MandantMailerService::class));

        $prefix = app('cache')->store()->getPrefix();
        $claimKey = $prefix.'mail-delivery:'.$job->deliveryId;

        $this->assertTrue(
            Cache::add('mail-delivery:probe', true, SendMandantMail::CLAIM_TTL_SECONDS),
            'PREMISE: a second, unrelated claim can be written, so the two rows below differ only in whether they are read.',
        );

        // One second past the window.
        Carbon::setTestNow(now()->addSeconds(SendMandantMail::CLAIM_TTL_SECONDS + 1));

        // (1) The claim is gone from every read — that is what the window buys.
        $this->assertFalse(
            Cache::has('mail-delivery:probe'),
            'an expired claim must be invisible, otherwise the window releases nothing and the guard never yields',
        );

        // (2) …and reading it is what physically removed its row.
        $this->assertDatabaseMissing(
            'cache',
            ['key' => $prefix.'mail-delivery:probe'],
            null,
            'DatabaseStore::many() deletes expired rows on the read that observes them — the lazy half of the mechanism.',
        );

        // (3) The delivery's own claim expired at the same moment and was never
        // read again, so its row is still there. This is the part that grows.
        $this->assertDatabaseHas(
            'cache',
            ['key' => $claimKey],
            null,
            'nothing prunes an unread cache row: every delivered mail leaves one behind for good. The window releases '
            .'the GUARD, not the row.',
        );

        // Premises for the two sentences above — see the docblock.
        $artisan = Artisan::all();

        $this->assertArrayHasKey(
            'cache:clear',
            $artisan,
            'PREMISE: the command list must really be readable, or the `cache:prune` check below is vacuous.',
        );
        $this->assertArrayNotHasKey(
            'cache:prune',
            $artisan,
            'PREMISE: Laravel 13.33.0 has no cache:prune command, so nothing in this framework can reap expired cache rows.',
        );

        $scheduled = collect(app(Schedule::class)->events())
            ->map(static fn ($event): string => (string) ($event->command ?? ''))
            ->implode(' ');

        $this->assertNotSame('', $scheduled, 'PREMISE: the schedule must not be empty, or the check below is vacuous.');
        $this->assertStringNotContainsString(
            'cache',
            $scheduled,
            'No scheduled task may delete cache rows: the JWT blacklist lives in that table, and a deletion that is '
            .'not scoped to expired entries would resurrect every invalidated token.',
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

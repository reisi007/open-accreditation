<?php

namespace Tests\Feature;

use const T_COMMENT;
use const T_DOC_COMMENT;

use App\Jobs\SendMandantMail;
use App\Models\CacheRow;
use App\Models\Mandant;
use App\Models\User;
use App\Services\MandantMailerService;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InteractsWithJwtRevocation;
use Tests\Support\PlainTestMailable;
use Tests\TestCase;
use Throwable;

/**
 * The `cache`-table reaper (`cache:prune-expired`, Nutzerentscheid
 * 2026-10-04) — what it deletes, and above all what it must never delete.
 *
 * ## Why this file is mostly about one predicate
 *
 * The `cache` table is not a scratch space. It carries two writers whose rows
 * nobody reads again:
 *
 * - the **idempotency claim** of `App\Jobs\SendMandantMail` — one row per
 *   delivered mail (`Cache::add(…, self::CLAIM_TTL_SECONDS)`), and a delivered
 *   mail's `deliveryId` is never claimed a second time, so nothing ever reads the
 *   key that would make `DatabaseStore::many()` drop the row;
 * - the **JWT blacklist** — one row per invalidated `jti`, and a logged-out token
 *   is not replayed, so the same is true there.
 *
 * The second writer turns a bug in this feature into a security incident, not
 * into a slow query: **deleting a blacklist row that has not expired
 * re-accepts a logged-out token.** So every test below comes in one of two
 * shapes, and the second shape exists precisely because the first is worthless
 * without it:
 *
 * - expired rows are collected (the feature works), and
 * - unexpired rows SURVIVE, per writer, including one measured through a real
 *   HTTP replay of a revoked token.
 *
 * A reaper test that only shows the first half is compatible with
 * `DELETE FROM cache`, and that statement is the incident.
 *
 * A reaper that only shows the HAPPY path is compatible with a service whose
 * `catch` block does nothing at all — the whole `try`/`catch` in
 * `ExpiredCachePruner::prune()` would then be untested, and a reaper that
 * silently stopped working is invisible from every angle the other tests
 * measure (the log still says what it always said, the exit code stays 0, and
 * the table grows). Section F exists for that, and it is the only part of this
 * file that does not need the table to be healthy.
 *
 * ## The mutations, and what each one turns red
 *
 * The predicate exists exactly once, in `CacheRow::scopeExpiredAt()`
 * (`app/Models/CacheRow.php`). Verified by mutation, not by assertion of intent:
 *
 * | Mutation | Goes red |
 * |---|---|
 * | scope returns `$query` (predicate dropped) | `…_keeps_an_unexpired_claim_row`, `…_keeps_an_unexpired_blacklist_row`, `test_an_unexpired_blacklist_entry_still_revokes_the_token_after_a_reaper_run` |
 * | operator `<=` → `>` (predicate inverted) | all three above **plus** `…_deletes_the_expired_claim_row_and_the_expired_blacklist_row`, `test_it_reports_the_count_and_the_predicate_it_applied`, `test_a_row_whose_expiration_is_exactly_now_is_expired` |
 * | operator `<=` → `<` (off-by-one at the boundary) | `…_a_row_whose_expiration_is_exactly_now_is_expired` |
 * | `cache_locks` added to the delete | `…_leaves_the_cache_locks_table_alone` |
 * | the `instanceof DatabaseStore` gate removed | `…_is_a_no_op_on_a_non_database_store` |
 * | the `catch` rethrow replaced by a swallow — `throw $e;` gone **and** `$deleted = 0;` seeded before the `try` (the coherent recipe; see below) | `test_a_failed_prune_is_logged_with_its_context_and_lets_the_exception_out`, `…_reports_a_non_zero_exit_code` |
 * | the `Log::error` in the `catch` dropped | `test_a_failed_prune_is_logged_with_its_context_and_lets_the_exception_out` |
 * | `->withoutOverlapping()` dropped | `…_is_registered_daily_and_does_not_overlap` |
 * | the registration deleted | `…_is_registered_daily_and_does_not_overlap`, and `SendMandantMailTest`'s cache-task guard |
 *
 * ### The swallow needs a second edit, and this table says so on purpose
 *
 * A "rethrow replaced by a swallow" is not one edit. Measured 2026-10-04 on both
 * engines, class `ExpiredCachePrunerTest`, 13 tests, from `ExpiredCachePruner.php`
 * with `throw $e;` (`:129`) removed:
 *
 * | recipe | what escapes | `Log::info` success line | exit code | Goes red |
 * |---|---|---|---|---|
 * | `throw $e;` deleted **alone** | `ErrorException: Undefined variable $deleted` | **not written** — the warning aborts the call | 1 | **1 of 2**: `test_a_failed_prune_is_logged_with_its_context_and_lets_the_exception_out` |
 * | `throw $e;` deleted **and** `$deleted = 0;` seeded before the `try` | nothing | written, `'deleted' => 0` | 0 | **2 of 2**: that test **and** `…_reports_a_non_zero_exit_code` |
 *
 * So the ledger row for the swallow names the **two-edit** recipe, because that is
 * the only one that turns both halves red — and the naive one-edit recipe is
 * documented next to it precisely because it under-reports, and for a reason that
 * has nothing to do with the exit code:
 *
 * `$deleted` is assigned **only inside** the `try` (`ExpiredCachePruner.php:115`)
 * and first read at the success line (`:137`), so swallowing the exception leaves
 * it undefined. PHP raises `E_WARNING` (`Undefined variable $deleted` — read back
 * through this application's own error handler), and Laravel's
 * `HandleExceptions::handleError()` turns every in-mask error into an
 * `ErrorException`, which escapes `prune()` and reaches `Kernel::handle()` exactly
 * like the `QueryException` would. Exit code 1, so
 * `…_reports_a_non_zero_exit_code` stays **green** — right about the number, for a
 * cause the mutation was not supposed to have. The success line is never reached
 * either (measured), so the log test is the *only* thing that catches the one-edit
 * recipe, and it catches it as `ErrorException` instead of `QueryException`
 * (`assertInstanceOf` at `:681`, fail-closed on the class, not on the count).
 *
 * Two consequences, both permanent: the swallow is pinned by a **two-edit**
 * mutation, and `…_reports_a_non_zero_exit_code` proves the exit-code contract
 * only against a reaper that survives its own failure path. Both facts are what
 * the row above claims; neither was measured by reading the code.
 *
 * The boundary row is the reason the operator is a named constant rather than a
 * habit: `DatabaseStore::many()` keeps a row when `expiration > now` and calls
 * everything else expired (`vendor/…/Cache/DatabaseStore.php:152-154`), so a row
 * whose expiration is exactly the current second is one the framework has
 * already declared unreadable — the reaper has to agree with it.
 *
 * ## Store
 *
 * Every behavioural test runs on the `database` cache store, because
 * `phpunit.xml:115` pins `CACHE_STORE=array` and `array` has neither the table
 * nor the semantics under test. `InteractsWithJwtRevocation::useProductionCacheStore()`
 * switches the store, drops the JWT instances that captured the old one, and
 * asserts the premise — without that assertion the tests below would pass on an
 * array store and prove nothing.
 */
class ExpiredCachePrunerTest extends TestCase
{
    use InteractsWithJwtRevocation;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ A ---
    // The feature: expired rows are collected.

    /**
     * The feature, through the REAL writers — a `SendMandantMail` claim and a
     * JWT-blacklist entry, not rows inserted by the test. A row the test wrote
     * itself could agree with the reaper for reasons that have nothing to do with
     * the application (a hand-inserted row is a hypothesis about the table, not
     * evidence that the writer produces one).
     *
     * The blacklist entry is written by a real `POST /api/auth/logout`, not by an
     * in-process `auth('api')->logout()`. That is not a stylistic choice: the
     * in-process call needs the token in the `JWT` singleton, which
     * `authenticateOverTheCookie()` deliberately clears — and
     * `JWTGuard::logout()` swallows the resulting `JWTException` and reports
     * success anyway (accepted risk A6). A test written that way would measure
     * nothing at all: no row, and a green assertion about a row that never came.
     *
     * The clock is moved past each row's OWN expiration, read back from the
     * table, so the test does not restate the writers' TTLs — a change to
     * `Blacklist::getMinutesUntilExpired()` (~7 days, A6) or to
     * `CLAIM_TTL_SECONDS` cannot turn this test red for the wrong reason.
     *
     * MUTATION: `<=` → `>` turns the two `assertDatabaseMissing` below red,
     * because neither row would match the inverted predicate.
     */
    public function test_it_deletes_the_expired_claim_row_and_the_expired_blacklist_row(): void
    {
        Mail::fake();

        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        // (1) The claim writer, through the job itself.
        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('claim@example.test'));
        $job->handle(app(MandantMailerService::class));

        $claimKey = $this->cacheKey('mail-delivery:'.$job->deliveryId);

        // (2) The blacklist writer, through a real login and a real logout.
        $token = $this->authenticateOverTheCookie(User::factory()->create());
        $logout = $this->callLogoutRoute($token);

        $blacklistKey = $this->blacklistCacheKey($token);

        $this->assertSame(200, $logout, 'PREMISE: the logout route must succeed, or no blacklist entry was written.');

        foreach ([$claimKey, $blacklistKey] as $key) {
            $this->assertNotNull(
                $this->cacheRow($key),
                "PREMISE: the real writers must leave a row for {$key}, or the deletion below proves nothing.",
            );
        }

        // Past BOTH expirations, as read back from the table.
        Carbon::setTestNow(Carbon::createFromTimestamp($this->latestExpiration($claimKey, $blacklistKey) + 1));

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseMissing('cache', ['key' => $claimKey], null,
            'an expired claim row must be collected — this is the row that used to survive for every delivered mail.');
        $this->assertDatabaseMissing('cache', ['key' => $blacklistKey], null,
            'an expired blacklist row must be collected; it is unreadable by the guard either way, so deleting it changes no decision.');
    }

    /**
     * The report is the only durable record of a scheduled run (the child's
     * stdout is discarded, `ScheduledTaskObserver`), and a reaper that deleted
     * nothing must not look like a reaper that never started.
     *
     * The logged `predicate` is asserted verbatim, so the number in the log
     * cannot drift away from the number the delete actually used — the two are
     * read from the same variable in the service, and this is what proves it.
     */
    public function test_it_reports_the_count_and_the_predicate_it_applied(): void
    {
        Log::spy();

        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $moment = Carbon::now()->getTimestamp();

        $this->writeCacheRow('report:expired-a', $moment - 10);
        $this->writeCacheRow('report:expired-b', $moment);
        $this->writeCacheRow('report:alive', $moment + 3600);

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'cache:prune-expired finished'
                && $context['deleted'] === 2
                && $context['table'] === 'cache'
                && $context['predicate'] === 'expiration <= '.$moment)
            ->once();
    }

    /**
     * The boundary second, in both directions — and the reason the operator is
     * `<=`.
     *
     * `DatabaseStore::many()` partitions on `$cache->expiration > $currentTime`
     * and calls everything else expired, so a row at exactly `now` is one the
     * framework has already declared unreadable. Three rows that differ in
     * exactly one second each, so an off-by-one in either direction is visible.
     *
     * MUTATION: `<=` → `<` turns the `exactly-now` assertion red.
     */
    public function test_a_row_whose_expiration_is_exactly_now_is_expired(): void
    {
        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $moment = Carbon::now()->getTimestamp();

        $this->writeCacheRow('boundary:one-second-past', $moment - 1);
        $this->writeCacheRow('boundary:exactly-now', $moment);
        $this->writeCacheRow('boundary:one-second-ahead', $moment + 1);

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseMissing('cache', ['key' => $this->cacheKey('boundary:one-second-past')]);
        $this->assertDatabaseMissing('cache', ['key' => $this->cacheKey('boundary:exactly-now')],
            null,
            'expiration == now is EXPIRED: DatabaseStore::many() keeps only rows with expiration > now, so the reaper '
            .'must delete at the boundary as well, or it leaves behind rows every read already treats as gone.');
        $this->assertDatabaseHas('cache', ['key' => $this->cacheKey('boundary:one-second-ahead')],
            null,
            'expiration == now + 1 is still readable; deleting it would un-revoke a blacklisted token one second early.');
    }

    // ------------------------------------------------------------------ B ---
    // The security property: unexpired rows survive. Without this half, every
    // test above is equally satisfied by `DELETE FROM cache`.

    /**
     * An unexpired CLAIM row must survive — and the deletion must not be a total
     * wipe, measured rather than asserted.
     *
     * MUTATION: dropping the predicate (`scopeExpiredAt()` returns `$query`
     * unchanged) or inverting it (`>`) turns the `assertDatabaseHas` red.
     */
    public function test_it_keeps_an_unexpired_claim_row(): void
    {
        Mail::fake();

        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('alive@example.test'));
        $job->handle(app(MandantMailerService::class));

        $claimKey = $this->cacheKey('mail-delivery:'.$job->deliveryId);
        $expiration = $this->cacheRow($claimKey)->expiration;

        // One second before the claim expires.
        Carbon::setTestNow(Carbon::createFromTimestamp($expiration - 1));

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseHas('cache', ['key' => $claimKey], null,
            'a claim that has not expired must survive: its guard is still live, and deleting the row would let a '
            .'second worker claim the same delivery and send it twice.');
    }

    /**
     * An unexpired BLACKLIST row must survive.
     *
     * Same measurement as the claim, on the writer where a mistake is an
     * incident rather than a duplicate mail.
     *
     * MUTATION: dropping or inverting the predicate turns this red.
     */
    public function test_it_keeps_an_unexpired_blacklist_row(): void
    {
        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $token = $this->authenticateOverTheCookie(User::factory()->create());

        $this->assertSame(200, $this->callLogoutRoute($token), 'PREMISE: the logout must succeed.');

        $blacklistKey = $this->blacklistCacheKey($token);
        $expiration = $this->cacheRow($blacklistKey)->expiration;

        // One second before the blacklist entry expires.
        Carbon::setTestNow(Carbon::createFromTimestamp($expiration - 1));

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseHas('cache', ['key' => $blacklistKey], null,
            'a blacklist entry that has not expired must survive: it is the only thing rejecting the logged-out token.');
    }

    /**
     * THE fail-open test: the reaper runs, the blacklist entry has not expired,
     * and the revoked token is still rejected — measured over the real cookie
     * channel, not through the cache API.
     *
     * `blacklistHolds()` would answer the same question, but it asks the
     * package whether the entry EXISTS; the row could be present and still not
     * gate anything. The HTTP replay is the decision the user actually
     * experiences, and it can only come from a token that was validated on the
     * wire (`Tests\TestCase::withJwtCookie()` is the one channel that carries it
     * — see `InteractsWithJwtRevocation`'s docblock for the two traps).
     *
     * The order matters and is the point: the reaper runs BETWEEN the logout and
     * the replay, so a reaper that un-revokes shows up here and nowhere else.
     *
     * MUTATION: dropping or inverting the predicate turns the final 401 red.
     */
    public function test_an_unexpired_blacklist_entry_still_revokes_the_token_after_a_reaper_run(): void
    {
        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $token = $this->authenticateOverTheCookie(User::factory()->create(['email' => 'revoked@example.test']));

        // Gegenprobe: the token must be good first, or a 401 afterwards is
        // equally what a token that never worked would produce.
        $this->assertSame(200, $this->callProtectedRoute($token),
            'PREMISE: the token must be accepted before the logout, otherwise the 401 below proves nothing.');

        $this->assertSame(200, $this->callLogoutRoute($token), 'PREMISE: the logout must succeed.');

        $blacklistKey = $this->blacklistCacheKey($token);

        $this->assertTrue($this->blacklistHolds($token),
            'PREMISE: the logout must really have blacklisted the token, or the replay below proves nothing.');
        $this->assertSame(
            401,
            $this->callProtectedRoute($token),
            'PREMISE: the revoked token must already be rejected before the reaper runs.',
        );

        // The reaper runs while the token is still inside its own TTL and the
        // blacklist entry is still unexpired (~7 days, A6).
        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseHas('cache', ['key' => $blacklistKey], null,
            'the blacklist entry must still be there after a reaper run — this is the row an unscoped delete takes.');

        $this->assertSame(
            401,
            $this->callProtectedRoute($token),
            'A logged-out token must stay rejected after a reaper run. This is the whole reason the predicate is '
            .'`expiration <= now` and not "delete the cache table".',
        );
    }

    // ------------------------------------------------------------------ C ---
    // The store gate and the tables that must NOT be touched.

    /**
     * A non-`database` store has no table to prune, and guessing one would mean
     * running a `DELETE` against whatever the connection happens to name. The run
     * must be a REPORTED no-op: exit 0, the row untouched, and the skip in the
     * log — because a scheduled command's stdout is discarded, so a silent skip
     * would be indistinguishable from a reaper that never started.
     *
     * The expired row is seeded with a query builder on purpose: the `array`
     * store never writes there, so only a direct insert can put a row in a table
     * the reaper has no business touching.
     *
     * MUTATION: remove the `instanceof DatabaseStore` gate → the delete runs and
     * the `assertDatabaseHas` fails.
     */
    public function test_it_is_a_no_op_on_a_non_database_store(): void
    {
        Log::spy();

        // `phpunit.xml` already pins `array`; assert the premise rather than
        // assuming it, because the whole test is about the gate.
        $this->assertSame('array', config('cache.default'),
            'PREMISE: this test must run on a store without a cache table.');

        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $this->writeCacheRow('non-database:expired', Carbon::now()->getTimestamp() - 10);

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseHas('cache', ['key' => $this->cacheKey('non-database:expired')], null,
            'On a store that has no cache table the reaper must delete nothing at all — it must not fall back to a '
            .'guessed table name.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'cache:prune-expired skipped'
                && $context['store'] === 'array')
            ->once();
    }

    /**
     * `cache_locks` is a different table and is NOT part of this reaper.
     *
     * Two reasons, one of them about this very task: an expired mutex row may
     * belong to a task that is running right now (a reaper that deleted its own
     * `withoutOverlapping()` lock would let a second copy start), and Laravel
     * already takes over expired locks itself — `DatabaseLock::acquire()` matches
     * on `expiration <= now`
     * (`vendor/…/Cache/DatabaseLock.php:70-98`). So there is nothing here to
     * gain and a running task to lose.
     *
     * MUTATION: point the delete at `cache_locks` as well → red.
     */
    public function test_it_leaves_the_cache_locks_table_alone(): void
    {
        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        DB::table('cache_locks')->insert([
            'key' => 'expired-mutex',
            'owner' => 'gone',
            'expiration' => Carbon::now()->getTimestamp() - 10,
        ]);

        $this->assertSame(0, Artisan::call('cache:prune-expired'));

        $this->assertDatabaseHas('cache_locks', ['key' => 'expired-mutex'], null,
            "This reaper prunes the cache VALUE table only. cache_locks is Laravel's own business — DatabaseLock "
            .'reclaims expired locks itself, and a reaper that deleted its own withoutOverlapping() mutex would let a '
            .'second copy of this very task run.');
    }

    // ------------------------------------------------------------------ D ---
    // The wiring: the schedule entry is what makes the feature run at all.

    /**
     * The reaper is scheduled, daily, and `withoutOverlapping()` — the same three
     * properties the two existing tasks are registered with.
     *
     * `withoutOverlapping()` is checked through the event's `withoutOverlapping`
     * FLAG and not through the mutex object, which was the first attempt and was
     * wrong: `Schedule::exec()` hands EVERY command event a `CacheEventMutex`
     * (`Schedule::$eventMutex`), so an event registered without the call carries a
     * mutex too. `Event::run()` consults the flag and nothing else
     * (`vendor/…/Console/Scheduling/Event.php:161`). The control at the end is
     * what gives the assertion its meaning.
     *
     * MUTATION: delete the registration from `routes/console.php` → red (no
     * event at all). Drop only `->withoutOverlapping()` → red.
     */
    public function test_the_reaper_is_registered_daily_and_does_not_overlap(): void
    {
        $event = $this->scheduledEvent('cache:prune-expired');

        $this->assertInstanceOf(Event::class, $event,
            'the reaper disappeared from routes/console.php — without the registration the table grows again');

        $this->assertSame('0 0 * * *', $event->expression,
            'the reaper must run daily, like the two existing tasks.');

        $this->assertTrue($event->withoutOverlapping,
            'the daily reaper must not overlap itself — without the call, two runs delete in parallel and the second '
            .'reports rows the first already took.');

        $withoutOverlap = $this->app->make(Schedule::class)->command('probe:no-overlap')->daily();

        $this->assertFalse($withoutOverlap->withoutOverlapping,
            'PREMISE: an event registered without withoutOverlapping() has the flag unset, so the assertion above has teeth.');
        $this->assertInstanceOf(CacheEventMutex::class, $withoutOverlap->mutex,
            'PREMISE: every scheduled command event carries a mutex anyway — which is exactly why the mutex object '
            .'cannot be the thing this test looks at.');
    }

    /**
     * Nothing in the application may wipe the cache table wholesale — not the
     * reaper, not a new command, not a maintenance script.
     *
     * This is the one requirement that cannot be pinned by a behavioural test
     * written against today's code: `Cache::flush()` has no test-visible
     * difference from a scoped delete unless a test happens to call it. So the
     * guard is a source scan.
     *
     * **The scan strips comments and docblocks first** (via `token_get_all`),
     * because a plain `str_contains` over the raw source produces two false
     * positives in this repository today and would have produced a third in this
     * very file: `App\Services\AccountDeletionService` and
     * `App\Services\ExpiredCachePruner` both *discuss* `cache:clear` in prose
     * while being the two places that must never invoke it. A guard that had to
     * be switched off by its own documentation is not a guard.
     *
     * Bounded on purpose: it pins the three spellings the requirement names plus
     * the chained-store form, not every conceivable wipe.
     *
     * MUTATION: add `Cache::flush();` anywhere under `app/` or `routes/` → red.
     */
    public function test_no_application_code_flushes_the_cache_store(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('routes')] as $directory) {
            foreach ($this->phpFilesIn($directory) as $file) {
                $code = $this->executableCode((string) file_get_contents($file));

                foreach (['Cache::flush', 'cache:flush', 'cache:clear'] as $needle) {
                    if (str_contains($code, $needle)) {
                        $offenders[] = $this->relative($file).': '.$needle;
                    }
                }

                if (preg_match('/Cache::\w+\([^;]*?\)\s*->\s*flush\s*\(/', $code) === 1) {
                    $offenders[] = $this->relative($file).': Cache::store()->flush()';
                }
            }
        }

        $this->assertSame([], $offenders,
            'A total wipe of the cache table invalidates every blacklisted token and releases every mail claim at once '
            .'— the same catastrophe as an unscoped delete, reached by a shorter route. Found: '
            .(implode(', ', $offenders) ?: '-'));
    }

    // ------------------------------------------------------------------ E ---
    // Where the predicate's inputs come from.

    /**
     * The reaper's DELETE reaches its table only through `CacheRow`, so the
     * indirection is load-bearing and gets its own test — a hard-coded `cache`
     * would be wrong the moment `DB_CACHE_TABLE` is set, and a connection name of
     * `''` would make `getConnection()` look for a connection that does not
     * exist.
     *
     * `config/cache.php:44` reads `DB_CACHE_CONNECTION`, and no shipped env file
     * sets it (measured: zero hits in `deployment/dev.env` and
     * `backend/.env.example`), so "unset" is the normal production case — and it
     * must stay `null` rather than becoming `''`, because only `null` makes
     * Eloquent fall through to the default connection.
     */
    public function test_the_cache_row_model_follows_the_cache_configuration(): void
    {
        $this->assertSame('cache', (new CacheRow)->getTable(),
            'PREMISE: the store writes to the default table name, or the table assertion below proves nothing.');

        config(['cache.stores.database.connection' => '']);
        $this->assertNull((new CacheRow)->getConnectionName(),
            'an empty DB_CACHE_CONNECTION must resolve to null, not to the empty string — only null falls through to '
            .'the default connection.');

        config(['cache.stores.database.connection' => 'probe_connection']);
        $this->assertSame('probe_connection', (new CacheRow)->getConnectionName(),
            'an explicit DB_CACHE_CONNECTION must be honoured, or the reaper would query a different database than the store writes to.');

        config(['cache.stores.database.table' => 'probe_cache_table']);
        $this->assertSame('probe_cache_table', (new CacheRow)->getTable(),
            'an explicit DB_CACHE_TABLE must be honoured, or the reaper would delete from a table nobody writes.');
    }

    // ------------------------------------------------------------------ F ---
    // The failure branch. Everything above measures what the reaper DOES; this
    // section measures what happens when it CANNOT.

    /**
     * A delete that fails must produce BOTH halves of the contract — the log
     * line and the escaping exception — because either half alone is a silent
     * failure in a different way.
     *
     * **Why the log alone is not enough:** the reaper runs as a scheduled
     * command in its own process and `schedule:run` exits 0 even when a task
     * fails (`ScheduleRunCommand::runEvent()` throws, `handle()` catches +
     * reports), so a swallowed exception yields a task that has been silently
     * dead for weeks while the heartbeat says "finished" — measured in
     * `ScheduledTaskObservabilityTest`. That test pins the same two halves for
     * `RunAllocations`; it does not reach this service.
     *
     * **Why the throw alone is not enough:** the command's own stdout is
     * discarded (`Event::execute()` hands `Process::run()` a `fn () => true`
     * output handler), so a reaper that rethrows and says nothing leaves no
     * durable record of *what* failed, and the next run's log looks like the
     * first one's.
     *
     * The breakage is a **missing table** — `cache.stores.database.table` is the
     * very key `CacheRow::getTable()` reads (`config/cache.php:45`), so setting
     * it to a name that does not exist reproduces the production misconfiguration
     * (`DB_CACHE_TABLE=no_such_table`) instead of simulating one. The reaper
     * still passes the store gate: `Cache::store()` is still a `DatabaseStore`,
     * only its table is wrong.
     *
     * Only the **exception class** is asserted, never the message: SQLite says
     * `no such table: …`, PostgreSQL 17 says `relation "…" does not exist`. The
     * message is asserted to be a non-empty string in the log and no further —
     * that is the portable half, and the engine-specific half is not this
     * suite's business.
     *
     * MUTATION: replace `throw $e;` (`ExpiredCachePruner.php:129`) with a
     * swallow → the `assertInstanceOf` fails, and **which** class it escapes as
     * depends on the recipe: the coherent one (`$deleted = 0;` seeded before the
     * `try`) escapes **nothing** → `null`; deleting `throw $e;` on its own leaves
     * `$deleted` undefined at `:137`, so an `ErrorException` escapes instead —
     * red either way, and `assertInstanceOf` is what makes the second case
     * fail-closed rather than counting an unrelated exception as the reaper's
     * failure (see the class docblock). Drop the `Log::error` → the spy fails.
     * Write the success line inside the `catch` → the `shouldNotHaveReceived`
     * fails.
     */
    public function test_a_failed_prune_is_logged_with_its_context_and_lets_the_exception_out(): void
    {
        Log::spy();

        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $moment = Carbon::now()->getTimestamp();

        // Point the store itself at a table that does not exist, BEFORE the store
        // is resolved — so the DatabaseStore and the model agree on the broken
        // name, which is the shape a wrong `DB_CACHE_TABLE` has in production.
        config(['cache.stores.database.table' => 'no_such_cache_table']);

        $this->useProductionCacheStore();

        $this->assertSame('no_such_cache_table', (new CacheRow)->getTable(),
            'PREMISE: the reaper must be pointed at the missing table, or the delete below runs against `cache` and succeeds.');
        $this->assertInstanceOf(DatabaseStore::class, app('cache')->store()->getStore(),
            'PREMISE: the store gate must still pass — a non-DatabaseStore would be a reported no-op, and the reaper would '
            .'never reach its delete at all.');

        $escaped = null;

        try {
            Artisan::call('cache:prune-expired');
        } catch (Throwable $e) {
            $escaped = $e;
        }

        // An exception that ESCAPES is what makes the CLI exit non-zero:
        // `Illuminate\Foundation\Console\Kernel::handle()` catches, renders and
        // `return 1` (`vendor/…/Console/Kernel.php:199-205`), and that 1 is the
        // child's exit code. Measured as an exit code in the test below.
        $this->assertInstanceOf(
            QueryException::class,
            $escaped,
            'a prune whose DELETE failed must let the exception out — swallowed here, the command would return SUCCESS, '
            .'the scheduler would log a heartbeat, and a dead reaper would be indistinguishable from one with nothing to do.',
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'cache:prune-expired failed'
                && $context['store'] === 'database'
                && $context['table'] === 'no_such_cache_table'
                && $context['moment'] === $moment
                && $context['exception'] === QueryException::class
                && is_string($context['error'])
                && $context['error'] !== ''
                && array_key_exists('duration_ms', $context))
            ->once();

        // No `info` line at all, not merely none mentioning the success message:
        // on this path the reaper is the only writer in the test, so a single
        // `info` call would BE the success line (or an unrelated one, which would
        // be its own finding). This is what a swallow looks like from the log
        // alone — a run that deleted nothing and reported a count anyway.
        Log::shouldNotHaveReceived('info');
    }

    /**
     * The same failure, measured as the thing an operator or a health check
     * actually acts on: the process exit code.
     *
     * `Artisan::call()` cannot answer that question — `Illuminate\Console\Application`
     * sets `setCatchExceptions(false)` (`vendor/…/Console/Application.php:76`),
     * so a failing command *throws* out of `call()` instead of returning a
     * status. The status is produced one layer up, by
     * `Illuminate\Foundation\Console\Kernel::handle()`, which is precisely what
     * the `artisan` binary calls (`artisan:16` → `Application::handleCommand()`
     * → `$kernel->handle(...)` → `exit($status)` at `artisan:18`). Driving that
     * method is therefore the production path, not a re-implementation of it —
     * and it is the only place the number `1` is ever produced
     * (`vendor/…/Console/Kernel.php:204`).
     *
     * **The control run is what gives this teeth.** Without it, `assertNotSame(0, …)`
     * would be satisfied by a harness that returns 7 for everything, and a
     * command that had never run at all. So the healthy reaper runs first through
     * the identical path and must report `Command::SUCCESS` — proving the path,
     * the input and the store are all fine, and that the non-zero below is caused
     * by the broken table alone.
     *
     * **This test does not go red for the one-edit swallow, and that is the
     * measured reason the recipe above needs two edits.** MUTATION: swallow the
     * exception (`ExpiredCachePruner.php:129`) **and** seed `$deleted = 0;`
     * before the `try` → nothing escapes, the command returns `Command::SUCCESS`
     * and the second call reports 0 like the first → red. Delete `throw $e;`
     * *alone* and this test stays **green**: `$deleted` is undefined at the
     * success line, PHP raises `E_WARNING`, Laravel's error handler turns it into
     * an `ErrorException`, and that still reaches `Kernel::handle()` — so the
     * number under assertion is still non-zero, for a cause that has nothing to do
     * with the swallow. (SQLite and PostgreSQL 17, measured 2026-10-04: 1 of 2
     * red for the one-edit recipe, 2 of 2 for the two-edit one; identical on both
     * engines.) So what this test actually pins is narrower than it looks: the
     * exit-code contract holds against a reaper that **survives its own failure
     * path** — which is exactly the shape of the real defect, and not the shape of
     * the first mutation anyone writes.
     */
    public function test_a_failed_prune_reports_a_non_zero_exit_code(): void
    {
        Log::spy();

        $this->useProductionCacheStore();
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00'));

        $this->assertSame(Command::SUCCESS, $this->exitCodeOf('cache:prune-expired'),
            'PREMISE: a healthy reaper must exit 0 through this very path, or a non-zero below would prove nothing.');

        // Same misconfiguration as the test above, applied after the control run.
        config(['cache.stores.database.table' => 'no_such_cache_table']);

        $this->assertNotSame(
            Command::SUCCESS,
            $this->exitCodeOf('cache:prune-expired'),
            'A reaper that could not delete anything must not exit 0 — the supervisor and the scheduler read the exit '
            .'code, and 0 is how a dead reaper reports success.',
        );
    }

    // ------------------------------------------------------------------ G ---
    // Helpers.

    /**
     * Run a command through the console kernel's `handle()` and return the exit
     * code the `artisan` binary would exit with.
     *
     * `Kernel::handle()` is the method `artisan` calls — `artisan` is 18 lines, and
     * the chain is `$app->handleCommand(new ArgvInput)` at `artisan:16` →
     * `Application::handleCommand()` → `$kernel->handle(...)` →
     * `exit($status)` at `artisan:18` — so this is the production path rather than
     * a re-implementation of it. (An earlier version of this docblock cited
     * `artisan:26`, a line the 18-line file does not have; the other docblock in
     * this class cited `:16`/`:18` correctly all along.) The exception report and
     * the rendered stack trace go to a `BufferedOutput` and into the `Log` spy —
     * otherwise every run of this test would write a rendered `QueryException`
     * into the developer's `storage/logs/laravel.log`.
     */
    private function exitCodeOf(string $command): int
    {
        return $this->app->make(Kernel::class)->handle(
            new ArgvInput(['artisan', $command]),
            new BufferedOutput,
        );
    }

    /**
     * The real logout route, with the token on the wire.
     *
     * The in-process `auth('api')->logout()` is NOT usable here: it needs the
     * token in the `JWT` singleton, which `authenticateOverTheCookie()` clears on
     * purpose, and `JWTGuard::logout()` swallows the resulting `JWTException` and
     * reports success without writing anything (accepted risk A6). Over HTTP the
     * cookie carries the token, the entry is written, and the 200 means it.
     */
    private function callLogoutRoute(string $token): int
    {
        $this->forgetInMemoryToken();
        $this->forgetGuard();

        $this->assertFalse(
            $this->inMemoryTokenIsSet(),
            'The JWT singleton must be empty when the request is made, or the logout could be answered out of memory.',
        );

        return $this->withJwtCookie($token)->postJson('/api/auth/logout')->status();
    }

    private function scheduledEvent(string $command): ?Event
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The `cache` table stores keys WITH the store's prefix, so every key in
     * these tests is built through the same helper the store writes with — a test
     * that hand-wrote `mail-delivery:…` would silently look at a key nobody ever
     * used.
     */
    private function cacheKey(string $unprefixed): string
    {
        return app('cache')->store()->getPrefix().$unprefixed;
    }

    /**
     * Where `Providers\Storage\Illuminate::add()` puts a blacklist entry: the
     * bare `jti`, under the cache prefix, because `DatabaseStore` is not a
     * `TaggableStore` and the storage falls back to an UNTAGGED repository
     * (`JwtBlacklistCacheFlushTest::cacheEntryFor()` measures the same thing).
     */
    private function blacklistCacheKey(string $token): string
    {
        return $this->cacheKey((string) $this->payloadOf($token)->get('jti'));
    }

    private function cacheRow(string $key): ?object
    {
        return DB::table((new CacheRow)->getTable())->where('key', $key)->first();
    }

    /**
     * The latest of several rows' expirations, read back from the table — the
     * test moves the clock from the writers' actual output instead of restating
     * their TTLs.
     */
    private function latestExpiration(string ...$keys): int
    {
        return max(array_map(fn (string $key): int => (int) $this->cacheRow($key)->expiration, $keys));
    }

    /**
     * A cache row written directly, for the rows that are deliberately NOT
     * produced by an application writer (boundary values, non-`database` store).
     */
    private function writeCacheRow(string $unprefixed, int $expiration): void
    {
        DB::table((new CacheRow)->getTable())->insert([
            'key' => $this->cacheKey($unprefixed),
            'value' => serialize(true),
            'expiration' => $expiration,
        ]);
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The source with every comment and docblock removed, so the scan above
     * measures CODE and not prose. Both existing hits are documentation:
     * `AccountDeletionService` explains *why* it avoids the JWT blacklist by
     * naming `cache:clear`, and this reaper's own docblock names `Cache::flush()`
     * while being forbidden from calling it.
     */
    private function executableCode(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}

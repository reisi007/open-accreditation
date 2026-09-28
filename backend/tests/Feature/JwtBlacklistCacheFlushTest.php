<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithJwtRevocation;
use Tests\Support\JwtExceptionFailingJwtStorage;
use Tests\Support\QueryFailingJwtStorage;
use Tests\TestCase;

/**
 * WHERE THE JWT BLACKLIST LIVES, AND WHAT CAN UNDO IT.
 *
 * The blacklist is not a table. `Providers\Storage\Illuminate::__construct(
 * CacheContract $cache)` puts it in the **cache**, and because
 * `DatabaseStore` is not a `TaggableStore` the storage provider's
 * `determineTagSupport()` falls through to the UNTAGGED repository — measured
 * key: `open-accriditation-cache-<jti>`, no tag namespace, and no isolation
 * from anything else in the cache. One `cache:clear` therefore empties the
 * blacklist, and empties everything else with it.
 *
 * ## The question this file answers, measured
 *
 * *Does a deploy resurrect already-invalidated tokens?*
 *
 * **No — today.** The deploy step runs exactly four artisan commands
 * (`deployment/entrypoint.sh`, `run_deploy_step()`):
 * `migrate --force` → `storage:link` → `db:seed --force` → `config:cache` /
 * `config:clear`. None of them touches the cache, and in the documented prod
 * path (`CACHE_STORE=database`, `.env.example:126`) the blacklist sits in the
 * `cache` table inside the `db_data` volume, which a container rebuild does not
 * touch. `test_a_deploy_never_flushes_the_cache` pins that by reading the
 * deploy scripts, so the next person to add a `cache:clear` finds out here.
 *
 * The exposure is therefore **conditional**, and `test_a_cache_flush_revives_a_logged_out_token`
 * measures exactly how wide it is: a flush resurrects the token for the
 * remainder of its own lifetime — at most `JWT_TTL` = 60 minutes from issuance,
 * never longer, because `exp` still applies once the blacklist is gone.
 *
 * ## And the account deletion is not exposed at all
 *
 * This is the load-bearing distinction for the deletion feature, and it has
 * its own file: `AccountDeletionRevokesAccessImmediatelyTest` shows the
 * revocation rests on the DB lookup in `JWTGuard::user():107`, writes nothing
 * to the cache, and survives a full flush. The two files together are what
 * keeps "the account is gone" from quietly becoming "the account is gone as
 * long as a cache entry lives".
 *
 * @see AccountDeletionRevokesAccessImmediatelyTest
 */
class JwtBlacklistCacheFlushTest extends TestCase
{
    use InteractsWithJwtRevocation;
    use RefreshDatabase;

    /**
     * Artisan commands that remove LIVE cache entries. Each of them would
     * resurrect every invalidated token.
     *
     * `cache:prune` is deliberately absent: it only removes entries that have
     * already expired, and an entry that expired can no longer blacklist
     * anything. `config:clear`, `route:clear`, `view:clear` and `event:clear`
     * are absent for the same reason — they clear compiled artefacts, not the
     * cache. `optimize:clear` includes `cache:clear`, hence its presence.
     *
     * @var list<string>
     */
    private const CACHE_FLUSHING_COMMANDS = [
        'cache:clear',
        'cache:forget',
        'optimize:clear',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------- the deploy itself ---

    /**
     * The deploy must not flush the cache — this is the whole reason logout
     * revocation survives a release.
     *
     * Asserted against the deploy files rather than against a description of
     * them, and with a non-vacuity guard: a scanner that matched nothing would
     * otherwise pass this test happily. The two commands it requires to be
     * present are the ones the deploy is built around, so if the scanner ever
     * stops finding them the test goes red instead of green.
     */
    public function test_a_deploy_never_flushes_the_cache(): void
    {
        $commands = $this->artisanCommandsInDeployScripts();

        $this->assertContains(
            'migrate',
            $commands,
            'PREMISE: the scanner must actually see the deploy commands, or this test is vacuous.'
        );
        $this->assertTrue(
            in_array('config:cache', $commands, true) || in_array('config:clear', $commands, true),
            'PREMISE: the deploy config step must be visible to the scanner, or this test is vacuous.'
        );

        $flushing = array_values(array_intersect(self::CACHE_FLUSHING_COMMANDS, $commands));

        $this->assertSame(
            [],
            $flushing,
            'The deploy must not run a cache-flushing command: the JWT blacklist lives in the cache, '
            .'and a flush would resurrect every token invalidated so far. Found: '.implode(', ', $flushing)
        );
    }

    // ---------------------------------------------------- the exposure window ---

    /**
     * If the cache IS flushed, an invalidated token comes back — for the
     * remainder of its own lifetime, and no longer.
     *
     * The three requests below differ in exactly one respect: whether the
     * blacklist entry is still there. That is what attributes the 401 to the
     * blacklist and the 200 to the flush, rather than to some difference in
     * how the request was made.
     */
    public function test_a_cache_flush_revives_a_logged_out_token_for_the_rest_of_its_lifetime(): void
    {
        $this->useProductionCacheStore();

        $user = User::factory()->create(['email' => 'flushed@example.com']);
        $token = $this->authenticateOverTheCookie($user);
        $issuedAt = $this->issuedAtOf($token);
        $expiresAt = $this->expiryOf($token);

        $this->assertSame(200, $this->callProtectedRoute($token), 'PREMISE: the token works before logout.');

        $logout = $this->callLogoutRoute();
        $this->assertSame(200, $logout, 'logout must answer 200.');

        $this->assertTrue(
            $this->blacklistHolds($token),
            'PREMISE: logout really did write a blacklist entry.'
        );
        $this->assertSame(
            401,
            $this->callProtectedRoute($token),
            'PREMISE: with the entry in place, the token is rejected.'
        );

        // The event under test.
        Cache::flush();

        $this->assertFalse(
            $this->blacklistHolds($token),
            'PREMISE: the flush really emptied the blacklist.'
        );

        $this->assertSame(
            200,
            $this->callProtectedRoute($token),
            'A flushed cache resurrects an invalidated token — this is the measured exposure.'
        );

        // The window: one minute before `exp` it is still usable …
        Carbon::setTestNow(Carbon::createFromTimestampUTC($expiresAt)->subMinute());
        $this->assertSame(
            200,
            $this->callProtectedRoute($token),
            'PREMISE: the token is still within its own lifetime here.'
        );

        // … and one minute past `exp` it is not. The bound is the token's own
        // expiry, not the blacklist entry, which is why the exposure is at
        // most JWT_TTL minutes measured from ISSUANCE.
        Carbon::setTestNow(Carbon::createFromTimestampUTC($expiresAt)->addMinute());
        $this->assertSame(
            401,
            $this->callProtectedRoute($token),
            'The exposure must end with the token\'s natural expiry.'
        );

        $this->assertLessThanOrEqual(
            (int) config('jwt.ttl') * 60,
            $expiresAt - $issuedAt,
            'The whole exposure window is bounded by JWT_TTL, measured from issuance.'
        );
    }

    /**
     * A blacklist entry must never outlive… nothing. It must never expire
     * BEFORE the token it revokes does — otherwise the token would become
     * usable again on its own, with no flush involved.
     *
     * This also records what the entry's lifetime actually **is**, because the
     * arithmetic is easy to get backwards: `Blacklist::getMinutesUntilExpired()`
     * takes `$exp->max($iat->addMinutes($this->refreshTTL))` — the **later** of
     * the two, not the earlier. With `JWT_TTL=60` and `JWT_REFRESH_TTL=10080`
     * (both in `.env.example`) a blacklisted token's entry lives ~10081
     * minutes — about seven days — while the token it revokes is dead after 60.
     * The entry is therefore storage, not a policy: nothing is decided by it
     * expiring on time.
     */
    public function test_a_blacklist_entry_never_expires_before_the_token_it_revokes(): void
    {
        $this->useProductionCacheStore();

        $user = User::factory()->create(['email' => 'entry@example.com']);
        $token = $this->authenticateOverTheCookie($user);
        $issuedAt = $this->issuedAtOf($token);
        $expiresAt = $this->expiryOf($token);

        $this->assertFalse(
            Schema::hasTable('jwt'),
            'PREMISE: the blacklist has no table of its own. It lives in the cache — which is exactly '
            .'why a flush can undo it, and why this is a bounded exposure rather than a durable record.'
        );

        $this->assertSame(200, $this->callProtectedRoute($token), 'PREMISE: the token works before logout.');
        $this->assertSame(200, $this->callLogoutRoute(), 'PREMISE: logout succeeds.');

        $entry = $this->cacheEntryFor($token);

        $this->assertNotNull($entry, 'PREMISE: logout wrote a cache entry for the jti.');
        $entryLifetime = $this->secondsUntil((int) $entry->expiration);
        $tokenLifetime = $expiresAt - $issuedAt;

        $this->assertGreaterThanOrEqual(
            $tokenLifetime,
            $entryLifetime,
            'A blacklist entry must not expire before the token it revokes — otherwise that token returns to service on its own, with no flush involved.'
        );
        $this->assertGreaterThan(
            (int) config('jwt.ttl'),
            (int) round($entryLifetime / 60),
            'PROBE: the entry lives far longer than the token. Blacklist::getMinutesUntilExpired() takes '
            .'max(exp, iat + refresh_ttl) — the LATER of the two, not the earlier — so the entry is '
            .'storage, not a policy.'
        );
    }

    // ------------------------------------------------- the silent failure (A6) ---

    /**
     * Accepted risk **A6**: `JWTGuard::logout()` catches `JWTException` and
     * reports success anyway.
     *
     * The sharpest form of the claim is not "logout says 200" but the pair:
     * the route says it revoked the token, AND the store is provably empty.
     * That pair is what makes the 200 a lie rather than a partial success, and
     * it is asserted here so an upstream fix fails loudly instead of silently
     * invalidating the risk register.
     *
     * The replay is made over the cookie channel with the in-memory token
     * cleared, so a 200 here means the token genuinely still works — not that
     * the harness lost track of it.
     */
    public function test_logout_claims_success_while_the_blacklist_stays_empty(): void
    {
        $this->swapBlacklistStorage(JwtExceptionFailingJwtStorage::class);

        $user = User::factory()->create(['email' => 'silent@example.com']);
        $token = $this->authenticateOverTheCookie($user);

        $this->assertSame(200, $this->callProtectedRoute($token), 'PREMISE: the token works before logout.');

        $logout = $this->callLogoutRoute();

        $this->assertSame(200, $logout, 'A6: the swallowed failure still answers 200.');
        $this->assertFalse(
            $this->blacklistHolds($token),
            'A6: …while nothing at all was blacklisted.'
        );
        $this->assertSame(
            200,
            $this->callProtectedRoute($token),
            'A6: …and the token is still accepted. The response said "revoked" and the token was not revoked.'
        );
    }

    /**
     * The contrast that makes the test above a *defect* report rather than a
     * curiosity: a blacklist write that fails the way a missing table would is
     * **loud**. Only the `JWTException` is swallowed.
     *
     * Both failures leave the token usable — the difference is solely whether
     * the user and the logs are told.
     */
    public function test_a_blacklist_write_that_fails_loudly_is_reported_loudly(): void
    {
        $this->swapBlacklistStorage(QueryFailingJwtStorage::class);

        $user = User::factory()->create(['email' => 'loud@example.com']);
        $token = $this->authenticateOverTheCookie($user);

        $this->assertSame(200, $this->callProtectedRoute($token), 'PREMISE: the token works before logout.');

        $this->assertSame(
            500,
            $this->callLogoutRoute(),
            'A QueryException is not swallowed: the route must fail visibly instead of claiming success.'
        );
        $this->assertFalse(
            $this->blacklistHolds($token),
            'Nothing was blacklisted — the same end state as A6, but reported honestly.'
        );
    }

    // ------------------------------------------------------------- helpers ---

    /**
     * Logout, over the cookie channel, with the in-memory token cleared so
     * the request is validated rather than recalled.
     */
    private function callLogoutRoute(): int
    {
        $this->forgetInMemoryToken();
        $this->forgetGuard();

        $this->assertFalse(
            $this->inMemoryTokenIsSet(),
            'The JWT singleton must be empty when the request is made.'
        );

        return $this->postJson('/api/auth/logout')->status();
    }

    /**
     * Every `php artisan <command>` the deploy scripts run.
     *
     * Scans the whole `deployment/` directory rather than one file, so a
     * helper script added later is covered without touching this test. A file
     * that cannot be read is a failure, not a silently skipped input.
     *
     * @return list<string>
     */
    private function artisanCommandsInDeployScripts(): array
    {
        $directory = dirname(base_path()).DIRECTORY_SEPARATOR.'deployment';

        $this->assertDirectoryExists(
            $directory,
            'The deployment directory must be readable — a scan that finds nothing would pass this test vacuously.'
        );

        $files = glob($directory.DIRECTORY_SEPARATOR.'{*,**/*}.{sh,yml,yaml}', GLOB_BRACE);

        $this->assertNotEmpty($files, 'PREMISE: the deploy scripts must be found.');

        $commands = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertIsString($contents, "Deploy script {$file} must be readable.");

            // The command is the token after `php artisan`; a comment that
            // merely mentions one is stripped first, because a commented-out
            // `cache:clear` is not something the deploy runs.
            $code = implode("\n", array_filter(
                explode("\n", $contents),
                static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')
            ));

            if (preg_match_all('/php\s+artisan\s+([a-z][a-z0-9:\-]*)/i', $code, $matches) > 0) {
                foreach ($matches[1] as $command) {
                    $commands[] = strtolower($command);
                }
            }
        }

        return array_values(array_unique($commands));
    }

    /**
     * The cache row the blacklist wrote for this token, or null.
     *
     * Read from the table rather than through the cache facade, because the
     * question is about what is physically stored — and because the untagged
     * repository means the key is the bare jti behind the store's prefix.
     */
    private function cacheEntryFor(string $token): ?object
    {
        $jti = $this->payloadOf($token)->get('jti');

        $key = app('cache')->store()->getPrefix().$jti;

        return DB::table(config('cache.stores.database.table', 'cache'))->where('key', $key)->first();
    }

    private function secondsUntil(int $timestamp): int
    {
        return $timestamp - Carbon::now()->getTimestamp();
    }
}

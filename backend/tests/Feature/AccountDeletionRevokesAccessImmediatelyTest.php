<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InteractsWithJwtRevocation;
use Tests\TestCase;

/**
 * THE CONTRACT: a deleted account is gone — for the data AND for the access —
 * and the access is gone IMMEDIATELY, not "whenever the token happens to
 * expire" and not "as long as some cache entry survives".
 *
 * ## Why this promise needs its own test at all
 *
 * There are TWO ways a token stops working here, and their quality differs by
 * an order of magnitude:
 *
 *   Weg A — the account is deleted. `JWTGuard::user()` resolves the subject
 *           with `$this->provider->retrieveById($payload['sub'])`
 *           (`JWTGuard.php:107`) — a plain DB lookup. Row gone ⇒ `$this->user`
 *           stays null ⇒ 401 on the very next request. Nothing is cached,
 *           nothing expires, nothing has to be swept.
 *
 *   Weg B — `logout()`. Writes the jti into the CACHE blacklist
 *           (`Providers\Storage\Illuminate::__construct(CacheContract $cache)`
 *           — a cache, not a table) and swallows a `JWTException` on failure
 *           (`JWTGuard.php:219-223`, accepted risk A6). Its revocation can be
 *           undone by a single `cache:clear`, and its failure is reported to
 *           the user as success.
 *
 * The account-deletion feature rests on **Weg A**, and must therefore not
 * silently come to depend on **Weg B**. `test_…_does_not_go_through_the_jwt_blacklist`
 * is what keeps the two apart.
 *
 * ## Three instrumentation traps, all of which were measured here
 *
 * A test that is green without having established its preconditions is worth
 * nothing, and the previous version of this file was exactly that. Each of the
 * following was *measured*, not assumed:
 *
 * 1. **`JWTGuard::user()` memoises `$this->user` and the guard is a container
 *    singleton that survives between `getJson()` calls.** Every protected
 *    request here therefore starts with `forgetGuards()`.
 *
 * 2. **`JWT::check()` swallows the exception and returns `false`**
 *    (`JWT.php:139-148`). "No exception was thrown" is meaningless there; the
 *    RETURN VALUE is the signal. Not used in this file — the assertions are
 *    made on HTTP status codes, which cannot be swallowed.
 *
 * 3. **The harness never transported the token at all.** `withCookie()` +
 *    `getJson()` answers 200 — but not through the cookie:
 *    `MakesHttpRequests::prepareCookiesForJsonRequest()` returns `[]` unless
 *    `withCredentials()` is set, and even then `prepareCookiesForRequest()`
 *    *encrypts* the value, which nothing decrypts for `/api/*` (the
 *    `EncryptCookies` middleware only runs in the `web` group). Measured: with
 *    `withCookie()` + `withCredentials()` and the in-memory token cleared, a
 *    protected route answers **401**, cookie value `eyJpdiI6…` (encrypted).
 *    The 200s in that old test came from `auth('api')->login()` leaving the
 *    token in the process-global `JWT::$token` singleton — and `logout()` ends
 *    in `JWT::unsetToken()`, which empties it. So its "logout revokes" 401 was
 *    produced by *there being no token at all*, not by the blacklist: with the
 *    blacklist entry removed by hand the replay was still 401.
 *    The channel used here is `Tests\TestCase::withJwtCookie()` — the
 *    plaintext setter **plus** the credentials switch, and the switch is not
 *    optional: `withUnencryptedCookie()` on its own is row 3 of the measured
 *    table in `JwtCookieChannelTest` and answers **401**, because
 *    `prepareCookiesForJsonRequest()` drops the cookie before it reaches the
 *    wire. `withJwtCookie()` really does put the token on the wire (measured:
 *    200 with the singleton cleared), so a 401 below can only come from the
 *    request being genuinely rejected.
 *
 * Every protected request in this file asserts, immediately before it, that the
 * singleton carries NO token — so no request can be answered out of memory and
 * every 401 is attributable.
 */
class AccountDeletionRevokesAccessImmediatelyTest extends TestCase
{
    use InteractsWithJwtRevocation;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ A ---

    /**
     * The promise in one test: the same token, accepted while the account
     * exists and rejected the moment it does not.
     *
     * The counter-probe first is not decoration. Without the 200 there is no
     * evidence that the token was ever good, and a 401 afterwards is exactly
     * what a token that never worked would also produce — which is the shape of
     * the bug described in the class docblock.
     */
    public function test_a_token_is_accepted_while_the_account_exists_and_rejected_immediately_after_the_deletion(): void
    {
        $user = User::factory()->create(['email' => 'deleting@example.com']);
        $token = $this->authenticateOverTheCookie($user);

        // Gegenprobe: the account still exists, so the token must work.
        $this->assertSame(
            200,
            $this->callProtectedRoute(),
            'PREMISE: the token must be accepted while the account exists, otherwise the 401 below proves nothing.'
        );

        User::query()->whereKey($user->id)->delete();

        // The premise, established BEFORE the result is interpreted.
        $this->assertFalse(
            User::query()->whereKey($user->id)->exists(),
            'PREMISE: the users row must really be gone before a 401 may be read as a revocation.'
        );

        $this->assertSame(
            401,
            $this->callProtectedRoute(),
            'A deleted account must be unauthenticable on the very next request — not "later".'
        );
    }

    /**
     * "Immediately" is a claim about time, so it is tested against time.
     *
     * One second before the token's own `exp` the deleted account must still
     * be locked out, while a *surviving* account's token is still accepted at
     * that same instant. The second half is the control: without it, a 401
     * could be the harness having broken under time travel rather than the
     * promise being kept.
     */
    public function test_the_revocation_does_not_wait_for_the_token_to_expire(): void
    {
        $deleted = User::factory()->create(['email' => 'gone@example.com']);
        $deletedToken = $this->authenticateOverTheCookie($deleted);
        $deletedExpiry = $this->expiryOf($deletedToken);

        $survivor = User::factory()->create(['email' => 'alive@example.com']);
        $survivorToken = $this->authenticateOverTheCookie($survivor);
        $survivorExpiry = $this->expiryOf($survivorToken);

        $this->assertSame(200, $this->callProtectedRoute($deletedToken), 'PREMISE: valid before deletion.');

        User::query()->whereKey($deleted->id)->delete();

        // One minute before the token would have expired on its own. The
        // premise of THIS test: the token is still within its own lifetime, so
        // an expiry cannot explain anything.
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deletedExpiry)->subMinute());

        $this->assertGreaterThan(
            Carbon::now()->getTimestamp(),
            $this->expiryOf($deletedToken),
            'PREMISE: the token must not be expired at the moment of the check.'
        );

        $this->assertSame(
            200,
            $this->callProtectedRoute($survivorToken),
            'PREMISE: a surviving account is still authenticable at the same instant — the clock move broke nothing.'
        );

        $this->assertSame(
            401,
            $this->callProtectedRoute($deletedToken),
            'A deleted account must be locked out while its token is still unexpired — revocation is immediate, not deferred to exp.'
        );

        // Sanity: the two tokens were minted with the same TTL, so the only
        // difference between them is the account, not the clock.
        $this->assertEqualsWithDelta(
            $deletedExpiry - $survivorExpiry,
            0,
            60,
            'PREMISE: both tokens share one TTL, so the comparison above isolates the account deletion.'
        );
    }

    /**
     * The revocation must not be a cache entry in disguise.
     *
     * If it were, it would inherit every weakness of Weg B: one `cache:clear`
     * would undo a deleted account's revocation. This asserts the deletion
     * leaves NO trace in the blacklist, and that the account is nevertheless
     * rejected.
     */
    public function test_the_revocation_does_not_go_through_the_jwt_blacklist(): void
    {
        $user = User::factory()->create(['email' => 'notblacklisted@example.com']);
        $token = $this->authenticateOverTheCookie($user);

        $this->assertSame(200, $this->callProtectedRoute(), 'PREMISE: valid before deletion.');
        $this->assertFalse(
            $this->blacklistHolds($token),
            'PREMISE: nothing is blacklisted before the deletion either.'
        );

        User::query()->whereKey($user->id)->delete();

        $this->assertFalse(
            $this->blacklistHolds($token),
            'Deleting an account must NOT rely on the JWT blacklist (Weg B). It rests on the DB lookup in JWTGuard::user().'
        );

        $this->assertSame(
            401,
            $this->callProtectedRoute(),
            'The account is gone and no cache entry was written — this rejection must come from the missing users row.'
        );
    }

    /**
     * Weg A against the one thing that kills Weg B: an emptied cache.
     *
     * `logout()`'s revocation lives in the cache and dies with it. The
     * deletion's revocation must not. Measured on the production cache store
     * (`CACHE_STORE=database`), after a full `Cache::flush()`.
     */
    public function test_a_deleted_account_stays_rejected_after_the_whole_cache_is_flushed(): void
    {
        $user = User::factory()->create(['email' => 'flushed@example.com']);
        $token = $this->authenticateOverTheCookie($user);

        $this->assertSame(200, $this->callProtectedRoute(), 'PREMISE: valid before deletion.');

        User::query()->whereKey($user->id)->delete();
        $this->assertFalse(User::query()->whereKey($user->id)->exists(), 'PREMISE: the row is gone.');

        // The flush, plus the evidence that it really emptied the cache and
        // that the token is not expired — the two things that would make a 401
        // uninformative.
        $this->useProductionCacheStore();
        Cache::flush();

        $this->assertFalse($this->blacklistHolds($token), 'PREMISE: the cache is empty.');
        $this->assertGreaterThan(
            Carbon::now()->getTimestamp(),
            $this->expiryOf($token),
            'PREMISE: the token is not expired, so its rejection cannot be an expiry.'
        );

        $this->assertSame(
            401,
            $this->callProtectedRoute(),
            'A deleted account stays unauthenticable after a cache flush — this is what makes Weg A independent of Weg B.'
        );
    }

    // ------------------------------------------------------------- helpers ---
    // Everything about HOW to reach the JWT stack safely lives in
    // `InteractsWithJwtRevocation`, together with the measurements that
    // explain why each step is necessary. Duplicating that knowledge in two
    // test classes is how it starts drifting.
}

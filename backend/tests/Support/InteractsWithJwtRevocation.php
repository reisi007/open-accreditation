<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Cache\DatabaseStore;
use PHPOpenSourceSaver\JWTAuth\Payload;

/**
 * The instrumentation for talking to this application's JWT stack from a test
 * **without** accidentally measuring the test harness instead.
 *
 * ## Why this exists at all
 *
 * Every step below was measured on this codebase; none of it is a guess. The
 * trap is that a naive `withCookie(...)` + `getJson(...)` looks like it
 * authenticates and does not, and the resulting test is green for the wrong
 * reason:
 *
 * - `MakesHttpRequests::prepareCookiesForJsonRequest()` returns `[]` unless
 *   `withCredentials()` is set — so without it, NO cookie is sent at all.
 * - Even with it, `prepareCookiesForRequest()` **encrypts** the value, and
 *   nothing decrypts it for `/api/*`: `EncryptCookies` is a `web`-group
 *   middleware, and the API routes never enter that group. jwt-auth is
 *   configured with `decrypt_cookies => false`, so it reads the ciphertext.
 *   Measured: the request's cookie value starts `eyJpdiI6…`, and with the
 *   in-memory token cleared the protected route answers **401**.
 * - The 200s that the naive setup does produce come from
 *   `auth('api')->login()` leaving the token in the process-global
 *   `JWT::$token` singleton. `logout()` ends in `JWT::unsetToken()`, which
 *   empties it — so a replay after a logout is rejected because *there is no
 *   token at all*, not because of the blacklist. Measured control: with the
 *   blacklist entry removed by hand, the replay was still 401.
 *
 * `withUnencryptedCookie()` is therefore the only channel that really carries
 * the token on the wire, and every helper here clears the singleton first, so
 * a status code can only come from a request that was genuinely validated.
 *
 * Two further traps this trait exists to neutralise:
 *
 * - `JWTGuard::user()` memoises `$this->user`, and the guard is a container
 *   singleton that **survives between `getJson()` calls** — a second request
 *   can be answered from the memo without looking at the token.
 * - `JWT::check()` swallows `JWTException` and returns `false`
 *   (`JWT.php:139-148`), so "no exception was thrown" is meaningless there.
 *   The return value is the signal. This trait deliberately never uses it;
 *   the tests assert HTTP statuses, which cannot be swallowed.
 */
trait InteractsWithJwtRevocation
{
    /**
     * Log in through the real guard and put the token on the wire the way
     * production does: as the httpOnly cookie.
     */
    protected function authenticateOverTheCookie(User $user): string
    {
        $token = auth('api')->login($user);

        $this->withUnencryptedCookie(config('jwt.cookie_key_name'), $token)
            ->withCredentials();

        $this->forgetInMemoryToken();

        return $token;
    }

    /**
     * One request to a protected route, with every piece of shared state that
     * could answer it from memory cleared first.
     *
     * The singleton assertion is taken immediately BEFORE the request, which
     * is the only moment it means anything: during a request the parser
     * legitimately caches what it parsed.
     */
    protected function callProtectedRoute(?string $token = null): int
    {
        if ($token !== null) {
            $this->withUnencryptedCookie(config('jwt.cookie_key_name'), $token);
        }

        $this->forgetInMemoryToken();
        $this->forgetGuard();

        $this->assertFalse(
            $this->inMemoryTokenIsSet(),
            'The JWT singleton must be empty when the request is made, or the request may be answered out of memory instead of validated.'
        );

        return $this->getJson('/api/auth/me')->status();
    }

    /** `forgetGuards()` rebuilds the guards without dropping the `AuthManager`. */
    protected function forgetGuard(): void
    {
        app('auth')->forgetGuards();
    }

    protected function forgetInMemoryToken(): void
    {
        app('tymon.jwt')->unsetToken();
    }

    /**
     * Whether the process-global `JWT::$token` currently holds a token, read
     * without disturbing it so it can be asserted on.
     */
    protected function inMemoryTokenIsSet(): bool
    {
        $property = new \ReflectionProperty(app('tymon.jwt'), 'token');
        $property->setAccessible(true);

        return $property->getValue(app('tymon.jwt')) !== null;
    }

    /**
     * Does the cache blacklist currently reject this token?
     *
     * Asked through the package's own `Blacklist`, not through a raw cache
     * read, so the answer is the one the guard sees.
     */
    protected function blacklistHolds(string $token): bool
    {
        return app('tymon.jwt.blacklist')->has($this->payloadOf($token));
    }

    protected function issuedAtOf(string $token): int
    {
        return (int) $this->payloadOf($token)->get('iat');
    }

    protected function expiryOf(string $token): int
    {
        return (int) $this->payloadOf($token)->get('exp');
    }

    /**
     * Decode a token into a `Payload` WITHOUT going through the blacklist
     * check, so this stays usable on a blacklisted token.
     */
    protected function payloadOf(string $token): Payload
    {
        return app('tymon.jwt.payload.factory')
            ->customClaims(app('tymon.jwt.provider.jwt')->decode($token))
            ->make();
    }

    /**
     * Switch to the cache store a real deployment uses.
     *
     * `phpunit.xml` pins `CACHE_STORE=array`; `.env.example:126` ships
     * `CACHE_STORE=database`, and `DatabaseStore` is not a `TaggableStore` —
     * so the storage provider falls back to an UNTAGGED repository and the
     * entries live under `<cache-prefix><jti>` with no tag isolation. The
     * blacklist behaves differently on the two stores, so anything about
     * flushing has to be measured where it would really happen.
     */
    protected function useProductionCacheStore(): void
    {
        config(['cache.default' => 'database']);

        $this->forgetJwtInstances();

        $this->assertInstanceOf(
            DatabaseStore::class,
            app('cache')->store()->getStore(),
            'PREMISE: this test must run on the cache store production uses.'
        );
    }

    /** Drop everything that captured a cache store or a blacklist storage. */
    protected function forgetJwtInstances(): void
    {
        app()->forgetInstance('cache');
        app()->forgetInstance('tymon.jwt.provider.storage');
        app()->forgetInstance('tymon.jwt.blacklist');
        app()->forgetInstance('tymon.jwt.manager');
        app()->forgetInstance('tymon.jwt');

        $this->forgetGuard();
    }

    /**
     * Point the package's blacklist storage at another class.
     *
     * `forgetInstance('auth')` is deliberately NOT used: that would destroy
     * the `AuthManager` whose `jwt` driver extension is registered once at
     * boot, and the next `auth('api')` would die with
     * "Auth driver [jwt] is not defined".
     */
    protected function swapBlacklistStorage(string $storageClass): void
    {
        config(['jwt.providers.storage' => $storageClass]);

        $this->forgetJwtInstances();

        $this->assertSame(
            $storageClass,
            app('tymon.jwt.provider.storage')::class,
            'PREMISE: the throwing storage must actually be the one in use.'
        );
    }
}

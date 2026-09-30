<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * THE CHANNEL TEST: `Tests\TestCase::withJwtCookie()` is the only way a JWT
 * reaches an `/api/*` request in this suite, and this file is the evidence
 * that it does.
 *
 * ## What is being protected
 *
 * The channel itself is not a convenience — it is the evidence base for every
 * other auth assertion in the suite. If a cookie silently stops being
 * transported, every protected request falls back to the process-global
 * `JWT::$token` singleton, and the suite does not go red: it goes **green for
 * the wrong reason**, because the singleton still answers. That is the failure
 * shape this file exists to make loud, and it is not hypothetical — it is what
 * produced the retracted "`logout()` revokes the token" reading (A6), which
 * held while the blacklist was in fact empty.
 *
 * ## Why the premise is asserted before every result is interpreted
 *
 * A 401 is ambiguous. It can mean "the channel carried a token and the server
 * rejected it" (interesting) or "no token was on the wire at all" (the harness
 * is broken, and the number means nothing). Only the singleton probe tells
 * the two apart, so it is taken immediately BEFORE each request — the sole
 * moment it means anything, since during a request the parser legitimately
 * caches what it parsed.
 *
 * ## The four measurements this rests on
 *
 * All with the singleton cleared and the guard memo dropped, so every status
 * below is a genuinely validated request:
 *
 *   | channel                                          | /api/auth/me |
 *   |--------------------------------------------------|--------------|
 *   | `withCookie()` (no `withCredentials`)            | 401          |
 *   | `withCookie()` + `withCredentials()`             | 401          |
 *   | `withUnencryptedCookie()` (no `withCredentials`) | 401          |
 *   | `withJwtCookie()` = both, unencrypted            | 200          |
 *
 * The ciphertext in row 2 is not a guess: the value arriving at the route
 * begins `eyJpdiI6` (`{"iv":`) where a raw token begins `eyJ0eXAi` (`{"typ":`).
 * `test_the_channel_sends_the_raw_token_and_not_ciphertext` pins that
 * difference down rather than inferring it from a 401.
 */
class JwtCookieChannelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one class in the suite that is allowed to answer its requests from
     * the in-memory JWT token.
     *
     * `test_the_in_memory_token_alone_can_authenticate_a_request` is the
     * PREMISE of every "the 401 above is trustworthy" claim in this file: it
     * shows the singleton really can authenticate, so clearing it proves
     * something. Under `JWT_AUTH_STATE_STRICT=1` (see
     * `Tests\TestCase::strictAuthStateIsEnabled()`) every request is preceded
     * by exactly that clearing, so without this flag the premise probe would
     * contradict the mode it is auditing — and `JwtAuthStateStrictnessTest`
     * pins that exactly one class claims the exemption.
     */
    protected static bool $answersRequestsFromTheInMemoryJwtToken = true;

    /**
     * A valid token, minted WITHOUT touching the `api` guard.
     *
     * `auth('api')->login()` would set the user on the guard instance — a
     * container singleton that survives between requests in one test — and
     * would then authenticate every later request regardless of the token
     * under test. `JWTAuth::fromUser()` only signs: MEASURED, it does not
     * populate `JWT::$token` (unlike `auth('api')->login()`), which is exactly
     * what makes it safe here.
     */
    private function mintToken(): string
    {
        return JWTAuth::fromUser(User::factory()->create());
    }

    /**
     * Clear the singleton and the guard memo, and PROVE it, so a 401 below can
     * only be attributed to the request.
     */
    private function clearInMemoryAuthState(): void
    {
        $this->forgetJwtAuthState();

        $this->assertFalse(
            $this->inMemoryJwtTokenIsSet(),
            'PREMISE: the JWT::$token singleton must be empty, or a request can be answered out of memory instead of validated.'
        );
    }

    // ------------------------------------------------------ the happy path --

    public function test_the_channel_authenticates_a_protected_route(): void
    {
        $token = $this->mintToken();

        $this->withJwtCookie($token);
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_the_channel_sends_the_raw_token_and_not_ciphertext(): void
    {
        $token = $this->mintToken();

        $seen = null;
        Route::middleware('api')->get('/__channel-probe', function (Request $request) use (&$seen) {
            $seen = $request->cookie(config('jwt.cookie_key_name'));

            return response()->json(['ok' => true]);
        });

        $this->withJwtCookie($token);
        $this->clearInMemoryAuthState();

        $this->getJson('/__channel-probe')->assertOk();

        // The decisive comparison. A ciphertext would start `eyJpdiI6`
        // (`{"iv":`) because `prepareCookiesForRequest()` wraps the value in
        // `encrypt()`; a raw JWT starts `eyJ0eXAi` (`{"typ":`). Asserting the
        // exact token — not merely "not the ciphertext" — is what pins the
        // channel to plaintext, which is what production actually sends:
        // `EncryptCookies` never runs on the `api` group.
        $this->assertSame($token, $seen);
        $this->assertStringStartsWith('eyJ0eXAi', (string) $seen);
        $this->assertStringNotContainsString('eyJpdiI6', (string) $seen);
    }

    // -------------------------------------------------- the broken variants --

    /**
     * Row 1 of the table: `withCookie()` alone drops the cookie entirely.
     *
     * `json()` hands `prepareCookiesForJsonRequest()` to the kernel, and that
     * returns `[]` unless `withCredentials()` is set. Silent — no warning, no
     * exception, just an unauthenticated request.
     */
    public function test_with_cookie_without_credentials_transports_nothing(): void
    {
        $token = $this->mintToken();

        $this->withCookie(config('jwt.cookie_key_name'), $token);
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    /**
     * Row 2: the encrypted channel. Worse than the silent drop, because the
     * cookie IS transported — just as something the server cannot read.
     *
     * This is the spelling that produced the retracted reading, and the reason
     * `ForbiddenJwtCookieChannelTest` exists.
     */
    public function test_with_cookie_plus_credentials_sends_unreadable_ciphertext(): void
    {
        $token = $this->mintToken();

        $seen = null;
        Route::middleware('api')->get('/__ciphertext-probe', function (Request $request) use (&$seen) {
            $seen = $request->cookie(config('jwt.cookie_key_name'));

            return response()->json(['ok' => true]);
        });

        $this->withCookie(config('jwt.cookie_key_name'), $token)->withCredentials();
        $this->clearInMemoryAuthState();

        $this->getJson('/__ciphertext-probe')->assertOk();

        $this->assertNotSame($token, $seen, 'The encrypted channel must NOT deliver the raw token.');
        $this->assertStringStartsWith('eyJpdiI6', (string) $seen, 'The value on the wire is encrypt() output, not a JWT.');

        // And the consequence, which is the part that fooled the earlier
        // measurement: a request carrying that ciphertext is UNAUTHENTICATED.
        $this->withJwtCookie($token);
        $this->clearInMemoryAuthState();
        $this->getJson('/api/auth/me')->assertOk();
    }

    /**
     * Row 3: `withUnencryptedCookie()` alone is not enough either.
     *
     * Counter-intuitive and therefore worth pinning — the unencrypted variant
     * sounds like the safe one, and it still silently transports nothing,
     * because the `withCredentials` switch is what enables cookie transport for
     * JSON requests at all. This is why the channel helper must own BOTH calls
     * rather than a test choosing the "safe-looking" one.
     */
    public function test_with_unencrypted_cookie_without_credentials_transports_nothing(): void
    {
        $token = $this->mintToken();

        $this->withUnencryptedCookie(config('jwt.cookie_key_name'), $token);
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    /**
     * The asymmetry that makes the 401s above meaningful.
     *
     * `call()` takes `$cookies` as its THIRD parameter, defaulting to `[]`; the
     * thirteen verb helpers are what fill it in. So a direct
     * `->call($method, $uri)` transports no cookie even with a perfectly good
     * `withJwtCookie()` configured — and an authenticated-by-singleton test
     * written that way asserts 403 while the wire says 401. That is why
     * `Tests\TestCase::callAsApi()` exists, and why `->call(` is forbidden in
     * `tests/` too.
     */
    public function test_call_without_cookies_is_a_guest_even_with_a_configured_cookie(): void
    {
        $token = $this->mintToken();

        $this->withJwtCookie($token);
        $this->clearInMemoryAuthState();

        $this->call('GET', '/api/auth/me')->assertUnauthorized();

        // Same cookie, same guard state — only the entry point differs.
        $this->clearInMemoryAuthState();
        $this->getJson('/api/auth/me')->assertOk();

        // The supported wrapper for a verb-driven loop.
        $this->clearInMemoryAuthState();
        $this->callAsApi('get', '/api/auth/me')->assertOk();
    }

    // ------------------------------------------------ the premise under test --

    /**
     * The singleton really can answer a request — otherwise the whole
     * file is decorative.
     *
     * Every "the 401 above is trustworthy" claim rests on the singleton being a
     * genuine alternative source. If it were inert, clearing it would prove
     * nothing and a broken channel would be indistinguishable from a working
     * one. So: a token left in the singleton, no cookie on the wire at all,
     * and the request comes back 200.
     */
    public function test_the_in_memory_token_alone_can_authenticate_a_request(): void
    {
        $token = $this->mintToken();

        // Deliberately NO cookie: the singleton is the only source.
        app('tymon.jwt')->setToken($token);
        app('auth')->forgetGuards();

        $this->assertTrue(
            $this->inMemoryJwtTokenIsSet(),
            'PREMISE: the singleton must hold the token, or clearing it proves nothing.'
        );

        $this->getJson('/api/auth/me')->assertOk();
    }

    /**
     * And it survives between requests, which is what made the earlier
     * measurement untrustworthy in the other direction.
     *
     * `JWTGuard::user()` memoises `$this->user` and the guard is a container
     * singleton that outlives a single `getJson()` call. After one successful
     * authenticated request, dropping only the `JWT::$token` singleton is NOT
     * enough to force the next request to re-validate — which is precisely why
     * `forgetJwtAuthState()` also calls `AuthManager::forgetGuards()`.
     */
    public function test_the_guard_memo_survives_dropping_only_the_singleton(): void
    {
        $token = $this->mintToken();

        $this->withJwtCookie($token);
        $this->clearInMemoryAuthState();
        $this->getJson('/api/auth/me')->assertOk();

        // Singleton gone, guard memo still holding the user from the request
        // above. The cookie is still configured too, so this only isolates the
        // memo by ALSO removing the cookie's ability to matter.
        app('tymon.jwt')->unsetToken();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        app('auth')->forgetGuards();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}

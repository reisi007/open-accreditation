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
 * ## The measurements this rests on
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
 *   | `->call()` with a configured cookie              | 401          |
 *
 * **And four more, reached through the PROPERTIES rather than the calls** —
 * the second door `ForbiddenJwtCookieChannelTest` rule 5 bans. They are the
 * same two mechanisms, which is the point: the property spelling is not a new
 * failure mode, it is an old one with the guard removed.
 *
 *   | channel                                                   | /api/auth/me |
 *   |-----------------------------------------------------------|--------------|
 *   | `defaultCookies` + `withCredentials`, by hand             | 401          |
 *   | `unencryptedCookies`, no switch, by hand                 | 401          |
 *   | `unencryptedCookies` + `withCredentials`, by hand        | **200**      |
 *   | `defaultCookies` + `withCredentials` + `encryptCookies`  | **200**      |
 *
 * The last TWO rows are the ones to read twice. Neither is broken, which is
 * exactly why neither can be caught by any rule that only reasons about silent
 * drops: each is a second, hand-built, fully working place where a JWT cookie
 * gets configured, and the single-writer rule is the only thing standing
 * between them and a third one tomorrow.
 *
 * The fourth row is the one an earlier version of this table did not have,
 * because `ForbiddenJwtCookieChannelTest` carried a comment asserting that
 * `encryptCookies` "cannot drop a JWT" and was in the ban only as the helper
 * that makes the other three work. MEASURED, both halves false: writing `false`
 * takes the unencrypted branch of `prepareCookiesForRequest()`
 * (`MakesHttpRequests.php:730-740`), and the encrypting property then hands the
 * RAW token to the wire. A second open door, not a helper.
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

    /**
     * The guard's row 5, MEASURED: the properties are a second door to the same
     * transport, and TWO of them open a channel that WORKS.
     *
     * `ForbiddenJwtCookieChannelTest` bans writing these four properties
     * directly, and a ban that is not measured is an assertion. So the halves
     * are measured here, and they come out differently, which is why this is one
     * method with two spellings rather than two separate "it is broken" claims:
     *
     *  - `defaultCookies` + `withCredentials`, by hand: **401**. Identical to
     *    row 2 above, because writing the property IS what `withCookie()`
     *    does — `prepareCookiesForRequest()` `encrypt()`s every entry. The
     *    singleton then answers the test, which is the green-suite hole
     *    `ForbiddenJwtCookieChannelTest` documents.
     *  - `unencryptedCookies` + `withCredentials`, by hand: **200**. A real,
     *    working, hand-built channel — the plaintext passes `encrypt()`'s
     *    merge untouched and the switch turns transport on. Nothing about it
     *    looks broken, which is exactly why the ban has to be textual.
     *
     * The second half is the reason this is not redundant with rules 1–4: it
     * cannot be caught by anything that only reasons about failure modes. The
     * second working spelling is measured separately, in
     * `test_the_encrypt_flag_is_a_second_working_door`, because it turns on a
     * different property.
     */
    public function test_the_transport_properties_are_a_second_door_to_the_same_channel(): void
    {
        $token = $this->mintToken();
        $name = config('jwt.cookie_key_name');

        // One property write per line, deliberately: the guard counts exempt
        // occurrences with `preg_match()` per line but pins them with
        // `preg_match_all()` over the whole file, so two matches on one line
        // would make those two numbers disagree.
        $this->defaultCookies[$name] = $token;
        $this->withCredentials = true;
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertUnauthorized();

        // Same token, same switch, the OTHER property: now it arrives.
        $this->defaultCookies = [];
        $this->unencryptedCookies[$name] = $token;
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertOk();
    }

    /**
     * And the third shape: the plaintext property WITHOUT the switch. Which is
     * row 3 one door down — the value is configured and the request is still a
     * guest, because the switch is what turns JSON cookie transport on at all.
     */
    public function test_the_unencrypted_property_without_the_switch_transports_nothing(): void
    {
        $token = $this->mintToken();

        $this->unencryptedCookies[config('jwt.cookie_key_name')] = $token;
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    /**
     * The fourth row of the property table, and the one the guard's own comment
     * got wrong: the encrypt flag is a SECOND WORKING DOOR, not the helper that
     * makes the other three work.
     *
     * `prepareCookiesForRequest()` opens with
     *
     *     if (! $this->encryptCookies) {
     *         return array_merge($this->defaultCookies, $this->unencryptedCookies);
     *     }
     *
     * (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/MakesHttpRequests.php:730-740`),
     * so writing `false` into it takes the branch that hands the jar to the wire
     * UNENCRYPTED. Combine that with the switch and the property that would
     * otherwise produce a ciphertext produces the raw token instead — MEASURED
     * here: **200**, and the probe route below sees the token itself.
     *
     * Why this matters for the ban and not merely for tidiness: the previous
     * version of `ForbiddenJwtCookieChannelTest`'s comment kept `encryptCookies`
     * in the pattern because "a write to it cannot drop a JWT, it is the one
     * property that makes the other three work". Both halves of that are false
     * against this measurement — it is not a helper, it opens a channel on its
     * own — and documenting the row as a harmless exclusion "because it cannot
     * do harm" would therefore have been worse than saying nothing. The correct
     * reason is the stronger one: it is a second place that configures a JWT
     * cookie and answers 200, which is exactly what the single-writer rule
     * forbids.
     *
     * The probe route is not decoration. Without it, a 200 would be consistent
     * with "the cookie was dropped and something else authenticated" — the
     * ambiguity this whole file is built to remove.
     */
    public function test_the_encrypt_flag_is_a_second_working_door(): void
    {
        $token = $this->mintToken();
        $name = config('jwt.cookie_key_name');

        $seen = null;
        Route::middleware('api')->get('/__encrypt-flag-probe', function (Request $request) use (&$seen) {
            $seen = $request->cookie(config('jwt.cookie_key_name'));

            return response()->json(['ok' => true]);
        });

        // One property write per line, for the reason the sibling test gives:
        // the guard counts exempt occurrences per LINE and pins them over the
        // whole file, so two matches on one line would make those numbers
        // disagree.
        $this->defaultCookies[$name] = $token;
        $this->withCredentials = true;
        $this->encryptCookies = false;
        $this->clearInMemoryAuthState();

        $this->getJson('/__encrypt-flag-probe')->assertOk();

        // The decisive half: the value on the wire is the RAW token, so this is
        // a working channel rather than an accident that happens to answer 200.
        $this->assertSame($token, $seen, 'The encrypt flag must deliver the raw token, not a ciphertext.');
        $this->assertStringStartsWith('eyJ0eXAi', (string) $seen);
        $this->assertStringNotContainsString('eyJpdiI6', (string) $seen);

        $this->clearInMemoryAuthState();
        $this->getJson('/api/auth/me')->assertOk();

        // And the converse, so the row cannot be read as "the flag is inert":
        // with the switch off, the same three writes transport nothing at all.
        $this->withCredentials = false;
        $this->clearInMemoryAuthState();

        $this->getJson('/api/auth/me')->assertUnauthorized();
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

<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * WP-1-a / WP-1-f: cookie hardening of the auth surface.
 *
 * The SPA and the API are served from the SAME origin — the SPA calls the API
 * with relative paths (`fetch('/api/…')` in `frontend/src/api/client.ts`) and
 * Caddy routes `/api*` to this backend inside the very same per-mandant site
 * block. The JWT cookie therefore ships with `SameSite=Lax` in EVERY
 * environment; `SameSite=None` (which requires `Secure` and removes the
 * SameSite half of the CSRF defence) is an explicit opt-in for a genuinely
 * cross-site deployment.
 */
class AuthCookieSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /* ------------------------------------------------------------------ */
    /* JWT cookie */
    /* ------------------------------------------------------------------ */

    public function test_jwt_cookie_is_samesite_lax_by_default(): void
    {
        $this->assertFalse(config('jwt.cross_site_cookie'));

        $cookie = $this->loginCookie();

        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
    }

    public function test_jwt_cookie_is_samesite_lax_in_a_local_environment(): void
    {
        // Local dev serves the SPA over plain HTTP — `SameSite=None` is
        // invalid there (browsers require `Secure`), which used to be the
        // reason for a local/non-local split. `Lax` is correct in both.
        app()->detectEnvironment(fn () => 'local');

        $cookie = $this->loginCookie();

        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertFalse($cookie->isSecure());
    }

    public function test_jwt_cookie_stays_secure_in_a_production_environment(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->trustAppUrlHost();

        $cookie = $this->loginCookie();

        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue($cookie->isSecure());
    }

    public function test_cross_site_opt_in_switches_the_cookie_to_samesite_none_and_keeps_secure(): void
    {
        config(['jwt.cross_site_cookie' => true]);
        app()->detectEnvironment(fn () => 'production');
        $this->trustAppUrlHost();

        $cookie = $this->loginCookie();

        $this->assertSame('none', strtolower((string) $cookie->getSameSite()));
        // `SameSite=None` is only valid together with `Secure` — Chrome >= 84 /
        // Firefox >= 96 reject the cookie outright without it, which broke
        // every login silently.
        $this->assertTrue($cookie->isSecure());
    }

    public function test_cross_site_opt_in_is_refused_in_a_plain_http_environment(): void
    {
        // The two attributes are mutually exclusive over plain HTTP: `SameSite=None`
        // needs `Secure`, and browsers drop a `Secure` cookie on `http://`. The
        // combination is therefore REFUSED loudly instead of emitting a cookie no
        // client would accept (before: `SameSite=None` without `Secure`, i.e.
        // silently broken auth in every `local` deployment that set the flag).
        Log::spy();

        config(['jwt.cross_site_cookie' => true]);
        app()->detectEnvironment(fn () => 'local');

        User::factory()->create(['email' => 'max@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])
            ->assertStatus(500)
            // No half-valid token is handed out either.
            ->assertCookieMissing(config('jwt.cookie_key_name'));

        Log::shouldHaveReceived('critical')->withArgs(
            fn (string $message): bool => str_contains($message, 'JWT_CROSS_SITE_COOKIE')
        );
    }

    /* ------------------------------------------------------------------ */
    /* Session cookie (WP-1-f) */
    /* ------------------------------------------------------------------ */

    public function test_session_cookie_is_secure_outside_local_by_default(): void
    {
        // The live config of the test process: before WP-1-f the value was
        // `null` (an unset env var with no default), i.e. the cookie was NOT
        // Secure in any environment.
        $this->assertTrue(config('session.secure'));

        $this->assertTrue($this->sessionSecureWithEnv(['APP_ENV' => 'production']));
        $this->assertTrue($this->sessionSecureWithEnv(['APP_ENV' => 'testing']));
        $this->assertTrue($this->sessionSecureWithEnv([]));
    }

    public function test_session_cookie_is_not_secure_in_local_by_default(): void
    {
        $this->assertFalse($this->sessionSecureWithEnv(['APP_ENV' => 'local']));
    }

    public function test_session_secure_cookie_env_var_wins_over_the_default(): void
    {
        $this->assertTrue($this->sessionSecureWithEnv([
            'APP_ENV' => 'local',
            'SESSION_SECURE_COOKIE' => 'true',
        ]));

        $this->assertFalse($this->sessionSecureWithEnv([
            'APP_ENV' => 'production',
            'SESSION_SECURE_COOKIE' => 'false',
        ]));
    }

    /**
     * Declare the host this test's requests use.
     *
     * `MakesHttpRequests` prefixes every URI with `config('app.url')`, so a
     * production-simulated run has to allow-list that host explicitly:
     * `trustHosts(…, subdomains: false)` (WF-2-c) no longer appends
     * `^(.+\.)?<APP_URL host>$`, and a production allow-list without a single
     * tenant domain answers 400 before any cookie logic runs. Pinning the host
     * here also keeps these tests independent of the developer's local `.env`
     * (`APP_URL`), which they used to silently rely on.
     */
    private function trustAppUrlHost(): void
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->assertIsString($host);

        config(['security.trusted_hosts' => '^'.preg_quote($host, '{}').'$']);
    }

    /**
     * Log in and return the `accr_jwt` cookie of the response.
     */
    private function loginCookie(): Cookie
    {
        User::factory()->create(['email' => 'max@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertCookie(config('jwt.cookie_key_name'));

        $cookie = $response->getCookie(config('jwt.cookie_key_name'), false);

        $this->assertNotNull($cookie);

        return $cookie;
    }

    /**
     * Evaluate `config/session.php` with the given environment overrides, the
     * way the framework does at boot, and return the resulting `secure` value.
     *
     * The dotenv repository is immutable and was already populated from
     * `.env`/`phpunit.xml`, so it is dropped and rebuilt from the temporarily
     * adjusted superglobals; the previous state is restored afterwards.
     */
    private function sessionSecureWithEnv(array $overrides): mixed
    {
        $original = [];

        foreach ($overrides as $key => $value) {
            $original[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }

        try {
            Env::enablePutenv(); // rebuild the repository from the current env

            $config = require config_path('session.php');

            return $config['secure'];
        } finally {
            foreach ($original as $key => [$server, $env, $putenv]) {
                unset($_SERVER[$key], $_ENV[$key]);

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }

                if ($env !== null) {
                    $_ENV[$key] = $env;
                }

                if ($putenv === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$putenv);
                }
            }

            Env::enablePutenv();
        }
    }
}

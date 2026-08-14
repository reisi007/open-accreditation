<?php

namespace Tests\Feature;

use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * B2: login and register use separate rate-limit buckets. Before, both routes
 * shared a single `throttle:5,1` bucket, so failed register attempts silently
 * consumed the login quota and vice versa. The named limiters are registered
 * in `AppServiceProvider`.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MandantContext::reset();
    }

    public function test_login_and_register_limiters_are_separate_buckets(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $login = RateLimiter::limiter('login');
        $register = RateLimiter::limiter('register');

        $this->assertNotNull($login, 'named limiter "login" must be registered');
        $this->assertNotNull($register, 'named limiter "register" must be registered');

        $loginLimit = $login($request);
        $registerLimit = $register($request);

        // Limits are env-dependent (AppServiceProvider): in `testing` (and
        // `local`) the budgets are raised to login 40/min + register 30/min —
        // the parallel Playwright suite needs ~17 logins/min behind one ip.
        // Production keeps the real brute-force values: login 15, register 10.
        $this->assertSame(40, $loginLimit->maxAttempts);
        $this->assertSame(30, $registerLimit->maxAttempts);

        // Same request/ip must still resolve to distinct bucket keys, otherwise
        // the middleware falls back to the shared route+ip signature.
        $this->assertNotSame($loginLimit->key, $registerLimit->key);
    }

    public function test_register_bucket_is_untouched_by_login_throttling(): void
    {
        // Exhaust the login bucket (40/min in testing) with failed logins.
        for ($i = 0; $i < 41; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // Login is now throttled…
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // …but register still evaluates (validation error, not a throttle 429).
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_bucket_blocks_31st_attempt_without_touching_login(): void
    {
        // Exhaust the register bucket (30/min in testing) with failed register
        // requests (validation errors still count toward the route throttle).
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/auth/register', [])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['name', 'email', 'password']);
        }

        // Register is now throttled…
        $this->postJson('/api/auth/register', [])
            ->assertStatus(429);

        // …but login still evaluates (validation error, not a throttle 429).
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /* ---------------------------------------------------------------------
     | P3e-B1: activate + public limiters (named buckets, no cross-route
     | cannibalism). Before, all anonymous inline throttles (`throttle:20,1`
     | / `throttle:60,1`) shared ONE per-ip bucket — Laravel keys them on
     | `sha1(domain|ip)`, identical without a route domain — so `activate`,
     | the portal and the accreditation list ate each other's budget.
     | ------------------------------------------------------------------- */

    public function test_activate_and_public_limiters_are_registered_with_distinct_keys(): void
    {
        $request = Request::create('/api/auth/activate/abc123', 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.2');

        $activate = RateLimiter::limiter('activate');
        $public = RateLimiter::limiter('public');

        $this->assertNotNull($activate, 'named limiter "activate" must be registered');
        $this->assertNotNull($public, 'named limiter "public" must be registered');

        $activateLimit = $activate($request);
        $publicLimit = $public($request);

        $this->assertSame(30, $activateLimit->maxAttempts);
        $this->assertSame(60, $publicLimit->maxAttempts);

        // The same request/ip must resolve to distinct bucket keys for every
        // named limiter — otherwise parallel runs on different routes still
        // share a single budget.
        $keys = [
            $activateLimit->key,
            $publicLimit->key,
            RateLimiter::limiter('login')($request)->key,
            RateLimiter::limiter('register')($request)->key,
        ];

        $this->assertCount(4, array_unique($keys), 'activate/public/login/register must use distinct bucket keys');
    }

    public function test_activate_and_public_buckets_are_independent_of_login_throttling(): void
    {
        // Exhaust the login bucket (40/min in testing) with failed logins.
        for ($i = 0; $i < 41; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // The exhausted login bucket must not throttle activate or public —
        // they own separate named buckets. (404: bogus token / no mandant.)
        $this->getJson('/api/auth/activate/not-a-token')->assertStatus(404);
        $this->getJson('/api/portal/overview')->assertStatus(404);
    }

    public function test_activate_bucket_blocks_31st_request(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/auth/activate/not-a-token')->assertStatus(404);
        }

        $this->getJson('/api/auth/activate/not-a-token')->assertStatus(429);
    }

    public function test_public_bucket_blocks_61st_request(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/portal/overview')->assertStatus(404);
        }

        $this->getJson('/api/portal/overview')->assertStatus(429);
    }
}

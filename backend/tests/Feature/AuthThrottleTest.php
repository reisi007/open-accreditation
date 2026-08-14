<?php

namespace Tests\Feature;

use App\Models\User;
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

    /* ---------------------------------------------------------------------
     | F5 / P2a-RL / P5-F2: the `media`, `admin` and `resend` named limiters.
     | All three are keyed per AUTHENTICATED user — they resolve the user via
     | `$request->user('api')`, because `Request::user()` without a guard
     | argument uses the *default* guard (web/session), which is null for API
     | requests.
     | ------------------------------------------------------------------- */

    private function authenticatedLimiterRequest(User $user, string $uri): Request
    {
        auth('api')->setUser($user);

        $request = Request::create($uri, 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.7');
        $request->setUserResolver(static fn (?string $guard = null) => auth($guard)->user());

        return $request;
    }

    public function test_media_limiter_is_registered_and_keyed_per_user(): void
    {
        $user = User::factory()->create();
        $request = $this->authenticatedLimiterRequest($user, '/api/user/media');

        $limiter = RateLimiter::limiter('media');
        $this->assertNotNull($limiter, 'named limiter "media" must be registered');

        $limit = $limiter($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('media:'.$user->id, $limit->key);

        // Same ip, different user → different bucket key.
        $other = User::factory()->create();
        $otherLimit = $limiter($this->authenticatedLimiterRequest($other, '/api/user/media'));
        $this->assertSame('media:'.$other->id, $otherLimit->key);
        $this->assertNotSame($limit->key, $otherLimit->key);

        // Unauthenticated request → per-ip fallback.
        $guestRequest = Request::create('/api/user/media', 'POST');
        $guestRequest->server->set('REMOTE_ADDR', '10.0.0.8');
        $this->assertSame('media:10.0.0.8', $limiter($guestRequest)->key);
    }

    public function test_admin_limiter_is_registered_and_keyed_per_user(): void
    {
        $user = User::factory()->create();
        $request = $this->authenticatedLimiterRequest($user, '/api/admin/mandants');

        $limiter = RateLimiter::limiter('admin');
        $this->assertNotNull($limiter, 'named limiter "admin" must be registered');

        $limit = $limiter($request);

        $this->assertSame(300, $limit->maxAttempts);
        $this->assertSame('admin:'.$user->id, $limit->key);
    }

    public function test_resend_limiter_is_registered_and_keyed_per_user(): void
    {
        $user = User::factory()->create();
        $request = $this->authenticatedLimiterRequest($user, '/api/admin/applications/1/resend');

        $limiter = RateLimiter::limiter('resend');
        $this->assertNotNull($limiter, 'named limiter "resend" must be registered');

        $limit = $limiter($request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame('resend:'.$user->id, $limit->key);
    }

    public function test_media_throttle_is_applied_to_user_and_self_service_uploads(): void
    {
        $this->assertContains('throttle:media', $this->routeMiddleware('api.user.media.store'));
        $this->assertContains('throttle:media', $this->routeMiddleware('api.mandant.logo.store'));
        $this->assertContains('throttle:media', $this->routeMiddleware('api.mandant.header.store'));

        // Read/delivery routes of the same surface stay unthrottled.
        $this->assertNotContains('throttle:media', $this->routeMiddleware('api.user.media.index'));
        $this->assertNotContains('throttle:media', $this->routeMiddleware('api.user.media.show'));
    }

    public function test_admin_throttle_is_applied_to_write_routes_only(): void
    {
        $this->assertContains('throttle:admin', $this->routeMiddleware('api.admin.mandants.store'));
        $this->assertContains('throttle:admin', $this->routeMiddleware('api.admin.mandants.update'));
        $this->assertContains('throttle:admin', $this->routeMiddleware('api.admin.mandants.destroy'));
        $this->assertContains('throttle:admin', $this->routeMiddleware('api.admin.applications.update'));
        $this->assertContains('throttle:admin', $this->routeMiddleware('api.admin.users.roles.update'));

        // Read routes are deliberately NOT throttled (auth-gated already).
        $this->assertNotContains('throttle:admin', $this->routeMiddleware('api.admin.mandants.index'));
        $this->assertNotContains('throttle:admin', $this->routeMiddleware('api.admin.mandants.show'));
        $this->assertNotContains('throttle:admin', $this->routeMiddleware('api.admin.applications.index'));
    }

    public function test_resend_throttle_is_applied_to_the_resend_route(): void
    {
        $this->assertContains('throttle:resend', $this->routeMiddleware('api.admin.applications.resend'));
    }

    private function routeMiddleware(string $routeName): array
    {
        $route = app('router')->getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "route {$routeName} must exist");

        return $route->gatherMiddleware();
    }
}

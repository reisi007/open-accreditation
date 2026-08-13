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

        // P2b-F9: login budget is 15/min (E2E @smoke needs ~11–13 logins/min),
        // register stays at 10/min.
        $this->assertSame(15, $loginLimit->maxAttempts);
        $this->assertSame(10, $registerLimit->maxAttempts);

        // Same request/ip must still resolve to distinct bucket keys, otherwise
        // the middleware falls back to the shared route+ip signature.
        $this->assertNotSame($loginLimit->key, $registerLimit->key);
    }

    public function test_register_bucket_is_untouched_by_login_throttling(): void
    {
        // Exhaust the login bucket (15/min) with failed login attempts.
        for ($i = 0; $i < 16; $i++) {
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
}

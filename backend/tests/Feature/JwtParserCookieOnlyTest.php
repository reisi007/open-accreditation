<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * F2: the JWT parser chain is restricted to the httpOnly `accr_jwt` cookie
 * ONLY. The SPA authenticates exclusively via that cookie, so every other
 * token channel (`Authorization: Bearer`, `?token=`, POST `token`, route
 * params) is dead surface and must not authenticate.
 */
class JwtParserCookieOnlyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Issue a VALID token WITHOUT touching the api guard. `auth('api')
     * ->login()` would cache the user (and token) on the guard instance, which
     * then authenticates every subsequent request in this test process
     * regardless of the request token — masking exactly the behavior under
     * test. `JWTAuth::fromUser()` only signs the token.
     */
    private function token(): string
    {
        $user = User::factory()->create();

        return JWTAuth::fromUser($user);
    }

    public function test_cookie_token_authenticates_on_protected_route(): void
    {
        $token = $this->token();

        // `withCredentials()` mirrors the SPA's `credentials: include` (JSON
        // test requests drop cookies otherwise); `withUnencryptedCookie` sends
        // the raw token — `decrypt_cookies` is false, so the real browser
        // cookie is plain too.
        $this->withCredentials()
            ->withUnencryptedCookie(config('jwt.cookie_key_name'), $token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_bearer_token_is_rejected(): void
    {
        $token = $this->token();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_query_string_token_is_rejected(): void
    {
        $token = $this->token();

        $this->getJson('/api/auth/me?token='.$token)
            ->assertStatus(401);
    }

    public function test_post_body_token_is_rejected(): void
    {
        $token = $this->token();

        $this->postJson('/api/auth/logout', ['token' => $token])
            ->assertStatus(401);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        MandantContext::reset();
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_login_returns_jwt_in_httponly_cookie_for_activated_user(): void
    {
        $user = User::factory()->create(['email' => 'max@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));

        $cookie = $response->getCookie(config('jwt.cookie_key_name'), false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure()); // testing env != local → secure cookie
    }

    public function test_login_is_case_insensitive_for_email(): void
    {
        $user = User::factory()->create(['email' => 'max@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'MAX@Example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));

        $this->assertSame('max@example.com', $user->fresh()->email);
    }

    public function test_login_blocks_unverified_users(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'max@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Das Konto ist noch nicht aktiviert. Bitte prüfe deine E-Mail.');
    }

    public function test_login_rejects_wrong_password_and_unknown_email_with_generic_error(): void
    {
        $user = User::factory()->create(['email' => 'max@example.com']);

        foreach ([
            ['email' => 'max@example.com', 'password' => 'wrong-password'],
            ['email' => 'unknown@example.com', 'password' => 'password'],
        ] as $credentials) {
            $this->postJson('/api/auth/login', $credentials)
                ->assertStatus(401)
                ->assertJsonPath('message', 'Ungültige Zugangsdaten.');
        }
    }

    public function test_logout_invalidates_the_token(): void
    {
        $user = User::factory()->create(['email' => 'max@example.com']);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ]);

        $token = $login->getCookie(config('jwt.cookie_key_name'), false)->getValue();

        $this->withCookie(config('jwt.cookie_key_name'), $token)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->withCookie(config('jwt.cookie_key_name'), $token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertCookieExpired(config('jwt.cookie_key_name'));

        $this->withCookie(config('jwt.cookie_key_name'), $token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_login_blocks_a_user_without_a_role_on_the_current_mandant(): void
    {
        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);

        $user = User::factory()->create(['email' => 'max@example.com']);
        $role = Role::query()->where('slug', 'user')->firstOrFail();

        // User only belongs to mandant A.
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandantA->id,
            'team_id' => null,
        ]);

        MandantContext::set($mandantA);
        $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])->assertOk();

        MandantContext::set($mandantB);
        $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Dieser Account ist für dieses Portal nicht registriert.');
    }

    public function test_super_admin_may_log_in_on_any_mandant(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband']);

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        RoleUser::create([
            'user_id' => $admin->id,
            'role_id' => $role->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        MandantContext::set($mandant);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk();
    }
}

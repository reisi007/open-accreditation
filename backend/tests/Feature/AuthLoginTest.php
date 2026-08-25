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

        // Cross-site: in a non-local (production) environment the SPA and API
        // live on different origins, so the cookie must be SameSite=None
        // (only valid together with Secure) — SameSite=Lax would drop it.
        $this->assertSame('none', strtolower((string) $cookie->getSameSite()));
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

    /* ---------------------------------------------------------------------
     | BE-R1: per-mandant accounts — host-scoped login lookups.
     |
     | Emails are unique per mandant, so the same address may exist as two
     | independent accounts (one per mandant domain). The login lookup is
     | therefore host-scoped: it resolves the CURRENT mandant's account for
     | the email (falling back to global `mandant_id = null` accounts such
     | as the bootstrap super admin) and never an account that only exists
     | on another mandant's domain.
     -------------------------------------------------------------------- */

    public function test_login_resolves_the_account_of_the_current_mandants_domain(): void
    {
        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);

        // Two independent accounts sharing one email — exactly what per-mandant
        // registration produces, including each account's own role assignment.
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();
        $userA = User::factory()->forMandant($mandantA)->create(['email' => 'alice@x.com']);
        $userB = User::factory()->forMandant($mandantB)->create(['email' => 'alice@x.com', 'password' => 'other-pass']);
        foreach ([$userA, $userB] as $account) {
            RoleUser::create([
                'user_id' => $account->id,
                'role_id' => $userRole->id,
                'mandant_id' => $account->mandant_id,
                'team_id' => null,
            ]);
        }

        // On mandant A's domain the mandant-A account is used …
        MandantContext::set($mandantA);
        $this->postJson('/api/auth/login', [
            'email' => 'alice@x.com',
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'alice@x.com',
            'password' => 'other-pass',
        ])->assertStatus(401);

        // … and on mandant B's domain the mandant-B account with ITS OWN password.
        MandantContext::set($mandantB);
        $this->postJson('/api/auth/login', [
            'email' => 'alice@x.com',
            'password' => 'other-pass',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'alice@x.com',
            'password' => 'password',
        ])->assertStatus(401);

        // Sanity: both independent accounts really exist side by side.
        $this->assertSame($mandantA->id, $userA->fresh()->mandant_id);
        $this->assertSame($mandantB->id, $userB->fresh()->mandant_id);
    }

    public function test_login_rejects_an_email_that_only_exists_on_another_mandant(): void
    {
        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);

        User::factory()->forMandant($mandantA)->create(['email' => 'alice@x.com']);

        // On mandant B's domain this email does not exist — and it must NOT be
        // leaked whether it exists elsewhere: generic invalid-credentials 401,
        // identical to a fully unknown email.
        MandantContext::set($mandantB);

        $this->postJson('/api/auth/login', [
            'email' => 'alice@x.com',
            'password' => 'password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Ungültige Zugangsdaten.');
    }

    public function test_login_prefers_the_current_mandants_account_over_the_global_one(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband']);

        // Global account (bootstrap-super-admin style, mandant_id NULL) AND a
        // local account of this very mandant share one email.
        $globalAdmin = User::factory()->create(['email' => 'chief@x.com']);
        $localUser = User::factory()->forMandant($mandant)->create(['email' => 'chief@x.com']);

        $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();
        RoleUser::create([
            'user_id' => $globalAdmin->id,
            'role_id' => $superAdminRole->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);
        RoleUser::create([
            'user_id' => $localUser->id,
            'role_id' => $userRole->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        MandantContext::set($mandant);

        // The domain-local identity wins over the global account.
        $login = $this->postJson('/api/auth/login', [
            'email' => 'chief@x.com',
            'password' => 'password',
        ])->assertOk();

        $token = $login->getCookie(config('jwt.cookie_key_name'), false)->getValue();

        $this->withCookie(config('jwt.cookie_key_name'), $token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $localUser->id);
    }
}

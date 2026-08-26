<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Mail\ActivationMail;
use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->mandant = Mandant::factory()->create(['slug' => 'verband']);
        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_register_creates_user_sends_activation_mail_and_assigns_user_role(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'Max@Example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Registrierung erfolgreich. Bitte prüfe deine E-Mail zur Aktivierung.');

        $this->assertDatabaseHas('users', [
            'email' => 'max@example.com',
            'email_verified_at' => null,
            // BE-R1: the account is bound to the current mandant.
            'mandant_id' => $this->mandant->id,
        ]);

        $user = User::where('email', 'max@example.com')->firstOrFail();

        $this->assertNotNull($user->activation_token);
        $this->assertNotNull($user->activation_token_expires_at);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertNull($user->email_verified_at);

        // The new account is scoped to the current mandant only.
        $this->assertSame('user', $user->roleForMandant($this->mandant->id));

        // F4: the mail URL carries the RAW token; the DB stores its sha256
        // digest — never the raw token itself.
        Mail::assertSent(ActivationMail::class, function (ActivationMail $mail) use ($user) {
            $rawToken = (string) substr($mail->activationUrl, strrpos($mail->activationUrl, '/') + 1);

            return $mail->hasTo($user->email)
                && str_contains($mail->activationUrl, $rawToken)
                && hash('sha256', $rawToken) === $user->activation_token;
        });
    }

    public function test_register_builds_activation_url_from_current_mandant_domain(): void
    {
        Mail::fake();

        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);
        MandantContext::set($bundesliga);

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertCreated();

        $user = User::where('email', 'max@example.com')->firstOrFail();

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        // F4: the mail URL carries the RAW token; the DB stores its sha256.
        Mail::assertSent(ActivationMail::class, function (ActivationMail $mail) use ($user, $scheme) {
            $rawToken = (string) substr($mail->activationUrl, strrpos($mail->activationUrl, '/') + 1);

            return $mail->hasTo($user->email)
                && str_starts_with($mail->activationUrl, $scheme.'://bundesliga.test/api/auth/activate/')
                && str_contains($mail->activationUrl, $rawToken)
                && hash('sha256', $rawToken) === $user->activation_token;
        });
    }

    public function test_activation_url_falls_back_to_config_host_without_mandant(): void
    {
        MandantContext::reset();

        $method = new \ReflectionMethod(AuthController::class, 'activationUrl');
        $url = $method->invoke(new AuthController, 'abc123');

        $configHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->assertNotNull($configHost);
        $this->assertStringContainsString($configHost, $url);
        $this->assertStringContainsString('/api/auth/activate/abc123', $url);
    }

    public function test_register_requires_a_current_mandant(): void
    {
        MandantContext::reset();

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertStatus(422);
    }

    public function test_register_rejects_duplicate_email_within_the_same_mandant(): void
    {
        // BE-R1: uniqueness is scoped to the current mandant — the existing
        // account must therefore belong to THIS mandant to collide.
        User::factory()->forMandant($this->mandant)->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'taken@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_allows_the_same_email_on_two_different_mandants(): void
    {
        Mail::fake();

        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);

        // First registration on the setUp mandant …
        $this->postJson('/api/auth/register', [
            'name' => 'Alice Erstkonto',
            'email' => 'alice@x.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertCreated();

        // … then the SAME email on a different mandant's domain: both
        // registrations succeed and produce two independent accounts.
        MandantContext::set($bundesliga);

        $this->postJson('/api/auth/register', [
            'name' => 'Alice Zweitkonto',
            'email' => 'alice@x.com',
            'password' => 'other-pass-456',
            'password_confirmation' => 'other-pass-456',
        ])->assertCreated();

        $accounts = User::query()->where('email', 'alice@x.com')
            ->orderBy('mandant_id')
            ->get();

        $this->assertCount(2, $accounts);
        $this->assertSame(
            [$this->mandant->id, $bundesliga->id],
            $accounts->pluck('mandant_id')->all(),
        );

        // Each account carries its own role assignment in its own mandant and
        // its own activation state — they are fully independent identities.
        $this->assertSame('user', $accounts[0]->roleForMandant($this->mandant->id));
        $this->assertSame('user', $accounts[1]->roleForMandant($bundesliga->id));
        $this->assertNotSame($accounts[0]->activation_token, $accounts[1]->activation_token);
        $this->assertTrue(Hash::check('secret-pass-123', $accounts[0]->password));
        $this->assertTrue(Hash::check('other-pass-456', $accounts[1]->password));
    }

    public function test_register_rejects_case_variant_duplicates_within_a_mandant(): void
    {
        User::factory()->forMandant($this->mandant)->create(['email' => 'alice@x.com']);

        // The email is lowercased BEFORE validation, so a case variant must hit
        // the per-mandant unique rule instead of sneaking past it (both Postgres
        // and SQLite compare text case-sensitively).
        $this->postJson('/api/auth/register', [
            'name' => 'Alice Variante',
            'email' => 'ALICE@X.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_register_validates_required_fields_and_password_confirmation(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'password']);

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /* ---------------------------------------------------------------------
     | RV-S3: registrations must never shadow a GLOBAL account.
     |
     | The bootstrap super admin exists as a global account
     | (`users.mandant_id = null`, cf. `DatabaseSeeder::resolveAdmin()`).
     | Because `findLoginUser` prefers the current mandant's row, a
     | domain-local registration with the global account's email would lock
     | it out of its login on this domain — such registrations are rejected.
     -------------------------------------------------------------------- */

    private function createGlobalSuperAdmin(string $email): User
    {
        // Mirrors DatabaseSeeder::resolveAdmin(): global user (mandant_id
        // null is the factory default) + global super_admin role assignment.
        $admin = User::factory()->create([
            'email' => $email,
            'password' => 'bootstrap-secret',
        ]);
        $this->assertNull($admin->fresh()->mandant_id);

        RoleUser::create([
            'user_id' => $admin->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $admin;
    }

    public function test_register_rejects_the_email_of_a_global_account(): void
    {
        Mail::fake();

        $this->createGlobalSuperAdmin('admin@example.com');

        foreach (['admin@example.com', 'ADMIN@Example.com'] as $email) {
            $response = $this->postJson('/api/auth/register', [
                'name' => 'Max Mustermann',
                'email' => $email,
                'password' => 'secret-pass-123',
                'password_confirmation' => 'secret-pass-123',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('email');

            // Understandable, dedicated message — not the generic duplicate hint.
            $this->assertStringContainsString(
                'systemweites Konto',
                collect($response->json('errors.email'))->implode(' '),
            );
        }

        // No shadow account was created; only the global one remains.
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'mandant_id' => null]);

        Mail::assertNothingSent();
    }

    public function test_register_allows_the_email_of_another_mandants_account(): void
    {
        // RV-S3 regression: the new global-account guard must not tighten the
        // per-mandant semantics (BE-R1) — an address that already exists ONLY
        // on ANOTHER mandant's domain stays registrable on this domain.
        Mail::fake();

        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        User::factory()->forMandant($bundesliga)->create(['email' => 'alice@x.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Alice Lokal',
            'email' => 'alice@x.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertCreated()
            ->assertJsonPath('message', 'Registrierung erfolgreich. Bitte prüfe deine E-Mail zur Aktivierung.');

        $this->assertDatabaseHas('users', [
            'email' => 'alice@x.com',
            'mandant_id' => $this->mandant->id,
        ]);

        Mail::assertSent(ActivationMail::class);
    }

    public function test_global_account_still_logs_in_after_a_rejected_shadow_registration(): void
    {
        // RV-S3 end-to-end: the guard rejects the shadowing attempt, so the
        // global account keeps resolving (and logging in) exactly as before.
        $admin = $this->createGlobalSuperAdmin('chief@example.com');

        $this->postJson('/api/auth/register', [
            'name' => 'Shadow Attempt',
            'email' => 'chief@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        MandantContext::set($this->mandant);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'chief@example.com',
            'password' => 'bootstrap-secret',
        ])->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));

        $token = $login->getCookie(config('jwt.cookie_key_name'), false)->getValue();

        $this->withCookie(config('jwt.cookie_key_name'), $token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);
    }
}

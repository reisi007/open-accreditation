<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Mail\ActivationMail;
use App\Models\Mandant;
use App\Models\MandantDomain;
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
}

<?php

namespace Tests\Feature;

use App\Mail\ActivationMail;
use App\Models\Mandant;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);

        $user = User::where('email', 'max@example.com')->firstOrFail();

        $this->assertNotNull($user->activation_token);
        $this->assertNotNull($user->activation_token_expires_at);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertNull($user->email_verified_at);

        // The new account is scoped to the current mandant only.
        $this->assertSame('user', $user->roleForMandant($this->mandant->id));

        Mail::assertSent(ActivationMail::class, function (ActivationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->activationUrl, $user->activation_token);
        });
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

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'taken@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
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

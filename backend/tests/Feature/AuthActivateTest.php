<?php

namespace Tests\Feature;

use App\Mail\ActivationMail;
use App\Models\Mandant;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthActivateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_activation_sets_email_verified_at_and_consumes_the_token(): void
    {
        $rawToken = Str::random(64);

        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => hash('sha256', $rawToken),
            'activation_token_expires_at' => now()->addHours(24),
        ]);

        $this->getJson('/api/auth/activate/'.$rawToken)
            ->assertOk()
            ->assertJsonPath('message', 'Konto erfolgreich aktiviert. Du kannst dich jetzt anmelden.');

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->activation_token);
        $this->assertNull($user->activation_token_expires_at);
    }

    public function test_activation_rejects_an_unknown_token(): void
    {
        $this->getJson('/api/auth/activate/'.Str::random(64))
            ->assertNotFound();
    }

    public function test_activation_rejects_an_expired_token(): void
    {
        $rawToken = Str::random(64);

        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => hash('sha256', $rawToken),
            'activation_token_expires_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/auth/activate/'.$rawToken)
            ->assertStatus(410);

        $user->refresh();

        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->activation_token);
    }

    public function test_activation_cannot_be_used_twice(): void
    {
        $rawToken = Str::random(64);

        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => hash('sha256', $rawToken),
            'activation_token_expires_at' => now()->addHours(24),
        ]);

        $this->getJson('/api/auth/activate/'.$rawToken)->assertOk();

        $this->getJson('/api/auth/activate/'.$rawToken)
            ->assertNotFound();
    }

    public function test_full_register_activate_flow_enables_login(): void
    {
        $rawToken = Str::random(64);

        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => hash('sha256', $rawToken),
            'activation_token_expires_at' => now()->addHours(24),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);

        $this->getJson('/api/auth/activate/'.$rawToken)->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));
    }

    /* ---------------------------------------------------------------------
     | F4: the users table stores ONLY the sha256 digest of the activation
     | token — never the raw token — while the mail URL carries the raw token.
     | ------------------------------------------------------------------- */

    public function test_register_stores_sha256_digest_not_the_raw_token(): void
    {
        Mail::fake();

        // register() requires a current mandant and the seeded roles.
        $this->seed(RoleSeeder::class);
        $mandant = Mandant::factory()->create(['slug' => 'verband']);
        MandantContext::set($mandant);

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertCreated();

        $user = User::where('email', 'max@example.com')->firstOrFail();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $user->activation_token);

        // The stored value must be the sha256 of the RAW token that the mail
        // carries — extract it from the dispatched mail URL.
        Mail::assertSent(ActivationMail::class, function (ActivationMail $mail) use ($user) {
            $url = $mail->activationUrl;
            $rawToken = (string) substr($url, strrpos($url, '/') + 1);

            $this->assertSame(hash('sha256', $rawToken), $user->activation_token);
            $this->assertNotSame($rawToken, $user->activation_token);

            return true;
        });
    }
}

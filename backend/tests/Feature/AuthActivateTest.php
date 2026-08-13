<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthActivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_sets_email_verified_at_and_consumes_the_token(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => Str::random(64),
            'activation_token_expires_at' => now()->addHours(24),
        ]);

        $this->getJson('/api/auth/activate/'.$user->activation_token)
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
        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => Str::random(64),
            'activation_token_expires_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/auth/activate/'.$user->activation_token)
            ->assertStatus(410);

        $user->refresh();

        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->activation_token);
    }

    public function test_activation_cannot_be_used_twice(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => Str::random(64),
            'activation_token_expires_at' => now()->addHours(24),
        ]);

        $this->getJson('/api/auth/activate/'.$user->activation_token)->assertOk();

        $this->getJson('/api/auth/activate/'.$user->activation_token)
            ->assertNotFound();
    }

    public function test_full_register_activate_flow_enables_login(): void
    {
        $token = Str::random(64);

        $user = User::factory()->create([
            'email_verified_at' => null,
            'activation_token' => $token,
            'activation_token_expires_at' => now()->addHours(24),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);

        $this->getJson('/api/auth/activate/'.$token)->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_requires_authentication(): void
    {
        $this->putJson('/api/user/profile', ['city' => 'Berlin'])->assertStatus(401);
    }

    public function test_profile_update_persists_valid_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->putJson('/api/user/profile', [
                'title' => 'Dr.',
                'gender' => 'diverse',
                'birth_date' => '1990-05-04',
                'street' => 'Musterstraße 1',
                'zip' => '10115',
                'city' => 'Berlin',
                'country' => 'Deutschland',
                'company' => 'Muster Verlag',
                'phone' => '+49 30 123456',
                'fax' => '+49 30 123457',
                'branch' => 'photo',
                'position' => 'Chefredakteurin',
                'vest_available' => true,
                'vest_number' => 'V-123',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Profil aktualisiert.')
            ->assertJsonPath('data.city', 'Berlin')
            ->assertJsonPath('data.branch', 'photo')
            ->assertJsonPath('data.vest_available', true);

        $user->refresh();

        $this->assertSame('Dr.', $user->title);
        $this->assertSame('1990-05-04', $user->birth_date?->format('Y-m-d'));
        $this->assertSame('photo', $user->branch);
        $this->assertTrue($user->vest_available);
        $this->assertSame('V-123', $user->vest_number);
    }

    public function test_profile_update_rejects_invalid_branch(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->putJson('/api/user/profile', ['branch' => 'funk'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch');
    }

    public function test_profile_update_rejects_future_birth_date(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->putJson('/api/user/profile', ['birth_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_profile_update_only_touches_the_authenticated_user(): void
    {
        $victim = User::factory()->create(['city' => 'Hamburg']);
        $attacker = User::factory()->create(['city' => null]);

        $this->actingAsApi($attacker)
            ->putJson('/api/user/profile', ['city' => 'München'])
            ->assertOk();

        $this->assertSame('München', $attacker->fresh()->city);
        $this->assertSame('Hamburg', $victim->fresh()->city);
    }
}

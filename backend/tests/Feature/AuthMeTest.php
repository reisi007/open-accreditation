<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_core_fields_roles_and_media(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAsApi($admin)
            ->getJson('/api/auth/me')
            ->assertOk();

        $response->assertJsonPath('data.id', $admin->id);
        $response->assertJsonPath('data.email', 'admin@example.com');
        $response->assertJsonPath('data.roles.0.slug', 'super_admin');
        $response->assertJsonPath('data.roles.0.mandant_id', null);
        $response->assertJsonPath('data.media', []);
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.activation_token');
    }

    public function test_me_returns_mandant_scoped_roles(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband']);

        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'verifier')->firstOrCreate(
            ['slug' => 'verifier'],
            ['name' => 'Verifier'],
        );

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        $this->actingAsApi($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0.slug', 'verifier')
            ->assertJsonPath('data.roles.0.mandant_id', $mandant->id);
    }
}

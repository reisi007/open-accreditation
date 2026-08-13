<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_assigns_super_admin_role_to_the_admin_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertDatabaseHas('role_user', [
            'user_id' => $admin->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);
    }

    public function test_role_seeder_creates_all_five_roles_idempotently(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 5);
        foreach (['super_admin', 'mandant_admin', 'team_admin', 'user', 'verifier'] as $slug) {
            $this->assertDatabaseHas('roles', ['slug' => $slug]);
        }
    }

    public function test_user_of_mandant_a_is_not_assigned_to_mandant_b(): void
    {
        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);

        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'user')->firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'User'],
        );

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandantA->id,
            'team_id' => null,
        ]);

        $this->assertSame('user', $user->roleForMandant($mandantA->id));
        $this->assertNull($user->roleForMandant($mandantB->id));

        $this->assertTrue($user->hasRole('user', $mandantA->id));
        $this->assertFalse($user->hasRole('user', $mandantB->id));
    }

    public function test_mandant_and_team_admin_helpers_respect_their_scope(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband']);

        $admin = User::factory()->create();
        $verifier = User::factory()->create();
        $teamAdmin = User::factory()->create();

        $mandantAdminRole = Role::query()->where('slug', 'mandant_admin')->firstOrCreate(
            ['slug' => 'mandant_admin'],
            ['name' => 'Mandant Admin'],
        );
        $verifierRole = Role::query()->where('slug', 'verifier')->firstOrCreate(
            ['slug' => 'verifier'],
            ['name' => 'Verifier'],
        );
        $teamAdminRole = Role::query()->where('slug', 'team_admin')->firstOrCreate(
            ['slug' => 'team_admin'],
            ['name' => 'Team Admin'],
        );

        RoleUser::create(['user_id' => $admin->id, 'role_id' => $mandantAdminRole->id, 'mandant_id' => $mandant->id]);
        RoleUser::create(['user_id' => $verifier->id, 'role_id' => $verifierRole->id, 'mandant_id' => $mandant->id]);
        RoleUser::create(['user_id' => $teamAdmin->id, 'role_id' => $teamAdminRole->id, 'mandant_id' => $mandant->id, 'team_id' => 7]);

        $this->assertTrue($admin->isMandantAdmin($mandant->id));
        $this->assertFalse($admin->isMandantAdmin($mandant->id + 999));

        $this->assertTrue($verifier->isVerifier($mandant->id));
        $this->assertFalse($verifier->isVerifier($mandant->id + 999));

        $this->assertTrue($teamAdmin->isTeamAdmin(7));
        $this->assertFalse($teamAdmin->isTeamAdmin(8));
    }
}

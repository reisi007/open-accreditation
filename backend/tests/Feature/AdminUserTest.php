<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * P2c Admin API — user management + role assignment with union semantics
 * (P1d-F2).
 *
 * `GET /api/admin/users` lists the users of the current mandant (scoped to
 * role_user assignments, filters: search/role/team_id).
 * `PUT /api/admin/users/{user}/roles` replaces the user's mandant role set.
 *
 * Multiple roles per (user, mandant) are allowed; the granted permissions are
 * the UNION over all assignments. Cross-mandant assignments stay isolated and
 * the global super_admin assignment (mandant_id = null) is never touched.
 */
class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    private Team $foreignTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->teamB = $this->mandantA->teams()->create(['name' => 'Team B', 'slug' => 'team-b']);
        $this->foreignTeam = $this->mandantB->teams()->create(['name' => 'Fremd', 'slug' => 'fremd']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Access
     | ------------------------------------------------------------------- */

    public function test_users_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);

        $user = User::factory()->create();
        $this->putJson('/api/admin/users/'.$user->id.'/roles', ['roles' => [['role' => 'user']]])
            ->assertStatus(401);
    }

    public function test_team_admin_user_and_verifier_are_forbidden(): void
    {
        foreach ([UserRole::TEAM_ADMIN, UserRole::USER, UserRole::VERIFIER] as $role) {
            $actor = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($actor)->getJson('/api/admin/users')
                ->assertStatus(403, "expected 403 for {$role->value} on users index");

            $target = User::factory()->create();
            $this->actingAsApi($actor)
                ->putJson('/api/admin/users/'.$target->id.'/roles', ['roles' => [['role' => 'user']]])
                ->assertStatus(403, "expected 403 for {$role->value} on roles update");
        }
    }

    public function test_mandant_admin_and_super_admin_can_access_users_api(): void
    {
        $this->actingAsApi($this->mandantAdmin())->getJson('/api/admin/users')->assertOk();
        $this->actingAsApi($this->superAdmin())->getJson('/api/admin/users')->assertOk();
    }

    /* ---------------------------------------------------------------------
     | List
     | ------------------------------------------------------------------- */

    public function test_index_lists_only_users_with_assignment_in_current_mandant(): void
    {
        $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id, null, 'user-a@example.com');
        $this->createUserWithRole(UserRole::USER->value, $this->mandantB->id, null, 'user-b@example.com');
        User::factory()->create(['name' => 'Rollenlos']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'user-a@example.com');
    }

    public function test_global_super_admin_never_appears_in_user_list(): void
    {
        $this->createGlobalSuperAdmin();

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_isolation_between_mandants(): void
    {
        $this->createUserWithRole(UserRole::USER->value, $this->mandantB->id, null, 'fremd@example.com');

        MandantContext::set($this->mandantB);
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'fremd@example.com');

        MandantContext::set($this->mandantA);
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_search_filter_matches_name_and_email(): void
    {
        $alice = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id, null, 'alice@example.com');
        $alice->update(['name' => 'Alice Beispiel']);
        $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id, null, 'bob@example.com')
            ->update(['name' => 'Bob Beispiel']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users?search=alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'alice@example.com');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users?search=Beispiel')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_role_filter_filters_by_assignment_slug(): void
    {
        $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id, null, 'user@example.com');
        $this->createUserWithRole(UserRole::VERIFIER->value, $this->mandantA->id, null, 'verifier@example.com');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users?role=verifier')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'verifier@example.com');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users?role=user')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'user@example.com');
    }

    public function test_index_team_id_filter_filters_by_assignment_team(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id, 'team@example.com');
        $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamB->id, 'team-b@example.com');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teamAdmin->id);
    }

    public function test_user_roles_payload_contains_role_and_team_info(): void
    {
        $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id, 'team@example.com');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('data.0.roles.0.role.slug', 'team_admin')
            ->assertJsonPath('data.0.roles.0.role.name', 'Team Admin')
            ->assertJsonPath('data.0.roles.0.mandant_id', $this->mandantA->id)
            ->assertJsonPath('data.0.roles.0.team_id', $this->teamA->id)
            ->assertJsonPath('data.0.roles.0.team.id', $this->teamA->id)
            ->assertJsonPath('data.0.roles.0.team.name', 'Team A');
    }

    /* ---------------------------------------------------------------------
     | Role replacement
     | ------------------------------------------------------------------- */

    public function test_update_roles_replaces_the_mandant_role_set(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [
                    ['role' => 'team_admin', 'team_id' => $this->teamA->id],
                    ['role' => 'user'],
                ],
            ])
            ->assertOk();

        $this->assertSame(2, RoleUser::query()->where('user_id', $user->id)->where('mandant_id', $this->mandantA->id)->count());
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'mandant_id' => $this->mandantA->id,
            'team_id' => $this->teamA->id,
        ]);
        $this->assertSame(0, RoleUser::query()->where('user_id', $user->id)->where('mandant_id', $this->mandantB->id)->count());
    }

    public function test_update_roles_returns_the_fresh_roles_payload(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [
                    ['role' => 'team_admin', 'team_id' => $this->teamA->id],
                    ['role' => 'user'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.role.slug', 'team_admin')
            ->assertJsonPath('data.0.role.name', 'Team Admin')
            ->assertJsonPath('data.0.team_id', $this->teamA->id)
            ->assertJsonPath('data.0.team.id', $this->teamA->id)
            ->assertJsonPath('data.1.role.slug', 'user')
            ->assertJsonPath('data.1.team', null);
    }

    public function test_update_roles_never_touches_global_super_admin_assignment(): void
    {
        $superAdmin = $this->createGlobalSuperAdmin();
        RoleUser::create([
            'user_id' => $superAdmin->id,
            'role_id' => Role::query()->where('slug', 'user')->value('id'),
            'mandant_id' => $this->mandantA->id,
        ]);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$superAdmin->id.'/roles', [
                'roles' => [['role' => 'verifier']],
            ])
            ->assertOk();

        // The global assignment (mandant_id null) survives untouched.
        $this->assertDatabaseHas('role_user', [
            'user_id' => $superAdmin->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
            'mandant_id' => null,
            'team_id' => null,
        ]);

        // The mandant-scoped rows were replaced by exactly the new set.
        $this->assertSame(1, RoleUser::query()->where('user_id', $superAdmin->id)->where('mandant_id', $this->mandantA->id)->count());
        $this->assertDatabaseHas('role_user', ['user_id' => $superAdmin->id, 'mandant_id' => $this->mandantA->id]);
    }

    public function test_update_roles_unknown_user_is_404(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/999999/roles', ['roles' => [['role' => 'user']]])
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Role validation
     | ------------------------------------------------------------------- */

    public function test_update_roles_rejects_unknown_role_slug(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [['role' => 'hacker']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles.0.role');

        $this->assertSame(1, RoleUser::query()->where('user_id', $user->id)->where('mandant_id', $this->mandantA->id)->count());
    }

    public function test_update_roles_rejects_super_admin_in_payload(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [['role' => 'super_admin']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles.0.role');
    }

    public function test_update_roles_requires_team_id_for_team_admin(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [['role' => 'team_admin']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->assertSame(1, RoleUser::query()->where('user_id', $user->id)->count());
    }

    public function test_update_roles_rejects_team_of_foreign_mandant(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [['role' => 'team_admin', 'team_id' => $this->foreignTeam->id]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_update_roles_rejects_team_id_for_non_team_admin_roles(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [['role' => 'user', 'team_id' => $this->teamA->id]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->assertSame(1, RoleUser::query()->where('user_id', $user->id)->where('mandant_id', $this->mandantA->id)->count());
    }

    public function test_update_roles_rejects_duplicate_assignments(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [
                    ['role' => 'user'],
                    ['role' => 'user'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_update_roles_allows_multiple_roles_per_user(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [
                    ['role' => 'user'],
                    ['role' => 'team_admin', 'team_id' => $this->teamA->id],
                ],
            ])
            ->assertOk();

        $this->assertSame(2, RoleUser::query()->where('user_id', $user->id)->where('mandant_id', $this->mandantA->id)->count());
    }

    /* ---------------------------------------------------------------------
     | Union semantics (P1d-F2)
     | ------------------------------------------------------------------- */

    public function test_union_grant_permissions_from_any_assignment(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', 'team_admin')->value('id'),
            'mandant_id' => $this->mandantA->id,
            'team_id' => $this->teamA->id,
        ]);

        MandantContext::set($this->mandantA);

        // user-role grants accreditations.self, team_admin grants events.manage
        // — the union of both assignments applies.
        $this->assertTrue($user->hasPermission('accreditations.self'));
        $this->assertTrue($user->hasPermission('events.manage'));
        $this->assertFalse($user->hasPermission('verification.verify'));
        $this->assertTrue(Gate::forUser($user)->allows('events.manage'));

        // The union exposes the team_admin assignment's team scope.
        $assignments = $user->roleAssignmentsForMandant($this->mandantA->id);
        $this->assertSame(2, $assignments->count());
        $this->assertSame(
            [$this->teamA->id],
            $assignments
                ->filter(fn (RoleUser $a) => $a->role->slug === 'team_admin')
                ->map(fn (RoleUser $a) => (int) $a->team_id)
                ->values()
                ->all(),
        );
    }

    public function test_union_permissions_stay_within_the_assigned_mandant(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', 'team_admin')->value('id'),
            'mandant_id' => $this->mandantA->id,
            'team_id' => $this->teamA->id,
        ]);
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', 'user')->value('id'),
            'mandant_id' => $this->mandantB->id,
        ]);

        // In mandant A the union grants team_admin powers…
        MandantContext::set($this->mandantA);
        $this->assertTrue($user->hasPermission('accreditations.self'));
        $this->assertTrue($user->hasPermission('events.manage'));

        // …in mandant B only the user-role permissions apply (no events.manage).
        MandantContext::set($this->mandantB);
        $this->assertTrue($user->hasPermission('accreditations.self'));
        $this->assertFalse($user->hasPermission('events.manage'));
    }

    public function test_union_user_with_team_admin_role_can_access_team_scoped_admin_routes(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        // Promote to [user, team_admin@teamA] via the API (union replacement).
        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/users/'.$user->id.'/roles', [
                'roles' => [
                    ['role' => 'user'],
                    ['role' => 'team_admin', 'team_id' => $this->teamA->id],
                ],
            ])
            ->assertOk();

        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Eigenes']);
        $foreignEvent = $this->mandantA->events()->create(['team_id' => $this->teamB->id, 'title' => 'Fremdes']);

        // team_admin powers from the union: own-team events only.
        $this->actingAsApi($user)
            ->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Eigenes');

        // The union does not grant cross-team write access.
        $this->actingAsApi($user)
            ->putJson('/api/admin/events/'.$foreignEvent->id, ['title' => 'Hack'])
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------------
     | P2b follow-up fixes (F1/F3/F4) — regression coverage
     | ------------------------------------------------------------------- */

    public function test_p2b_f1_mandant_admin_lists_teams_but_cannot_write(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', ['name' => 'Hack', 'slug' => 'hack'])
            ->assertStatus(403);
    }

    public function test_p2b_f1_team_admin_lists_only_own_teams_and_cannot_write(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', ['name' => 'Hack', 'slug' => 'hack'])
            ->assertStatus(403);
    }

    public function test_p2b_f1_team_admin_cannot_read_teams_of_another_mandant(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        // URL mandant B ≠ current context A → 404 (no cross-mandant leak).
        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/mandants/'.$this->mandantB->id.'/teams')
            ->assertStatus(404);
    }

    public function test_p2b_f3_deadline_end_equal_to_start_is_allowed(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Gleich',
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-15',
            ])
            ->assertStatus(201);

        $event = $this->mandantA->events()->create(['title' => 'Update Gleich']);
        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/events/'.$event->id, [
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.deadline_end', '2026-08-15');
    }

    public function test_p2b_f4_team_id_filter_returns_effective_category_set(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Override', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Spezial', 'slug' => 'spezial']);

        $this->actingAsApi($this->mandantAdmin())
            ->getJson('/api/admin/categories?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'presse')
            ->assertJsonPath('data.0.name', 'Override')
            ->assertJsonPath('data.1.slug', 'spezial');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function mandantAdmin(): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id);
    }

    private function createGlobalSuperAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null, ?string $email = null): User
    {
        $user = User::factory()->create($email === null ? [] : ['email' => $email]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandantId,
            'team_id' => $teamId,
        ]);

        return $user;
    }
}

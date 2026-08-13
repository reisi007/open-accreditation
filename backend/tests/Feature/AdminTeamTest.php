<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P2a Super Admin API — teams (Vereine) of a mandant.
 *
 * Slug uniqueness is scoped per mandant; teams of other mandants are never
 * reachable through a mandant's team endpoints (404, no cross-mandant leak).
 */
class AdminTeamTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        // Teams are an opt-in feature per mandant. Most team tests operate on
        // a mandant with the feature enabled; the disabled cases are tested
        // explicitly below.
        $this->mandantA->update(['teams_enabled' => true]);
        $this->mandantB->update(['teams_enabled' => true]);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_team_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')->assertStatus(401);
        $this->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [])->assertStatus(401);
    }

    public function test_team_write_endpoints_require_super_admin(): void
    {
        $roles = [UserRole::MANDANT_ADMIN, UserRole::TEAM_ADMIN, UserRole::USER];

        foreach ($roles as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [
                    'name' => 'Hack',
                    'slug' => 'hack',
                ])
                ->assertStatus(403, "expected 403 for {$role->value} on teams store");
        }
    }

    public function test_team_write_update_and_delete_require_super_admin(): void
    {
        $team = $this->mandantA->teams()->create(['name' => 'FC Ziel', 'slug' => 'fc-ziel']);

        foreach ([UserRole::MANDANT_ADMIN, UserRole::TEAM_ADMIN, UserRole::USER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id, ['name' => 'Hack'])
                ->assertStatus(403, "expected 403 for {$role->value} on teams update");

            $this->actingAsApi($user)
                ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id)
                ->assertStatus(403, "expected 403 for {$role->value} on teams delete");
        }
    }

    public function test_team_index_is_readable_by_mandant_admin(): void
    {
        $this->mandantA->teams()->create(['name' => 'ZSKA Verband', 'slug' => 'zska']);
        $this->mandantB->teams()->create(['name' => 'Fremder', 'slug' => 'fremder']);

        $this->actingAsApi($this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id))
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ZSKA Verband');
    }

    public function test_team_index_for_team_admin_is_scoped_to_own_team(): void
    {
        $teamA = $this->mandantA->teams()->create(['name' => 'Eigenes', 'slug' => 'eigenes']);
        $this->mandantA->teams()->create(['name' => 'Fremdes Team', 'slug' => 'fremdes-team']);

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Eigenes')
            ->assertJsonPath('data.0.id', $teamA->id);
    }

    public function test_team_index_is_forbidden_for_plain_users(): void
    {
        $user = $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id);

        $this->actingAsApi($user)
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertStatus(403);
    }

    public function test_super_admin_can_access_team_endpoints(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_can_create_team(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [
                'name' => 'FC Musterhausen',
                'slug' => 'fc-musterhausen',
                'home_venue' => 'Musterstadion',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'FC Musterhausen')
            ->assertJsonPath('data.slug', 'fc-musterhausen')
            ->assertJsonPath('data.home_venue', 'Musterstadion')
            ->assertJsonPath('data.mandant_id', $this->mandantA->id);

        $this->assertDatabaseHas('teams', [
            'mandant_id' => $this->mandantA->id,
            'slug' => 'fc-musterhausen',
            'name' => 'FC Musterhausen',
        ]);
    }

    public function test_cannot_create_team_when_teams_disabled(): void
    {
        $this->mandantA->update(['teams_enabled' => false]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [
                'name' => 'FC Gesperrt',
                'slug' => 'fc-gesperrt',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('teams', ['slug' => 'fc-gesperrt']);
    }

    public function test_cannot_update_team_when_teams_disabled(): void
    {
        $team = $this->mandantA->teams()->create(['name' => 'FC Alt', 'slug' => 'fc-alt']);
        $this->mandantA->update(['teams_enabled' => false]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id, [
                'name' => 'FC Neu',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'FC Alt']);
    }

    public function test_can_list_teams_ordered_by_name(): void
    {
        $this->mandantA->teams()->create(['name' => 'ZSKA Verband', 'slug' => 'zska']);
        $this->mandantA->teams()->create(['name' => 'Borussia', 'slug' => 'borussia']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Borussia')
            ->assertJsonPath('data.1.name', 'ZSKA Verband');
    }

    public function test_team_slug_is_unique_within_a_mandant(): void
    {
        $this->mandantA->teams()->create(['name' => 'Erster', 'slug' => 'gleicher-slug']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [
                'name' => 'Zweiter',
                'slug' => 'gleicher-slug',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_same_team_slug_is_allowed_for_another_mandant(): void
    {
        $this->mandantA->teams()->create(['name' => 'Erster', 'slug' => 'gleicher-slug']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantB->id.'/teams', [
                'name' => 'Anderer Verband',
                'slug' => 'gleicher-slug',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('teams', [
            'mandant_id' => $this->mandantB->id,
            'slug' => 'gleicher-slug',
        ]);
    }

    public static function invalidSlugProvider(): array
    {
        return [
            'uppercase' => ['FC Test'],
            'underscore' => ['fc_test'],
            'leading dash' => ['-fc'],
            'double dash' => ['fc--test'],
            'trailing dash' => ['fc-test-'],
        ];
    }

    #[DataProvider('invalidSlugProvider')]
    public function test_rejects_invalid_team_slugs(string $slug): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [
                'name' => 'X',
                'slug' => $slug,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_can_update_team_partially(): void
    {
        $team = $this->mandantA->teams()->create(['name' => 'FC Alt', 'slug' => 'fc-alt']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id, [
                'name' => 'FC Neu',
                'home_venue' => 'Neues Stadion',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'FC Neu')
            ->assertJsonPath('data.home_venue', 'Neues Stadion')
            ->assertJsonPath('data.slug', 'fc-alt');

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'FC Neu',
            'home_venue' => 'Neues Stadion',
        ]);
    }

    public function test_can_delete_team(): void
    {
        $team = $this->mandantA->teams()->create(['name' => 'FC Weg', 'slug' => 'fc-weg']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_team_of_foreign_mandant_is_not_reachable(): void
    {
        $team = $this->mandantB->teams()->create(['name' => 'FC Fremd', 'slug' => 'fc-fremd']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id, [
                'name' => 'Hacked',
            ])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'FC Fremd']);
    }

    public function test_team_index_does_not_include_teams_of_other_mandants(): void
    {
        $this->mandantA->teams()->create(['name' => 'Eigener', 'slug' => 'eigener']);
        $this->mandantB->teams()->create(['name' => 'Fremder', 'slug' => 'fremder']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Eigener');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null): User
    {
        $user = User::factory()->create();
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

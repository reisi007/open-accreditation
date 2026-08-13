<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3b Admin API — accreditations (Akkreditierungen) of the current mandant.
 *
 * Guarded by `can:accreditations.manage` (super_admin, mandant_admin,
 * team_admin). team_admin is locked to his own team's accreditations; a
 * payload `team_id` of another team is forced back onto his team, a `?team_id`
 * param must match one of his teams (403). Cross-field validation: the
 * category must be mandant-level or own team, `event_id` is required for
 * `scope=event` and forbidden otherwise.
 */
class AdminAccreditationTest extends TestCase
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

    public function test_accreditation_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/accreditations')->assertStatus(401);
        $this->postJson('/api/admin/accreditations', [])->assertStatus(401);

        $accreditation = $this->createAccreditation(['quota' => 5]);
        $this->putJson('/api/admin/accreditations/'.$accreditation->id, [])->assertStatus(401);
        $this->deleteJson('/api/admin/accreditations/'.$accreditation->id)->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/accreditations')
                ->assertStatus(403, "expected 403 for {$role->value} on accreditations index");

            $this->actingAsApi($user)->postJson('/api/admin/accreditations', ['quota' => 5])
                ->assertStatus(403, "expected 403 for {$role->value} on accreditations store");
        }
    }

    public function test_super_admin_can_create_event_scope_accreditation(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $event = $this->mandantA->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'event',
                'event_id' => $event->id,
                'quota' => 20,
                'deadline_start' => '2026-08-01',
                'deadline_end' => '2026-08-15',
                'auto_approve' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.category.name', 'Presse')
            ->assertJsonPath('data.scope', 'event')
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.event.title', 'Finale')
            ->assertJsonPath('data.event.date', '2026-09-01')
            ->assertJsonPath('data.quota', 20)
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.available', 20)
            ->assertJsonPath('data.deadline_start', '2026-08-01')
            ->assertJsonPath('data.deadline_end', '2026-08-15')
            ->assertJsonPath('data.auto_approve', true)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.team_id', null)
            ->assertJsonPath('data.team', null);

        $this->assertDatabaseHas('accreditations', [
            'mandant_id' => $this->mandantA->id,
            'category_id' => $category->id,
            'event_id' => $event->id,
            'scope' => 'event',
            'quota' => 20,
            'active' => true,
        ]);
    }

    public function test_super_admin_can_create_league_and_season_scope(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Spieler', 'slug' => 'spieler']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'league',
                'quota' => 10,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.scope', 'league')
            ->assertJsonPath('data.event_id', null)
            ->assertJsonPath('data.event', null);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 10,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.scope', 'season');
    }

    public function test_mandant_admin_can_create_team_level_accreditation(): void
    {
        $category = $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Betreuer', 'slug' => 'betreuer']);

        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'team_id' => $this->teamA->id,
                'quota' => 8,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->teamA->id)
            ->assertJsonPath('data.team.name', 'Team A');
    }

    public function test_team_admin_foreign_team_id_is_forced_back_to_own_team(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $category = $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Betreuer', 'slug' => 'betreuer']);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'team_id' => $this->teamB->id,
                'quota' => 5,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->teamA->id);

        $this->assertDatabaseHas('accreditations', ['team_id' => $this->teamA->id]);
    }

    public function test_cannot_create_for_foreign_mandant_team(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'team_id' => $this->foreignTeam->id,
                'quota' => 5,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('accreditations', ['team_id' => $this->foreignTeam->id]);
    }

    public function test_category_must_belong_to_current_mandant(): void
    {
        $foreignCategory = $this->mandantB->categories()->create(['name' => 'Fremd', 'slug' => 'fremd']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $foreignCategory->id,
                'scope' => 'season',
                'quota' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_team_admin_cannot_use_other_teams_category(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $categoryB = $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'Fremdes Team', 'slug' => 'fremdes-team']);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations', [
                'category_id' => $categoryB->id,
                'scope' => 'season',
                'quota' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_team_admin_can_use_mandant_level_category(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 5,
            ])
            ->assertStatus(201);
    }

    public function test_event_scope_requires_event_id_and_event_of_mandant(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'event',
                'quota' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');

        $foreignEvent = $this->mandantB->events()->create(['title' => 'Fremdes Spiel']);
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'event',
                'event_id' => $foreignEvent->id,
                'quota' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_league_season_scope_rejects_event_id(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $event = $this->mandantA->events()->create(['title' => 'Finale']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'league',
                'event_id' => $event->id,
                'quota' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_quota_validation_and_scope_values(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quota');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'turnier',
                'quota' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', ['category_id' => $category->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope', 'quota']);
    }

    public function test_deadline_end_must_follow_deadline_start(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 5,
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_end');

        // Equal dates are a valid single-day window.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations', [
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 5,
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-15',
            ])
            ->assertStatus(201);
    }

    public function test_super_admin_can_list_all_and_filter_by_team_and_active(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $this->mandantA->accreditations()->create(['team_id' => $this->teamA->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $this->mandantA->accreditations()->create(['team_id' => $this->teamB->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5, 'active' => false]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.team.id', $this->teamA->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.team.id', $this->teamB->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations?active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_super_admin_index_with_foreign_team_is_404(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations?team_id='.$this->foreignTeam->id)
            ->assertStatus(404);
    }

    public function test_team_admin_index_only_shows_own_team_accreditations(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->accreditations()->create(['team_id' => $this->teamA->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $this->mandantA->accreditations()->create(['team_id' => $this->teamB->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.team.id', $this->teamA->id);
    }

    public function test_team_admin_index_with_foreign_team_param_is_403(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/accreditations?team_id='.$this->teamB->id)
            ->assertStatus(403);
    }

    public function test_team_admin_index_with_own_team_param_works(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->accreditations()->create(['team_id' => $this->teamA->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/accreditations?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_super_admin_can_update_partially(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5, 'active' => true]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/accreditations/'.$accreditation->id, [
                'quota' => 30,
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.quota', 30)
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('accreditations', ['id' => $accreditation->id, 'quota' => 30, 'active' => false]);
    }

    public function test_switch_from_event_scope_clears_event_id(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $event = $this->mandantA->events()->create(['title' => 'Finale']);
        $accreditation = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'event', 'event_id' => $event->id, 'quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/accreditations/'.$accreditation->id, ['scope' => 'league'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'league')
            ->assertJsonPath('data.event_id', null);

        $this->assertDatabaseHas('accreditations', ['id' => $accreditation->id, 'scope' => 'league', 'event_id' => null]);
    }

    public function test_team_admin_can_update_own_team_accreditation(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['team_id' => $this->teamA->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/accreditations/'.$accreditation->id, ['quota' => 9])
            ->assertOk()
            ->assertJsonPath('data.quota', 9)
            ->assertJsonPath('data.team_id', $this->teamA->id);
    }

    public function test_team_admin_cannot_update_or_delete_other_team_accreditation(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['team_id' => $this->teamB->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/accreditations/'.$accreditation->id, ['quota' => 1])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/accreditations/'.$accreditation->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('accreditations', ['id' => $accreditation->id, 'quota' => 5]);
    }

    public function test_team_admin_cannot_update_or_delete_verband_level_accreditation(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/accreditations/'.$accreditation->id, ['quota' => 1])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/accreditations/'.$accreditation->id)
            ->assertStatus(403);
    }

    public function test_super_admin_can_delete_accreditation(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/accreditations/'.$accreditation->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('accreditations', ['id' => $accreditation->id]);
    }

    public function test_accreditation_of_foreign_mandant_is_not_reachable(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/accreditations/'.$accreditation->id, ['quota' => 1])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/accreditations/'.$accreditation->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('accreditations', ['id' => $accreditation->id, 'quota' => 5]);
    }

    public function test_applications_count_and_available_reflect_applications(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 3]);

        Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'requested',
        ]);
        Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'approved',
        ]);
        Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'denied',
        ]);
        Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'blacklisted',
        ]);

        // All statuses count, so quota 3 − 4 applications → available = -1
        // (overbooking is visible to the admin already).
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.applications_count', 4)
            ->assertJsonPath('data.0.available', -1);
    }

    public function test_team_delete_cascades_accreditations(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->accreditations()->create(['team_id' => $this->teamA->id, 'category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);

        $this->teamA->delete();

        $this->assertDatabaseMissing('accreditations', ['team_id' => $this->teamA->id]);
    }

    public function test_deleting_accreditation_cascades_applications(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $application = Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'requested',
        ]);

        $accreditation->delete();

        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
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

    private static int $categorySeq = 0;

    private function createAccreditation(array $attributes): Accreditation
    {
        $category = $this->mandantA->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandantA->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            ...$attributes,
        ]);
    }
}

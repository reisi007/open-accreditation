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
use Tests\TestCase;

/**
 * P2b Admin API — events (Events/Spiele) of the current mandant.
 *
 * Events are mandant-level (`team_id` null) or team-level. super_admin /
 * mandant_admin manage both; team_admin only his own team's events (mandant-
 * level events are out of his scope entirely). A `?team_id` param from a
 * team_admin must match his own team, otherwise 403.
 */
class AdminEventTest extends TestCase
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

    public function test_event_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/events')->assertStatus(401);
        $this->postJson('/api/admin/events', [])->assertStatus(401);

        $event = $this->mandantA->events()->create(['title' => 'Spieltag 1']);
        $this->putJson('/api/admin/events/'.$event->id, [])->assertStatus(401);
        $this->deleteJson('/api/admin/events/'.$event->id)->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/events')
                ->assertStatus(403, "expected 403 for {$role->value} on events index");

            $this->actingAsApi($user)->postJson('/api/admin/events', ['title' => 'Hack'])
                ->assertStatus(403, "expected 403 for {$role->value} on events store");
        }
    }

    public function test_super_admin_can_create_event(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Finale',
                'date' => '2026-09-01',
                'venue' => 'Olympiastadion',
                'competition' => 'Pokal',
                'deadline_start' => '2026-08-01',
                'deadline_end' => '2026-08-15',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Finale')
            ->assertJsonPath('data.date', '2026-09-01')
            ->assertJsonPath('data.venue', 'Olympiastadion')
            ->assertJsonPath('data.competition', 'Pokal')
            ->assertJsonPath('data.deadline_start', '2026-08-01')
            ->assertJsonPath('data.deadline_end', '2026-08-15')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.team_id', null)
            ->assertJsonPath('data.team', null);

        $this->assertDatabaseHas('events', [
            'mandant_id' => $this->mandantA->id,
            'title' => 'Finale',
            'active' => true,
        ]);
    }

    public function test_mandant_admin_can_create_team_event(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Derby',
                'team_id' => $this->teamA->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->teamA->id)
            ->assertJsonPath('data.team.name', 'Team A');
    }

    public function test_team_admin_can_create_event_for_own_team_with_team_id_forced(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/events', [
                'title' => 'Heimspiel',
                'team_id' => $this->teamB->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->teamA->id);

        $this->assertDatabaseHas('events', ['title' => 'Heimspiel', 'team_id' => $this->teamA->id]);
    }

    public function test_cannot_create_event_for_foreign_mandant_team(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Hack',
                'team_id' => $this->foreignTeam->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('events', ['title' => 'Hack']);
    }

    public function test_super_admin_can_list_all_events_and_filter_by_team(): void
    {
        $this->mandantA->events()->create(['title' => 'Verbandsevent']);
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Team A Event']);
        $this->mandantA->events()->create(['team_id' => $this->teamB->id, 'title' => 'Team B Event']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Team A Event')
            ->assertJsonPath('data.0.team.id', $this->teamA->id);
    }

    public function test_super_admin_index_with_foreign_team_is_404(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events?team_id='.$this->foreignTeam->id)
            ->assertStatus(404);
    }

    public function test_active_filter(): void
    {
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Aktiv', 'active' => true]);
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Inaktiv', 'active' => false]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events?active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Aktiv');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Inaktiv');
    }

    public function test_team_admin_index_only_shows_own_team_events(): void
    {
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Eigenes']);
        $this->mandantA->events()->create(['team_id' => $this->teamB->id, 'title' => 'Fremdes']);
        $this->mandantA->events()->create(['title' => 'Verbandsevent']);

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Eigenes');
    }

    public function test_team_admin_index_with_own_team_param_works(): void
    {
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Eigenes']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/events?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_team_admin_index_with_foreign_team_param_is_403(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/events?team_id='.$this->teamB->id)
            ->assertStatus(403);
    }

    public function test_super_admin_can_update_event_partially(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Alt', 'active' => true]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, [
                'title' => 'Neu',
                'venue' => 'Stadion',
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Neu')
            ->assertJsonPath('data.venue', 'Stadion')
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => 'Neu', 'active' => false]);
    }

    public function test_team_admin_can_update_own_team_event(): void
    {
        $event = $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Heimspiel']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/events/'.$event->id, ['title' => 'Auswärtsspiel'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Auswärtsspiel')
            ->assertJsonPath('data.team_id', $this->teamA->id);
    }

    public function test_team_admin_cannot_update_or_delete_other_team_event(): void
    {
        $event = $this->mandantA->events()->create(['team_id' => $this->teamB->id, 'title' => 'Fremdes']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/events/'.$event->id, ['title' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/events/'.$event->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => 'Fremdes']);
    }

    public function test_team_admin_cannot_update_or_delete_verband_level_event(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Verbandsevent']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/events/'.$event->id, ['title' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/events/'.$event->id)
            ->assertStatus(403);
    }

    public function test_super_admin_can_delete_event(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Weg']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/events/'.$event->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_event_of_foreign_mandant_is_not_reachable(): void
    {
        $event = $this->mandantB->events()->create(['title' => 'Fremd']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['title' => 'Hack'])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/events/'.$event->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => 'Fremd']);
    }

    public function test_deadline_end_must_follow_deadline_start(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Ungültig',
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_end');

        $event = $this->mandantA->events()->create(['title' => 'Update Ziel']);
        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, [
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_end');

        $this->assertDatabaseMissing('events', ['title' => 'Ungültig']);
    }

    public function test_deadline_end_equal_to_deadline_start_is_allowed(): void
    {
        // P2b-F3: a single-day registration window (equal dates) is valid —
        // `after_or_equal` instead of `after`.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Gleich',
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-15',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.deadline_start', '2026-08-15')
            ->assertJsonPath('data.deadline_end', '2026-08-15');

        $event = $this->mandantA->events()->create(['title' => 'Update Gleich']);
        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, [
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.deadline_start', '2026-08-15')
            ->assertJsonPath('data.deadline_end', '2026-08-15');
    }

    public function test_deadline_end_without_deadline_start_is_allowed(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Nur Ende',
                'deadline_end' => '2026-08-15',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.deadline_end', '2026-08-15');
    }

    public function test_partial_update_rejects_deadline_end_before_stored_deadline_start(): void
    {
        $event = $this->mandantA->events()->create([
            'title' => 'Ziel',
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['deadline_end' => '2026-08-05'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_end');

        $this->assertSame('2026-08-20', $event->fresh()->deadline_end?->toDateString());
    }

    public function test_partial_update_rejects_deadline_start_after_stored_deadline_end(): void
    {
        $event = $this->mandantA->events()->create([
            'title' => 'Ziel',
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['deadline_start' => '2026-08-25'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_start');

        $this->assertSame('2026-08-10', $event->fresh()->deadline_start?->toDateString());
    }

    public function test_partial_update_of_a_single_valid_deadline_still_works(): void
    {
        $event = $this->mandantA->events()->create([
            'title' => 'Ziel',
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['deadline_end' => '2026-08-22'])
            ->assertOk()
            ->assertJsonPath('data.deadline_end', '2026-08-22')
            ->assertJsonPath('data.deadline_start', '2026-08-10');

        // Reverse direction: only deadline_start moved, still within range.
        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['deadline_start' => '2026-08-12'])
            ->assertOk()
            ->assertJsonPath('data.deadline_start', '2026-08-12')
            ->assertJsonPath('data.deadline_end', '2026-08-22');
    }

    public function test_update_can_clear_both_deadlines(): void
    {
        $event = $this->mandantA->events()->create([
            'title' => 'Ziel',
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, [
                'deadline_start' => null,
                'deadline_end' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.deadline_start', null)
            ->assertJsonPath('data.deadline_end', null);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'deadline_start' => null, 'deadline_end' => null]);
    }

    public function test_event_date_validation(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'X',
                'date' => 'kein-datum',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_event_title_required_and_max_length(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', ['title' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_venue_and_competition_are_strings_max_255(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'X',
                'venue' => ['nope'],
                'competition' => ['nope'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['venue', 'competition']);
    }

    public function test_team_delete_cascades_team_categories_and_events(): void
    {
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Heimspiel']);

        $this->teamA->delete();

        $this->assertDatabaseMissing('categories', ['team_id' => $this->teamA->id]);
        $this->assertDatabaseMissing('events', ['team_id' => $this->teamA->id]);
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
}

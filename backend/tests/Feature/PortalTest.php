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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P3a Public portal API — auth-free, always scoped to the current mandant
 * (MandantContext).
 *
 * `GET /api/portal/overview`         mandant + teams (teams only when
 *                                    `teams_enabled` and mandant active)
 * `GET /api/portal/events`           active events, date ASC; `team_id`
 *                                    (foreign → 422), `competition` filters
 * `GET /api/portal/events/{id}`      active event detail (venue_effective,
 *                                    deadline_effective, contact)
 * `GET /api/portal/mandant/logo|header`  public inline media delivery
 *
 * Every request runs unauthenticated — the portal must never demand a login.
 */
class PortalTest extends TestCase
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
        Storage::fake('private');

        $this->mandantA = Mandant::factory()->create([
            'slug' => 'verband-a',
            'name' => 'Verband A',
            'teams_enabled' => true,
            'impressum_text' => 'Impressum A',
            'privacy_text' => 'Datenschutz A',
        ]);
        $this->mandantB = Mandant::factory()->create([
            'slug' => 'verband-b',
            'name' => 'Verband B',
            'teams_enabled' => true,
            'impressum_text' => 'Impressum B',
        ]);

        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a', 'home_venue' => 'Heim A']);
        $this->teamB = $this->mandantA->teams()->create(['name' => 'Team B', 'slug' => 'team-b', 'home_venue' => 'Heim B']);
        $this->foreignTeam = $this->mandantB->teams()->create(['name' => 'Fremd', 'slug' => 'fremd']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Overview
     | ------------------------------------------------------------------- */

    public function test_overview_is_public_and_returns_mandant_and_teams(): void
    {
        $this->getJson('/api/portal/overview')
            ->assertOk()
            ->assertJsonPath('data.mandant.id', $this->mandantA->id)
            ->assertJsonPath('data.mandant.slug', 'verband-a')
            ->assertJsonPath('data.mandant.name', 'Verband A')
            ->assertJsonPath('data.mandant.impressum_text', 'Impressum A')
            ->assertJsonPath('data.mandant.privacy_text', 'Datenschutz A')
            ->assertJsonPath('data.mandant.teams_enabled', true)
            ->assertJsonCount(2, 'data.teams')
            ->assertJsonPath('data.teams.0.id', $this->teamA->id)
            ->assertJsonPath('data.teams.0.name', 'Team A')
            ->assertJsonPath('data.teams.0.home_venue', 'Heim A')
            ->assertJsonPath('data.teams.1.id', $this->teamB->id)
            ->assertJsonPath('data.teams.1.name', 'Team B')
            ->assertJsonPath('data.teams.1.home_venue', 'Heim B');
    }

    public function test_overview_hides_teams_when_teams_enabled_is_false(): void
    {
        $this->mandantA->update(['teams_enabled' => false]);

        $this->getJson('/api/portal/overview')
            ->assertOk()
            ->assertJsonPath('data.mandant.teams_enabled', false)
            ->assertJsonCount(0, 'data.teams');
    }

    public function test_overview_is_scoped_to_the_current_mandant(): void
    {
        MandantContext::set($this->mandantB);

        $this->getJson('/api/portal/overview')
            ->assertOk()
            ->assertJsonPath('data.mandant.id', $this->mandantB->id)
            ->assertJsonPath('data.mandant.slug', 'verband-b')
            ->assertJsonPath('data.mandant.name', 'Verband B')
            ->assertJsonPath('data.mandant.impressum_text', 'Impressum B')
            ->assertJsonCount(1, 'data.teams')
            ->assertJsonPath('data.teams.0.id', $this->foreignTeam->id);
    }

    public function test_overview_without_mandant_context_is_404(): void
    {
        MandantContext::set(null);

        $this->getJson('/api/portal/overview')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Public media delivery
     | ------------------------------------------------------------------- */

    public function test_portal_logo_and_header_are_public_with_content_type(): void
    {
        $this->getJson('/api/portal/mandant/logo')->assertStatus(404);
        $this->getJson('/api/portal/mandant/header')->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/header', [
                'file' => UploadedFile::fake()->image('header.png'),
            ])
            ->assertOk();

        $this->get('/api/portal/mandant/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->get('/api/portal/mandant/header')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_portal_media_is_scoped_to_the_current_mandant(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        // Mandant B has no logo → its public delivery is 404, not A's file.
        MandantContext::set($this->mandantB);
        $this->getJson('/api/portal/mandant/logo')->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Events (calendar)
     | ------------------------------------------------------------------- */

    public function test_events_lists_only_active_events_ordered_by_date_asc(): void
    {
        $this->mandantA->events()->create(['title' => 'Spaet', 'date' => '2026-09-01', 'venue' => 'Stadion']);
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Frueh', 'date' => '2026-08-01']);
        $this->mandantA->events()->create(['title' => 'Inaktiv', 'date' => '2026-07-01', 'active' => false]);

        $this->getJson('/api/portal/events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Frueh')
            ->assertJsonPath('data.0.date', '2026-08-01')
            ->assertJsonPath('data.0.team_id', $this->teamA->id)
            ->assertJsonPath('data.0.team.id', $this->teamA->id)
            ->assertJsonPath('data.0.team.name', 'Team A')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.1.title', 'Spaet')
            ->assertJsonPath('data.1.date', '2026-09-01')
            ->assertJsonPath('data.1.venue', 'Stadion')
            ->assertJsonPath('data.1.team', null);
    }

    public function test_events_team_id_filter(): void
    {
        $this->mandantA->events()->create(['team_id' => $this->teamA->id, 'title' => 'Team A Event']);
        $this->mandantA->events()->create(['team_id' => $this->teamB->id, 'title' => 'Team B Event']);
        $this->mandantA->events()->create(['title' => 'Verbandsevent']);

        $this->getJson('/api/portal/events?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Team A Event');
    }

    public function test_events_team_id_filter_rejects_foreign_team(): void
    {
        $this->getJson('/api/portal/events?team_id='.$this->foreignTeam->id)
            ->assertStatus(422)
            ->assertJsonMissing(['errors']);

        $this->getJson('/api/portal/events?team_id=999999')
            ->assertStatus(422)
            ->assertJsonMissing(['errors']);
    }

    public function test_events_competition_filter_is_partial_and_escaped(): void
    {
        $this->mandantA->events()->create(['title' => 'Pokal', 'competition' => 'Pokal Finale']);
        $this->mandantA->events()->create(['title' => 'Liga', 'competition' => 'Liga']);
        $this->mandantA->events()->create(['title' => 'Prozent', 'competition' => '100% Chance']);

        $this->getJson('/api/portal/events?competition=okal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Pokal');

        // CC-R1: case-mismatched search must still match — Postgres LIKE is
        // case-sensitive by default, SQLite is not; LOWER() pins the portable
        // contract on both engines.
        $this->getJson('/api/portal/events?competition=OKAL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Pokal');

        // Literal `%` is escaped: the search only matches events containing
        // a real percent sign, never acting as a wildcard.
        $this->getJson('/api/portal/events?competition='.urlencode('%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Prozent');
    }

    public function test_events_are_scoped_to_the_current_mandant(): void
    {
        $this->mandantA->events()->create(['title' => 'Eigenes']);
        $this->mandantB->events()->create(['title' => 'Fremdes']);

        $this->getJson('/api/portal/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Eigenes');

        MandantContext::set($this->mandantB);
        $this->getJson('/api/portal/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Fremdes');
    }

    /* ---------------------------------------------------------------------
     | Event detail
     | ------------------------------------------------------------------- */

    public function test_event_show_is_public_with_effective_fields_and_team_admin_contact(): void
    {
        $contact = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id, 'leiter@example.com');
        $contact->update(['name' => 'Team Leiter']);

        $event = $this->mandantA->events()->create([
            'team_id' => $this->teamA->id,
            'title' => 'Derby',
            'date' => '2026-09-01',
            'venue' => 'Heimstadion',
            'competition' => 'Pokal',
            'deadline_start' => '2026-08-01',
            'deadline_end' => '2026-08-15',
        ]);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.title', 'Derby')
            ->assertJsonPath('data.date', '2026-09-01')
            ->assertJsonPath('data.team_id', $this->teamA->id)
            ->assertJsonPath('data.team.id', $this->teamA->id)
            ->assertJsonPath('data.team.name', 'Team A')
            // Explicit venue wins over the team's home venue.
            ->assertJsonPath('data.venue_effective', 'Heimstadion')
            // deadline_end is the countdown reference.
            ->assertJsonPath('data.deadline_effective', '2026-08-15')
            ->assertJsonPath('data.contact.name', 'Team Leiter')
            ->assertJsonPath('data.contact.email', 'leiter@example.com');
    }

    public function test_event_show_venue_falls_back_to_team_home_venue(): void
    {
        $event = $this->mandantA->events()->create([
            'team_id' => $this->teamA->id,
            'title' => 'Ohne Ort',
        ]);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.venue', null)
            ->assertJsonPath('data.venue_effective', 'Heim A');
    }

    public function test_event_show_deadline_falls_back_to_start(): void
    {
        $event = $this->mandantA->events()->create([
            'title' => 'Nur Start',
            'deadline_start' => '2026-08-01',
        ]);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.deadline_end', null)
            ->assertJsonPath('data.deadline_effective', '2026-08-01');

        $none = $this->mandantA->events()->create(['title' => 'Ohne Frist']);
        $this->getJson('/api/portal/events/'.$none->id)
            ->assertOk()
            ->assertJsonPath('data.deadline_effective', null);
    }

    public function test_event_show_contact_falls_back_to_mandant_admin(): void
    {
        $admin = $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id, null, 'verband@example.com');
        $admin->update(['name' => 'Verbands Admin']);

        $event = $this->mandantA->events()->create(['title' => 'Verbandsevent']);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.contact.name', 'Verbands Admin')
            ->assertJsonPath('data.contact.email', 'verband@example.com');
    }

    public function test_event_show_contact_is_null_without_any_admin(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Ohne Verwalter']);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertOk()
            ->assertJsonPath('data.contact', null);
    }

    public function test_event_show_inactive_event_is_404(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Inaktiv', 'active' => false]);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertStatus(404);
    }

    public function test_event_show_of_foreign_mandant_is_404(): void
    {
        $event = $this->mandantB->events()->create(['title' => 'Fremd']);

        $this->getJson('/api/portal/events/'.$event->id)
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
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

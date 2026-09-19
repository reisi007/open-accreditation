<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Services\MediaPathService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W4 admin API — team logos (Vereins-Logos) and cardinality-free event
 * participants (Teilnehmer: Single / Versus / Turnier).
 *
 * Team logos land on the public `media` disk under the W1 layout
 * (`<host>/teams/<slug>/logo.<ext>` via `MediaPathService::teamFile`) and are
 * delivered auth-gated. Writes are hierarchical (W4-F1): super_admin globally,
 * mandant_admin for every team of his mandant, team_admin for his own team(s);
 * user/verifier are denied, foreigners get 403/404.
 *
 * Participants are mandant-level content: a team_admin may read but every
 * write is 403 (mirrors W2 event types). Rows of a foreign mandant or of a
 * sibling event are 404 (parent-event scoped binding + explicit event check).
 * A participant references a team of the current mandant (foreign → 404) or
 * carries a free-text `name`.
 */
class AdminTeamParticipationTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Storage::fake(MediaPathService::DISK);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);
        $this->mandantB->domains()->create(['hostname' => 'verband-b.test']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Team logo — authorization
     | ------------------------------------------------------------------- */

    public function test_team_logo_endpoints_require_authentication(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->getJson('/api/admin/teams/'.$team->id.'/logo')->assertStatus(401);
        $this->post('/api/admin/teams/'.$team->id.'/logo', [])->assertStatus(401);
        $this->deleteJson('/api/admin/teams/'.$team->id.'/logo')->assertStatus(401);
    }

    public function test_all_viewers_can_read_team_logo(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        foreach ([$this->mandantAdmin(), $this->teamAdmin($team)] as $reader) {
            $this->actingAsApi($reader)
                ->getJson('/api/admin/teams/'.$team->id.'/logo')
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png');
        }
    }

    public function test_mandant_admin_manages_team_logos_of_his_mandant(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->mandantAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_url', route('api.admin.teams.logo', ['team' => $team->id]));

        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-a/logo.png');

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/teams/'.$team->id.'/logo')
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/team-a/logo.png');
        $this->assertNull($team->fresh()->logo_path);
    }

    public function test_team_admin_manages_only_his_own_team_logo(): void
    {
        $own = $this->makeTeam($this->mandantA, 'eigen');
        $sibling = $this->makeTeam($this->mandantA, 'nachbar');
        $teamAdmin = $this->teamAdmin($own);

        $this->actingAsApi($teamAdmin)
            ->post('/api/admin/teams/'.$own->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/eigen/logo.png');

        $this->actingAsApi($teamAdmin)
            ->post('/api/admin/teams/'.$sibling->id.'/logo', [
                'file' => UploadedFile::fake()->image('fremd.png'),
            ])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/teams/'.$sibling->id.'/logo')
            ->assertStatus(403);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/nachbar/logo.png');
        $this->assertNull($sibling->fresh()->logo_path);
    }

    public function test_mandant_admin_cannot_manage_team_logo_of_foreign_mandant(): void
    {
        $teamB = $this->makeTeam($this->mandantB, 'fremd');
        $mandantAdminA = $this->mandantAdmin();

        // Foreign team via the mandant-scoped binding → 404.
        $this->actingAsApi($mandantAdminA)
            ->post('/api/admin/teams/'.$teamB->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(404);

        // No mandant_admin role in the current (foreign) context → 403.
        MandantContext::set($this->mandantB);

        $this->actingAsApi($mandantAdminA)
            ->post('/api/admin/teams/'.$teamB->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(403);

        $this->assertNull($teamB->fresh()->logo_path);
    }

    public function test_team_admin_cannot_manage_team_logo_of_foreign_mandant(): void
    {
        $teamA = $this->makeTeam($this->mandantA, 'team-a');
        $teamB = $this->makeTeam($this->mandantB, 'fremd');
        $teamAdminA = $this->teamAdmin($teamA);

        MandantContext::set($this->mandantB);

        $this->actingAsApi($teamAdminA)
            ->post('/api/admin/teams/'.$teamB->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(403);

        $this->assertNull($teamB->fresh()->logo_path);
    }

    public function test_user_and_verifier_cannot_manage_team_logo(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $actors = [
            $this->createUserWithRole(UserRole::USER->value, $this->mandantA->id),
            $this->createUserWithRole(UserRole::VERIFIER->value, $this->mandantA->id),
        ];

        foreach ($actors as $actor) {
            $this->actingAsApi($actor)
                ->post('/api/admin/teams/'.$team->id.'/logo', [
                    'file' => UploadedFile::fake()->image('logo.png'),
                ])
                ->assertStatus(403);

            $this->actingAsApi($actor)
                ->deleteJson('/api/admin/teams/'.$team->id.'/logo')
                ->assertStatus(403);
        }

        $this->assertNull($team->fresh()->logo_path);
    }

    /* ---------------------------------------------------------------------
     | Team logo — media lifecycle
     | ------------------------------------------------------------------- */

    public function test_super_admin_uploads_team_logo_in_domain_layout(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_url', route('api.admin.teams.logo', ['team' => $team->id]));

        $expected = 'verband-a.test/teams/team-a/logo.png';
        Storage::disk(MediaPathService::DISK)->assertExists($expected);
        $this->assertSame($expected, $team->fresh()->logo_path);
    }

    public function test_team_logo_rejects_invalid_file_type(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->create('logo.txt', 100, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/teams/team-a/logo.txt');
    }

    public function test_team_logo_rejects_oversized_file(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('huge.png')->size(3000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_team_logo_rejects_oversized_dimensions(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('big.png', 2001, 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/teams/team-a/logo.png');
    }

    public function test_replacing_team_logo_deletes_the_previous_file(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo-a.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo-b.jpg'),
            ])
            ->assertOk();

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/teams/team-a/logo.png');
        Storage::disk(MediaPathService::DISK)
            ->assertExists('verband-a.test/teams/team-a/logo.jpg');

        $this->assertSame(
            'verband-a.test/teams/team-a/logo.jpg',
            $team->fresh()->logo_path,
        );
    }

    public function test_delete_team_logo_removes_file_and_path(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/teams/'.$team->id.'/logo')
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/teams/team-a/logo.png');
        $this->assertNull($team->fresh()->logo_path);
    }

    public function test_team_logo_delivery_returns_404_without_file(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/teams/'.$team->id.'/logo')
            ->assertStatus(404);
    }

    public function test_team_logo_of_foreign_mandant_is_404(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/teams/'.$team->id.'/logo')
            ->assertStatus(404);

        $this->assertNull($team->fresh()->logo_path);
    }

    public function test_team_logo_uses_host_neutral_path_without_domain(): void
    {
        $mandantC = Mandant::factory()->create(['slug' => 'verband-c', 'name' => 'Verband C']);
        MandantContext::set($mandantC);
        $team = $this->makeTeam($mandantC, 'cup');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$mandantC->id.'/teams/cup/logo.png');
        $this->assertSame('_tenants/'.$mandantC->id.'/teams/cup/logo.png', $team->fresh()->logo_path);
    }

    public function test_team_resource_exposes_logo_url(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonPath('data.0.logo_url', null);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonPath('data.0.logo_url', route('api.admin.teams.logo', ['team' => $team->id]));
    }

    public function test_slug_change_moves_the_logo_file(): void
    {
        $this->mandantA->update(['teams_enabled' => true]);
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id, [
                'slug' => 'team-neu',
            ])
            ->assertOk();

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/teams/team-a/logo.png');
        Storage::disk(MediaPathService::DISK)
            ->assertExists('verband-a.test/teams/team-neu/logo.png');
        $this->assertSame(
            'verband-a.test/teams/team-neu/logo.png',
            $team->fresh()->logo_path,
        );
    }

    public function test_deleting_team_removes_logo_file(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/teams/'.$team->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id)
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/teams/team-a/logo.png');
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    /* ---------------------------------------------------------------------
     | Participants — authorization
     | ------------------------------------------------------------------- */

    public function test_participant_endpoints_require_authentication(): void
    {
        $event = $this->makeEvent($this->mandantA);
        $participant = $event->participants()->create(['name' => 'Gast', 'sort_order' => 0]);

        $this->getJson('/api/admin/events/'.$event->id.'/participants')->assertStatus(401);
        $this->postJson('/api/admin/events/'.$event->id.'/participants', ['name' => 'X'])->assertStatus(401);
        $this->putJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id, ['name' => 'X'])->assertStatus(401);
        $this->deleteJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id)->assertStatus(401);
    }

    public function test_team_admin_can_read_but_not_write_participants(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');
        $event = $this->makeEvent($this->mandantA);
        $participant = $event->participants()->create(['team_id' => $team->id, 'sort_order' => 0]);
        $teamAdmin = $this->teamAdmin($team);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/events/'.$event->id.'/participants')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['name' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id, ['name' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('event_participants', ['id' => $participant->id, 'name' => null]);
    }

    /* ---------------------------------------------------------------------
     | Participants — Single / Versus / Turnier
     | ------------------------------------------------------------------- */

    public function test_single_team_participant_resolves_name_from_team(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a', 'Team Alpha');
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $team->id])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.name_effective', 'Team Alpha')
            ->assertJsonPath('data.team.name', 'Team Alpha')
            ->assertJsonPath('data.sort_order', 1);

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'team_id' => $team->id,
        ]);
    }

    public function test_versus_two_teams(): void
    {
        $home = $this->makeTeam($this->mandantA, 'heim', 'Heimverein');
        $away = $this->makeTeam($this->mandantA, 'gast', 'Gastverein');
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $home->id])
            ->assertStatus(201)
            ->assertJsonPath('data.name_effective', 'Heimverein');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $away->id])
            ->assertStatus(201)
            ->assertJsonPath('data.name_effective', 'Gastverein');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events/'.$event->id.'/participants')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name_effective', 'Heimverein')
            ->assertJsonPath('data.1.name_effective', 'Gastverein');
    }

    public function test_tournament_four_teams_share_one_event(): void
    {
        $event = $this->makeEvent($this->mandantA);

        for ($i = 1; $i <= 4; $i++) {
            $team = $this->makeTeam($this->mandantA, 'gruppe-'.$i, 'Gruppe '.$i);

            $this->actingAsApi($this->superAdmin())
                ->postJson('/api/admin/events/'.$event->id.'/participants', [
                    'team_id' => $team->id,
                    'sort_order' => $i * 10,
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.sort_order', $i * 10);
        }

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events/'.$event->id.'/participants')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.team.name', 'Gruppe 1')
            ->assertJsonPath('data.3.team.name', 'Gruppe 4');
    }

    public function test_name_override_wins_over_team_name(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a', 'Offizieller Name');
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', [
                'team_id' => $team->id,
                'name' => 'Kurzname',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Kurzname')
            ->assertJsonPath('data.name_effective', 'Kurzname');
    }

    public function test_placeholder_participant_with_name_only(): void
    {
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['name' => 'Sieger Spiel A'])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', null)
            ->assertJsonPath('data.name_effective', 'Sieger Spiel A')
            ->assertJsonPath('data.team', null);
    }

    public function test_placeholder_participants_with_null_team_may_coexist(): void
    {
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['name' => 'Platzhalter A'])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['name' => 'Platzhalter B'])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events/'.$event->id.'/participants')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_participant_requires_team_or_name(): void
    {
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'team_id']);
    }

    public function test_foreign_mandant_team_is_404(): void
    {
        $foreignTeam = $this->makeTeam($this->mandantB, 'fremd');
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $foreignTeam->id])
            ->assertStatus(404);

        $this->assertDatabaseMissing('event_participants', ['team_id' => $foreignTeam->id]);
    }

    public function test_foreign_mandant_event_is_404(): void
    {
        $foreignEvent = $this->makeEvent($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$foreignEvent->id.'/participants', ['name' => 'Hack'])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events/'.$foreignEvent->id.'/participants')
            ->assertStatus(404);
    }

    public function test_participant_of_sibling_event_is_404(): void
    {
        $eventA = $this->makeEvent($this->mandantA, 'Spiel A');
        $eventB = $this->makeEvent($this->mandantA, 'Spiel B');
        $participantB = $eventB->participants()->create(['name' => 'B', 'sort_order' => 0]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$eventA->id.'/participants/'.$participantB->id, ['name' => 'Hack'])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/events/'.$eventA->id.'/participants/'.$participantB->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('event_participants', ['id' => $participantB->id, 'name' => 'B']);
    }

    public function test_cross_mandant_participant_binding_is_404(): void
    {
        $eventA = $this->makeEvent($this->mandantA);
        $eventB = $this->makeEvent($this->mandantB);
        $participantB = $eventB->participants()->create(['name' => 'B', 'sort_order' => 0]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$eventA->id.'/participants/'.$participantB->id, ['name' => 'Hack'])
            ->assertStatus(404);
    }

    public function test_duplicate_team_participant_is_422(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $team->id])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $team->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');
    }

    /* ---------------------------------------------------------------------
     | Participants — ordering + update/delete
     | ------------------------------------------------------------------- */

    public function test_sort_order_auto_increments(): void
    {
        $event = $this->makeEvent($this->mandantA);

        foreach ([0, 1, 2] as $index) {
            $team = $this->makeTeam($this->mandantA, 'team-'.$index, 'Team '.$index);

            $this->actingAsApi($this->superAdmin())
                ->postJson('/api/admin/events/'.$event->id.'/participants', ['team_id' => $team->id])
                ->assertStatus(201)
                ->assertJsonPath('data.sort_order', $index + 1);
        }
    }

    public function test_duplicate_sort_order_is_422(): void
    {
        $event = $this->makeEvent($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', [
                'name' => 'Erster',
                'sort_order' => 5,
            ])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events/'.$event->id.'/participants', [
                'name' => 'Zweiter',
                'sort_order' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_order');
    }

    public function test_index_orders_by_sort_order(): void
    {
        $event = $this->makeEvent($this->mandantA);

        $event->participants()->create(['name' => 'Drei', 'sort_order' => 30]);
        $event->participants()->create(['name' => 'Eins', 'sort_order' => 10]);
        $event->participants()->create(['name' => 'Zwei', 'sort_order' => 20]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/events/'.$event->id.'/participants')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name_effective', 'Eins')
            ->assertJsonPath('data.1.name_effective', 'Zwei')
            ->assertJsonPath('data.2.name_effective', 'Drei');
    }

    public function test_participant_update_supports_partial_and_team_switch(): void
    {
        $teamA = $this->makeTeam($this->mandantA, 'team-a', 'Team A');
        $teamB = $this->makeTeam($this->mandantA, 'team-b', 'Team B');
        $event = $this->makeEvent($this->mandantA);
        $participant = $event->participants()->create(['team_id' => $teamA->id, 'sort_order' => 0]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id, [
                'team_id' => $teamB->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.team_id', $teamB->id)
            ->assertJsonPath('data.name_effective', 'Team B');

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id, [
                'name' => 'Override',
            ])
            ->assertOk()
            ->assertJsonPath('data.team_id', $teamB->id)
            ->assertJsonPath('data.name_effective', 'Override');
    }

    public function test_participant_update_cannot_clear_both_team_and_name(): void
    {
        $team = $this->makeTeam($this->mandantA, 'team-a');
        $event = $this->makeEvent($this->mandantA);
        $participant = $event->participants()->create(['team_id' => $team->id, 'sort_order' => 0]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id, [
                'team_id' => null,
                'name' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_participant_can_be_deleted(): void
    {
        $event = $this->makeEvent($this->mandantA);
        $participant = $event->participants()->create(['name' => 'Weg damit', 'sort_order' => 0]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/events/'.$event->id.'/participants/'.$participant->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('event_participants', ['id' => $participant->id]);
    }

    public function test_event_participants_relation_is_ordered(): void
    {
        $event = $this->makeEvent($this->mandantA);
        $event->participants()->create(['name' => 'Zwei', 'sort_order' => 2]);
        $event->participants()->create(['name' => 'Eins', 'sort_order' => 1]);

        $this->assertSame(
            ['Eins', 'Zwei'],
            $event->participants()->pluck('name')->all(),
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function makeTeam(Mandant $mandant, string $slug, ?string $name = null): Team
    {
        return $mandant->teams()->create([
            'slug' => $slug,
            'name' => $name ?? ucfirst($slug),
        ]);
    }

    private function makeEvent(Mandant $mandant, string $title = 'Spieltag'): Event
    {
        return $mandant->events()->create(['title' => $title]);
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function mandantAdmin(): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id);
    }

    private function teamAdmin(Team $team): User
    {
        return $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $team->mandant_id, $team->id);
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

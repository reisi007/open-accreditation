<?php

namespace Tests\Feature;

use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P3b public accreditation API + apply + "Meine Akkreditierungen".
 *
 *   GET /api/accreditations          active, mandant-scoped, ordered by
 *                                    category name; `event_id` filter
 *   GET /api/accreditations/{id}     active detail; inactive/foreign → 404
 *   POST /api/accreditations/{id}/apply  requested application; deadline
 *                                    window, duplicate guard, quota NOT enforced
 *   GET /api/applications            own applications, newest first
 *   DELETE /api/applications/{id}    withdraw own requested application
 *
 * The public list/detail run unauthenticated; apply and applications need a
 * JWT cookie.
 */
class AccreditationTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Public list
     | ------------------------------------------------------------------- */

    public function test_list_is_public_and_orders_by_category_name(): void
    {
        $presse = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $spieler = $this->mandantA->categories()->create(['name' => 'Spieler', 'slug' => 'spieler']);
        $presse->accreditations()->create(['mandant_id' => $this->mandantA->id, 'scope' => 'season', 'quota' => 10]);
        $spieler->accreditations()->create(['mandant_id' => $this->mandantA->id, 'scope' => 'season', 'quota' => 20]);

        $this->getJson('/api/accreditations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category.name', 'Presse')
            ->assertJsonPath('data.0.quota', 10)
            ->assertJsonPath('data.0.applications_count', 0)
            ->assertJsonPath('data.0.available', 10)
            ->assertJsonPath('data.1.category.name', 'Spieler')
            ->assertJsonPath('data.1.quota', 20);
    }

    public function test_list_shows_only_active_accreditations(): void
    {
        $this->createAccreditation(['active' => true]);
        $this->createAccreditation(['active' => false]);

        $this->getJson('/api/accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.active', true);
    }

    public function test_list_is_scoped_to_the_current_mandant(): void
    {
        $this->createAccreditation(['quota' => 5]);
        $this->mandantB->accreditations()->create([
            'category_id' => $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse'])->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $this->getJson('/api/accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        MandantContext::set($this->mandantB);
        $this->getJson('/api/accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_list_event_id_filter(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale']);
        $this->createAccreditation(['quota' => 5]);
        $this->createAccreditation(['scope' => 'event', 'event_id' => $event->id, 'quota' => 5]);

        $this->getJson('/api/accreditations?event_id='.$event->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event.title', 'Finale');
    }

    public function test_list_event_id_filter_rejects_foreign_event(): void
    {
        $foreignEvent = $this->mandantB->events()->create(['title' => 'Fremd']);

        $this->getJson('/api/accreditations?event_id='.$foreignEvent->id)
            ->assertStatus(422)
            ->assertJsonMissing(['errors']);

        $this->getJson('/api/accreditations?event_id=999999')
            ->assertStatus(422)
            ->assertJsonMissing(['errors']);
    }

    public function test_list_without_mandant_context_is_404(): void
    {
        MandantContext::set(null);

        $this->getJson('/api/accreditations')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Public detail
     | ------------------------------------------------------------------- */

    public function test_show_is_public_with_nested_resources(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $event = $this->mandantA->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $this->mandantA->accreditations()->create([
            'team_id' => $this->teamA->id,
            'category_id' => $category->id,
            'scope' => 'event',
            'event_id' => $event->id,
            'quota' => 15,
            'deadline_start' => '2026-08-01',
            'deadline_end' => '2026-08-15',
        ]);

        $this->getJson('/api/accreditations/'.$accreditation->id)
            ->assertOk()
            ->assertJsonPath('data.id', $accreditation->id)
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.category.name', 'Presse')
            ->assertJsonPath('data.scope', 'event')
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.event.id', $event->id)
            ->assertJsonPath('data.event.title', 'Finale')
            ->assertJsonPath('data.event.date', '2026-09-01')
            ->assertJsonPath('data.team_id', $this->teamA->id)
            ->assertJsonPath('data.team.name', 'Team A')
            ->assertJsonPath('data.quota', 15)
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.available', 15)
            ->assertJsonPath('data.deadline_start', '2026-08-01')
            ->assertJsonPath('data.deadline_end', '2026-08-15')
            ->assertJsonPath('data.auto_approve', false)
            ->assertJsonPath('data.active', true);
    }

    public function test_show_without_event_and_team_serializes_nulls(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->getJson('/api/accreditations/'.$accreditation->id)
            ->assertOk()
            ->assertJsonPath('data.event', null)
            ->assertJsonPath('data.event_id', null)
            ->assertJsonPath('data.team', null)
            ->assertJsonPath('data.team_id', null);
    }

    public function test_show_inactive_accreditation_is_404(): void
    {
        $accreditation = $this->createAccreditation(['active' => false]);

        $this->getJson('/api/accreditations/'.$accreditation->id)
            ->assertStatus(404);
    }

    public function test_show_of_foreign_mandant_is_404(): void
    {
        $accreditation = $this->mandantB->accreditations()->create([
            'category_id' => $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse'])->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $this->getJson('/api/accreditations/'.$accreditation->id)
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Apply
     | ------------------------------------------------------------------- */

    public function test_apply_requires_authentication(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(401);
    }

    public function test_apply_creates_requested_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $user = $this->createUser();

        $response = $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply');

        $response->assertStatus(201)
            ->assertJsonPath('data.accreditation.id', $accreditation->id)
            ->assertJsonPath('data.accreditation.category.name', 'Presse')
            ->assertJsonPath('data.accreditation.scope', 'season')
            ->assertJsonPath('data.accreditation.quota', 5)
            // The own application already counts: quota 5 − 1 = 4 left.
            ->assertJsonPath('data.accreditation.available', 4)
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.priority', false)
            ->assertJsonPath('data.reason', null);

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
        ]);
    }

    public function test_apply_before_deadline_start_is_422(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00');

        $accreditation = $this->createAccreditation([
            'quota' => 5,
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Applications for this accreditation are not open yet.');

        $this->assertDatabaseMissing('applications', ['accreditation_id' => $accreditation->id]);

        Carbon::setTestNow();
    }

    public function test_apply_on_deadline_start_day_is_allowed(): void
    {
        Carbon::setTestNow('2026-08-10 00:00:00');

        $accreditation = $this->createAccreditation([
            'quota' => 5,
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201);

        Carbon::setTestNow();
    }

    public function test_apply_at_exact_deadline_end_is_allowed(): void
    {
        // The window ends with the day: 23:59:59 of deadline_end still counts.
        Carbon::setTestNow('2026-08-20 23:59:59');

        $accreditation = $this->createAccreditation([
            'quota' => 5,
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201);

        Carbon::setTestNow();
    }

    public function test_apply_after_deadline_end_is_422(): void
    {
        Carbon::setTestNow('2026-08-21 00:00:00');

        $accreditation = $this->createAccreditation([
            'quota' => 5,
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The application deadline for this accreditation has passed.');

        $this->assertDatabaseMissing('applications', ['accreditation_id' => $accreditation->id]);

        Carbon::setTestNow();
    }

    public function test_apply_without_deadlines_is_always_allowed(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201);
    }

    public function test_duplicate_apply_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $user = $this->createUser();

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201);

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have already applied for this accreditation.');

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_overbooking_is_allowed_when_quota_is_exhausted(): void
    {
        // quota=1, two different users apply → both succeed (P3c allocation
        // engine decides later, overbooking is legal at request time).
        $accreditation = $this->createAccreditation(['quota' => 1]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201);

        $this->assertDatabaseCount('applications', 2);
    }

    public function test_apply_to_inactive_accreditation_is_404(): void
    {
        $accreditation = $this->createAccreditation(['active' => false, 'quota' => 5]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(404);
    }

    public function test_apply_to_foreign_mandant_accreditation_is_404(): void
    {
        $accreditation = $this->mandantB->accreditations()->create([
            'category_id' => $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse'])->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $this->actingAsApi($this->createUser())
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(404);

        $this->assertDatabaseMissing('applications', ['accreditation_id' => $accreditation->id]);
    }

    public function test_apply_does_not_leak_across_mandants(): void
    {
        $accreditationA = $this->createAccreditation(['quota' => 5]);
        $accreditationB = $this->mandantB->accreditations()->create([
            'category_id' => $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse'])->id,
            'scope' => 'season',
            'quota' => 5,
        ]);
        $user = $this->createUser();

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditationA->id.'/apply')
            ->assertStatus(201);

        // The same user cannot apply for B's accreditation while A is current.
        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditationB->id.'/apply')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | My applications
     | ------------------------------------------------------------------- */

    public function test_applications_index_requires_authentication(): void
    {
        $this->getJson('/api/applications')->assertStatus(401);
    }

    public function test_applications_index_shows_own_applications_newest_first(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        $first = $this->createAccreditation(['quota' => 5]);
        $second = $this->createAccreditation(['quota' => 5]);
        $third = $this->createAccreditation(['quota' => 5]);

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$first->id.'/apply')
            ->assertStatus(201);
        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$second->id.'/apply')
            ->assertStatus(201);
        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$third->id.'/apply')
            ->assertStatus(201);
        // The other user's application must not appear.
        $this->actingAsApi($other)
            ->postJson('/api/accreditations/'.$second->id.'/apply')
            ->assertStatus(201);

        $this->actingAsApi($user)
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.accreditation.id', $third->id)
            ->assertJsonPath('data.1.accreditation.id', $second->id)
            ->assertJsonPath('data.2.accreditation.id', $first->id)
            ->assertJsonPath('data.0.status', 'requested')
            // The user's own application on that accreditation counts: 5 − 1.
            ->assertJsonPath('data.0.accreditation.available', 4);
    }

    public function test_applications_index_is_scoped_to_the_current_mandant(): void
    {
        $user = $this->createUser();
        $accreditationA = $this->createAccreditation(['quota' => 5]);
        $accreditationB = $this->mandantB->accreditations()->create([
            'category_id' => $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse'])->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        // Create both applications directly (same user, both mandants).
        Application::create(['accreditation_id' => $accreditationA->id, 'user_id' => $user->id, 'status' => 'requested']);
        Application::create(['accreditation_id' => $accreditationB->id, 'user_id' => $user->id, 'status' => 'requested']);

        $this->actingAsApi($user)
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.accreditation.id', $accreditationA->id);
    }

    public function test_withdraw_requires_authentication(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = Application::create(['accreditation_id' => $accreditation->id, 'user_id' => $this->createUser()->id, 'status' => 'requested']);

        $this->deleteJson('/api/applications/'.$application->id)
            ->assertStatus(401);
    }

    public function test_withdraw_own_requested_application_is_204(): void
    {
        $user = $this->createUser();
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = Application::create(['accreditation_id' => $accreditation->id, 'user_id' => $user->id, 'status' => 'requested']);

        $this->actingAsApi($user)
            ->deleteJson('/api/applications/'.$application->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
    }

    public function test_withdraw_foreign_application_is_404(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = Application::create(['accreditation_id' => $accreditation->id, 'user_id' => $other->id, 'status' => 'requested']);

        $this->actingAsApi($user)
            ->deleteJson('/api/applications/'.$application->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('applications', ['id' => $application->id]);
    }

    public function test_withdraw_approved_or_denied_application_is_422(): void
    {
        $user = $this->createUser();

        foreach (['approved', 'denied', 'blacklisted'] as $status) {
            // One distinct accreditation per status (the unique
            // accreditation×user pair forbids re-using the same one).
            $accreditation = $this->createAccreditation(['quota' => 5]);
            $application = Application::create([
                'accreditation_id' => $accreditation->id,
                'user_id' => $user->id,
                'status' => $status,
            ]);

            $this->actingAsApi($user)
                ->deleteJson('/api/applications/'.$application->id)
                ->assertStatus(422)
                ->assertJsonPath('message', 'Only pending (requested) applications can be withdrawn.');

            $this->assertDatabaseHas('applications', ['id' => $application->id]);
        }
    }

    public function test_available_reflects_other_applications_after_apply(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2]);
        $userA = $this->createUser();
        $userB = $this->createUser();

        $this->actingAsApi($userA)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201)
            // quota 2, the own application already counts → 1 left.
            ->assertJsonPath('data.accreditation.available', 1);

        $this->actingAsApi($userB)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply')
            ->assertStatus(201)
            ->assertJsonPath('data.accreditation.available', 0);

        $this->getJson('/api/accreditations/'.$accreditation->id)
            ->assertOk()
            ->assertJsonPath('data.applications_count', 2)
            ->assertJsonPath('data.available', 0);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private function createUser(): User
    {
        return User::factory()->create();
    }

    private function createAccreditation(array $attributes): Accreditation
    {
        $category = $this->mandantA->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandantA->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            ...$attributes,
        ]);
    }
}

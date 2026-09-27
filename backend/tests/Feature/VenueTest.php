<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Models\Venue;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * W12 venue master data (Spielstätten) — `/api/admin/venues`.
 *
 * The three decisions this file pins down:
 * 1. **One table, mandant-scoped.** Names are unique per mandant; two Verbände
 *    may use the same name. A *deactivated* name stays taken and is
 *    reactivated, never duplicated.
 * 2. **Deactivate, never delete, while referenced.** `is_active: false` keeps
 *    the row (and its name resolvable by every team/event pointing at it);
 *    DELETE answers 409 naming the reference counts, and the DB `restrict` on
 *    both FKs is the enforcement underneath the API-level guard.
 * 3. **Mandant isolation.** Every read and write is scoped to the current
 *    mandant, and a foreign venue id is 404 — never 403, never a leak.
 *
 * The permission gate mirrors `categories.manage`: a venue is mandant-wide
 * reference data (no team level exists to scope to), but the gate is held by
 * mandant_admin AND team_admin — the team_admin grant feeds the venue picker
 * and its inline create in the team and the event form, both of which he may
 * already edit (`teams.manage` / `events.manage`). `user` and `verifier` hold
 * no venue permission.
 */
class VenueTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A', 'teams_enabled' => true]);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B', 'teams_enabled' => true]);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Index
     | ------------------------------------------------------------------- */

    public function test_index_lists_only_the_venues_of_the_current_mandant_ordered_by_name(): void
    {
        $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->venues()->create(['name' => 'Alte Försterei']);
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $this->actingAsApi($this->mandantAdmin())
            ->getJson('/api/admin/venues')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alte Försterei')
            ->assertJsonPath('data.1.name', 'Zeppelin Arena');
    }

    public function test_index_carries_the_reference_counts_a_delete_would_destroy(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);
        $this->mandantA->teams()->create(['name' => 'FC B', 'slug' => 'fc-b', 'venue_id' => $venue->id]);
        $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $venue->id]);
        $unused = $this->mandantA->venues()->create(['name' => 'Arena']);

        $this->actingAsApi($this->mandantAdmin())
            ->getJson('/api/admin/venues')
            ->assertOk()
            // The index is ordered by name: "Arena" < "Zeppelin Arena".
            ->assertJsonPath('data.0.id', $unused->id)
            ->assertJsonPath('data.0.name', 'Arena')
            ->assertJsonPath('data.0.teams_count', 0)
            ->assertJsonPath('data.0.events_count', 0)
            ->assertJsonPath('data.1.id', $venue->id)
            ->assertJsonPath('data.1.teams_count', 2)
            ->assertJsonPath('data.1.events_count', 1)
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonPath('data.1.created_at', $venue->created_at?->toJSON())
            ->assertJsonPath('data.1.updated_at', $venue->updated_at?->toJSON());
    }

    public function test_index_includes_deactivated_venues(): void
    {
        $this->mandantA->venues()->create(['name' => 'Alte Halle', 'is_active' => false]);

        $this->actingAsApi($this->mandantAdmin())
            ->getJson('/api/admin/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }

    /* ---------------------------------------------------------------------
     | Store
     | ------------------------------------------------------------------- */

    public function test_can_create_a_venue(): void
    {
        $response = $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/venues', ['name' => 'Zeppelin Arena']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Zeppelin Arena')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.teams_count', 0)
            ->assertJsonPath('data.events_count', 0);

        $this->assertDatabaseHas('venues', [
            'mandant_id' => $this->mandantA->id,
            'name' => 'Zeppelin Arena',
            'is_active' => true,
        ]);
    }

    public function test_a_created_venue_is_scoped_to_the_current_mandant_never_the_payload(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/venues', [
                'name' => 'Hack',
                'mandant_id' => $this->mandantB->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('venues', [
            'name' => 'Hack',
            'mandant_id' => $this->mandantA->id,
        ]);
        $this->assertDatabaseMissing('venues', [
            'name' => 'Hack',
            'mandant_id' => $this->mandantB->id,
        ]);
    }

    public function test_duplicate_name_within_the_mandant_is_422_with_a_german_message_not_a_db_error(): void
    {
        $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);

        $response = $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/venues', ['name' => 'Zeppelin Arena'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('venues', 1);
        $this->assertIsString($response->json('errors.name.0'));
    }

    public function test_a_deactivated_name_stays_taken_and_is_reactivated_rather_than_duplicated(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena', 'is_active' => false]);

        // The plain composite unique covers inactive rows too — a deactivated
        // name is not free real estate.
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/venues', ['name' => 'Zeppelin Arena'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$venue->id, ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseCount('venues', 1);
    }

    public function test_the_same_name_may_exist_in_two_mandants(): void
    {
        $this->mandantB->venues()->create(['name' => 'Stadion']);

        // B's name does not reserve A's — the unique rule is mandant-scoped.
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/venues', ['name' => 'Stadion'])
            ->assertStatus(201);

        $this->assertDatabaseCount('venues', 2);
    }

    public function test_store_validation_failures(): void
    {
        $admin = $this->actingAsApi($this->mandantAdmin());

        $admin->postJson('/api/admin/venues', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $admin->postJson('/api/admin/venues', ['name' => ['nope']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $admin->postJson('/api/admin/venues', ['name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Form-encoded bytes (`name=\xFF`) would explode in the response
        // encoder → HTTP 500 without the ValidUtf8 rule.
        $admin->post('/api/admin/venues', ['name' => "\xFF"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $admin->postJson('/api/admin/venues', ['name' => 'Ok', 'is_active' => 'vielleicht'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertDatabaseCount('venues', 0);
    }

    /* ---------------------------------------------------------------------
     | Update / deactivate
     | ------------------------------------------------------------------- */

    public function test_can_rename_a_venue_partially(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Alt', 'is_active' => true]);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$venue->id, ['name' => 'Neu'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu')
            // A partial update touches nothing else.
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Neu', 'is_active' => true]);
    }

    public function test_deactivating_keeps_the_row_and_the_name_resolvable_for_its_teams_and_events(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $team = $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);
        $event = $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $venue->id]);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$venue->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.teams_count', 1)
            ->assertJsonPath('data.events_count', 1);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'is_active' => false]);

        // The references still resolve to the same name — deactivation stops
        // new assignments, it does not erase history.
        $this->assertSame('Zeppelin Arena', $team->fresh()->venue?->name);
        $this->assertSame('Zeppelin Arena', $event->fresh()->venue?->name);
    }

    public function test_can_reactivate_a_deactivated_venue(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena', 'is_active' => false]);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$venue->id, ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'is_active' => true]);
    }

    public function test_renaming_to_another_venue_name_of_the_same_mandant_is_422(): void
    {
        $this->mandantA->venues()->create(['name' => 'A']);
        $b = $this->mandantA->venues()->create(['name' => 'B']);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$b->id, ['name' => 'A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('venues', ['id' => $b->id, 'name' => 'B']);
    }

    public function test_update_keeps_its_own_name_on_an_unchanged_payload(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);

        // `ignore($venue->id)` — sending the unchanged name is not a duplicate.
        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$venue->id, ['name' => 'Zeppelin Arena'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Zeppelin Arena');
    }

    public function test_update_validation_failures(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $admin = $this->actingAsApi($this->mandantAdmin());

        $admin->putJson('/api/admin/venues/'.$venue->id, [])
            ->assertOk()
            ->assertJsonPath('data.name', 'Zeppelin Arena');

        $admin->put('/api/admin/venues/'.$venue->id, ['name' => "\xFF"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $admin->putJson('/api/admin/venues/'.$venue->id, ['name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Zeppelin Arena']);
    }

    /* ---------------------------------------------------------------------
     | Destroy
     | ------------------------------------------------------------------- */

    public function test_can_delete_an_unreferenced_venue(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Tippfehler']);

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }

    public function test_can_delete_a_deactivated_venue_once_nothing_references_it(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Tippfehler', 'is_active' => false]);

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }

    public function test_deleting_a_venue_referenced_by_a_team_is_409_naming_the_counts(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);
        $this->mandantA->teams()->create(['name' => 'FC B', 'slug' => 'fc-b', 'venue_id' => $venue->id]);

        $response = $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(409);

        $this->assertIsString($response->json('message'));
        $this->assertStringContainsString('2 Vereine', $response->json('message'));
        $this->assertDatabaseHas('venues', ['id' => $venue->id]);
    }

    public function test_deleting_a_venue_referenced_by_an_event_is_409_naming_the_counts(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $venue->id]);

        $response = $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(409);

        $this->assertStringContainsString('1 Event', $response->json('message'));
        $this->assertDatabaseHas('venues', ['id' => $venue->id]);
    }

    public function test_deleting_a_venue_referenced_by_a_team_and_an_event_names_both_counts(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);
        $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $venue->id]);

        $response = $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(409);

        $this->assertStringContainsString('1 Verein', $response->json('message'));
        $this->assertStringContainsString('1 Event', $response->json('message'));
    }

    public function test_deactivating_is_always_possible_even_while_referenced(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);

        // The escape hatch is the DEACTIVATE, and it works while referenced.
        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$venue->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    /**
     * The DB `restrict` is the *enforcement* underneath the API-level 409 —
     * "deactivate instead of delete" is the policy. A direct DELETE that skips
     * the controller must therefore fail too. Skipped where the engine does
     * not enforce FKs in the surrounding transaction (SQLite inside a
     * transaction, see the portability rule); there the API-level guard is the
     * only line, which the 409 tests above already pin.
     */
    public function test_the_database_itself_refuses_to_delete_a_referenced_venue(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);

        $referencing = DB::table('teams')
            ->where('venue_id', $venue->id)
            ->exists();

        if (! $referencing) {
            $this->markTestSkipped('foreign keys are not enforced in this SQLite transaction context');
        }

        $this->expectException(QueryException::class);

        DB::table('venues')->where('id', $venue->id)->delete();
    }

    public function test_a_venue_is_deleted_with_its_mandant(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);

        $this->mandantA->delete();

        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }

    /* ---------------------------------------------------------------------
     | Mandant isolation
     | ------------------------------------------------------------------- */

    public function test_a_venue_of_a_foreign_mandant_is_404_on_update(): void
    {
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/venues/'.$foreign->id, ['name' => 'Gehackt'])
            ->assertStatus(404);

        $this->assertDatabaseHas('venues', ['id' => $foreign->id, 'name' => 'Fremde Halle']);
    }

    public function test_a_venue_of_a_foreign_mandant_is_404_on_delete(): void
    {
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/venues/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('venues', ['id' => $foreign->id]);
    }

    public function test_a_foreign_mandant_venue_name_does_not_block_the_own_mandant_name(): void
    {
        $this->mandantB->venues()->create(['name' => 'Stadion']);

        // The unique rule is mandant-scoped, so B's name does not reserve A's.
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/venues', ['name' => 'Stadion'])
            ->assertStatus(201);
    }

    public function test_a_team_or_event_cannot_be_linked_to_a_foreign_venue(): void
    {
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);
        $team = $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/teams/'.$team->id, [
                'venue_id' => $foreign->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'venue_id' => null]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Hack',
                'venue_id' => $foreign->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('events', ['title' => 'Hack']);
    }

    /* ---------------------------------------------------------------------
     | Authorization
     | ------------------------------------------------------------------- */

    public function test_venue_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/venues')->assertStatus(401);
        $this->postJson('/api/admin/venues', ['name' => 'Hack'])->assertStatus(401);
    }

    /**
     * `venues.manage` is held by mandant_admin and team_admin, exactly like
     * `categories.manage` — the grant exists so the venue combobox and its
     * inline create work in the team and the event form. It is NOT handed to
     * the end-user roles: `user` and `verifier` are refused on every route.
     */
    public static function deniedRoleProvider(): array
    {
        return [
            'user' => [UserRole::USER],
            'verifier' => [UserRole::VERIFIER],
        ];
    }

    #[DataProvider('deniedRoleProvider')]
    public function test_user_and_verifier_are_still_denied_every_venue_route(UserRole $role): void
    {
        $user = $this->createUserWithRole($role, $this->mandantA->id);

        $this->actingAsApi($user)->getJson('/api/admin/venues')->assertStatus(403);
        $this->actingAsApi($user)->postJson('/api/admin/venues', ['name' => 'Hack'])->assertStatus(403);

        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);

        $this->actingAsApi($user)
            ->putJson('/api/admin/venues/'.$venue->id, ['is_active' => false])
            ->assertStatus(403);

        $this->actingAsApi($user)
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'is_active' => true]);
        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
    }

    public function test_user_and_verifier_hold_no_venues_manage_permission(): void
    {
        $this->assertFalse($this->createUserWithRole(UserRole::USER, $this->mandantA->id)->hasPermission('venues.manage'));
        $this->assertFalse($this->createUserWithRole(UserRole::VERIFIER, $this->mandantA->id)->hasPermission('venues.manage'));
    }

    public function test_mandant_admin_super_admin_and_team_admin_hold_venues_manage(): void
    {
        $this->assertTrue($this->mandantAdmin()->hasPermission('venues.manage'));
        $this->assertTrue($this->superAdmin()->hasPermission('venues.manage'));
        $this->assertTrue($this->teamAdmin()->hasPermission('venues.manage'));
    }

    /**
     * The regression this grant fixes: the venue combobox in the team form
     * reads `GET /api/admin/venues`. A team_admin 403-ing on it would render
     * the picker empty on a form he is otherwise allowed to edit.
     */
    public function test_a_team_admin_may_read_the_venue_index_he_needs_for_the_picker(): void
    {
        $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->venues()->create(['name' => 'Alte Försterei']);
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $this->actingAsApi($this->teamAdmin())
            ->getJson('/api/admin/venues')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alte Försterei')
            ->assertJsonPath('data.1.name', 'Zeppelin Arena');
    }

    /**
     * The inline create in the team form ("Ort kann auch GUI mäßig mit erstellt
     * werden") is the user-confirmed path — for a team_admin it must not
     * dead-end, and the row lands in the *current* mandant.
     */
    public function test_a_team_admin_may_create_a_venue_from_the_team_form(): void
    {
        $this->actingAsApi($this->teamAdmin())
            ->postJson('/api/admin/venues', ['name' => 'Zeppelin Arena'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Zeppelin Arena')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('venues', [
            'mandant_id' => $this->mandantA->id,
            'name' => 'Zeppelin Arena',
        ]);
    }

    /**
     * Proves the gate is genuinely open for a team_admin, so that the 409 in
     * the next test is the *referential* guard answering rather than the
     * permission refusing (a 403 would hide a broken grant behind a 409).
     */
    public function test_a_team_admin_may_delete_an_unreferenced_venue(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Tippfehler']);

        $this->actingAsApi($this->teamAdmin())
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }

    public function test_a_team_admin_may_not_delete_a_venue_a_team_or_an_event_still_references(): void
    {
        $byTeam = $this->mandantA->venues()->create(['name' => 'Vereinsheim']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $byTeam->id]);

        $byEvent = $this->mandantA->venues()->create(['name' => 'Auswaertsplatz']);
        $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $byEvent->id]);

        $teamAdmin = $this->actingAsApi($this->teamAdmin());

        $byTeamResponse = $teamAdmin->deleteJson('/api/admin/venues/'.$byTeam->id)->assertStatus(409);
        $this->assertStringContainsString('1 Verein', $byTeamResponse->json('message'));

        $byEventResponse = $teamAdmin->deleteJson('/api/admin/venues/'.$byEvent->id)->assertStatus(409);
        $this->assertStringContainsString('1 Event', $byEventResponse->json('message'));

        // Deactivate stays available — that is the escape hatch.
        $this->assertDatabaseHas('venues', ['id' => $byTeam->id, 'is_active' => true]);
        $this->assertDatabaseHas('venues', ['id' => $byEvent->id, 'is_active' => true]);
    }

    /**
     * A venue row is never team-owned, so there is no team level to narrow the
     * write to: the surface is mandant-scoped on the write exactly as it is on
     * the read. Pinned so the breadth of the grant is documented rather than
     * accidental — and bounded by the mandant, see the two isolation tests.
     */
    public function test_a_team_admin_manages_the_venue_list_of_his_whole_mandant(): void
    {
        $ownTeamsVenue = $this->mandantA->venues()->create(['name' => 'Vereinsheim']);
        $unused = $this->mandantA->venues()->create(['name' => 'Auswaertsplatz']);
        $teamAdmin = $this->teamAdmin();

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/venues/'.$unused->id, ['name' => 'Auswaertsplatz neu'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Auswaertsplatz neu');

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/venues/'.$ownTeamsVenue->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('venues', ['id' => $unused->id, 'name' => 'Auswaertsplatz neu']);
        $this->assertDatabaseHas('venues', ['id' => $ownTeamsVenue->id, 'is_active' => false]);
    }

    /**
     * The grant changes the *role*, never the *mandant scope*: a team_admin
     * reads and writes only his own mandant's venues, a foreign id is 404.
     */
    public function test_a_team_admin_never_reaches_a_foreign_mandant_venue(): void
    {
        $teamAdmin = $this->teamAdmin();
        $own = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        // The gate passes (it is his mandant), the mandant scope refuses: 404.
        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/venues/'.$foreign->id, ['name' => 'Gehackt'])
            ->assertStatus(404);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/venues/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('venues', ['id' => $foreign->id, 'name' => 'Fremde Halle']);
    }

    public function test_a_team_admin_of_a_foreign_mandant_is_denied_the_venue_surface(): void
    {
        // The role lives in mandant B, the request runs in mandant A.
        $foreignTeamAdmin = $this->teamAdmin($this->mandantB);
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);

        $this->actingAsApi($foreignTeamAdmin)->getJson('/api/admin/venues')->assertStatus(403);
        $this->actingAsApi($foreignTeamAdmin)->postJson('/api/admin/venues', ['name' => 'Hack'])->assertStatus(403);
        $this->actingAsApi($foreignTeamAdmin)
            ->putJson('/api/admin/venues/'.$venue->id, ['name' => 'Gehackt'])
            ->assertStatus(403);
        $this->actingAsApi($foreignTeamAdmin)
            ->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Zeppelin Arena']);
        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
    }

    /**
     * The gate is team-scoped for a team_admin: no team assignment → no
     * permission at all (mirrors `RolePermissionTest`).
     */
    public function test_a_team_admin_without_a_team_assignment_is_denied(): void
    {
        $orphan = $this->createUserWithRole(UserRole::TEAM_ADMIN, $this->mandantA->id);

        $this->actingAsApi($orphan)->getJson('/api/admin/venues')->assertStatus(403);
        $this->actingAsApi($orphan)->postJson('/api/admin/venues', ['name' => 'Hack'])->assertStatus(403);

        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
    }

    public function test_a_mandant_admin_never_reaches_a_foreign_mandant_venue(): void
    {
        // The gate passes, the mandant scope refuses: 404, not 403.
        $admin = $this->mandantAdmin();
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $this->actingAsApi($admin)
            ->putJson('/api/admin/venues/'.$foreign->id, ['name' => 'Gehackt'])
            ->assertStatus(404);

        $this->assertDatabaseHas('venues', ['id' => $foreign->id, 'name' => 'Fremde Halle']);
    }

    /* ---------------------------------------------------------------------
     | Model / factory
     | ------------------------------------------------------------------- */

    public function test_the_factory_creates_an_active_venue_of_the_given_mandant(): void
    {
        $venue = Venue::factory()->create(['mandant_id' => $this->mandantA->id]);

        $this->assertTrue($venue->is_active);
        $this->assertSame($this->mandantA->id, $venue->mandant_id);
        $this->assertTrue(Venue::query()->find($venue->id)->is_active);
    }

    public function test_the_inactive_factory_state_deactivates(): void
    {
        $venue = Venue::factory()->inactive()->create(['mandant_id' => $this->mandantA->id]);

        $this->assertFalse($venue->is_active);
    }

    public function test_the_relations_are_reachable_from_the_venue(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $team = $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);
        $event = $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $venue->id]);

        $this->assertTrue($venue->teams->contains($team));
        $this->assertTrue($venue->events->contains($event));
    }

    public function test_a_team_keeps_its_venue_reference_when_the_mandant_is_deleted_away(): void
    {
        // Sanity guard for the cascade path: a mandant delete takes its venues
        // with it, so no venue row can ever survive its mandant.
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);

        Team::query()->whereKey($this->mandantA->teams()->first()->id)->delete();

        $this->assertDatabaseHas('venues', ['id' => $venue->id]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN, null);
    }

    private function mandantAdmin(): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN, $this->mandantA->id);
    }

    /**
     * A team_admin WITH a team assignment — without one the gate denies every
     * permission (see `test_a_team_admin_without_a_team_assignment_is_denied`).
     */
    private function teamAdmin(?Mandant $mandant = null): User
    {
        $mandant ??= $this->mandantA;

        $team = Team::factory()->create(['mandant_id' => $mandant->id]);

        return $this->createUserWithRole(UserRole::TEAM_ADMIN, $mandant->id, $team->id);
    }

    private function createUserWithRole(UserRole $role, ?int $mandantId, ?int $teamId = null): User
    {
        $user = User::factory()->create();
        $roleRow = Role::query()->where('slug', $role->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $roleRow->id,
            'mandant_id' => $mandantId,
            'team_id' => $teamId,
        ]);

        return $user;
    }
}

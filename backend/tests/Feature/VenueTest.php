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
 *
 * The one place the team_admin grant is narrower than the gate: he may only
 * MODIFY a venue his own team uses. Read and create stay mandant-wide (the
 * picker and the inline create must not dead-end a Verband with no venues), and
 * that asymmetry is pinned in the "team_admin write scope" block below together
 * with the reason a deactivated name makes even an unreferenced venue
 * off-limits to him.
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

    /* ---------------------------------------------------------------------
     | The team_admin write scope: only venues his OWN teams use
     | ------------------------------------------------------------------- */

    /**
     * THE DECISION (W12): a `team_admin` may only MODIFY the venues his own
     * team(s) use. Reading and creating stay mandant-wide — the two tests above
     * (`test_a_team_admin_may_read_the_venue_index_he_needs_for_the_picker`,
     * `test_a_team_admin_may_create_a_venue_from_the_team_form`) are the reason
     * and they stay green under this rule. Only the WRITE narrows.
     *
     * The asymmetry is deliberate and both halves have a reason:
     * - **Read + create mandant-wide.** The read feeds the venue combobox in
     *   the team form, which has to offer the whole Verband's venues (a club
     *   plays its derby somewhere else), and the inline create next to it must
     *   not dead-end a Verband that has no venues yet. `venues.manage` follows
     *   `categories.manage` here for exactly that reason.
     * - **Write narrowed.** A venue row is SHARED master data, not team-owned
     *   (`venues` has no `team_id` at all). Renaming or deactivating the venue
     *   of a neighbouring club changes what the neighbour sees on its own
     *   pages — and deactivating is not cosmetic: a deactivated name stays
     *   taken forever, so it also steals the name from the Verband. Nobody
     *   asked the neighbour.
     *
     * So: own team's venue → rename AND deactivate both allowed.
     *
     * REPLACES `test_a_team_admin_manages_the_venue_list_of_his_whole_mandant`,
     * which asserted the exact opposite — a team_admin renaming a venue no
     * team uses — and whose docblock called that breadth the pinned intent.
     * The decision withdrew it. Its coverage is not lost, it is split: the
     * rename + deactivate pair lives here (on his own team's venue), the
     * mandant level keeps the breadth in
     * `test_a_mandant_admin_may_still_rename_and_delete_an_unused_venue`, and
     * the "bounded by the mandant, not by the team" half it also covered
     * survives unchanged in `test_a_team_admin_never_reaches_a_foreign_mandant_venue`.
     */
    public function test_a_team_admin_may_rename_and_deactivate_the_venue_his_own_team_uses(): void
    {
        [$teamAdmin, $team] = $this->teamAdminWithTeam();
        $venue = $this->mandantA->venues()->create(['name' => 'Vereinsheim']);
        $team->update(['venue_id' => $venue->id]);

        $client = $this->actingAsApi($teamAdmin);

        $client->putJson('/api/admin/venues/'.$venue->id, ['name' => 'Vereinsheim Süd'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Vereinsheim Süd');

        $client->putJson('/api/admin/venues/'.$venue->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'name' => 'Vereinsheim Süd',
            'is_active' => false,
        ]);
    }

    /**
     * THE DEFECT this scope closes. Two clubs of the SAME Verband, one venue
     * row: FC Nachbar plays there, FC Eigen does not. Renaming it or pulling
     * the plug on it from the other club's admin is the "ärgert den Nachbarn,
     * ohne ihn zu fragen" case — and it is what the API answered 200 to
     * before the scope existed.
     */
    public function test_a_team_admin_may_not_rename_or_deactivate_a_venue_only_another_team_uses(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Nachbarstadion']);
        $this->mandantA->teams()->create([
            'name' => 'FC Nachbar',
            'slug' => 'fc-nachbar',
            'venue_id' => $venue->id,
        ]);

        // The team_admin's OWN team exists and belongs to this mandant — the
        // 403 below is the ownership scope, not a missing assignment and not
        // the mandant axis (both of those have their own tests below).
        $teamAdmin = $this->teamAdmin();

        $client = $this->actingAsApi($teamAdmin);

        $client->putJson('/api/admin/venues/'.$venue->id, ['name' => 'Umbenannt'])
            ->assertStatus(403);
        $client->putJson('/api/admin/venues/'.$venue->id, ['is_active' => false])
            ->assertStatus(403);
        $client->deleteJson('/api/admin/venues/'.$venue->id)
            ->assertStatus(403);

        // Nothing moved: the neighbour's venue is intact and still active.
        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'name' => 'Nachbarstadion',
            'is_active' => true,
        ]);
    }

    /**
     * The "no team uses it" half, and the reason this is not softened into
     * "his own team's venue OR nobody's venue": an unreferenced venue is not
     * harmless to touch. `unique(mandant_id, name)` deliberately does NOT
     * filter on `is_active`, so DEACTIVATING an unreferenced venue takes the
     * name out of circulation permanently — a team_admin could squat names for
     * the whole Verband. The mandant level keeps that power
     * (`test_a_mandant_admin_may_still_rename_and_delete_an_unused_venue`).
     */
    public function test_a_team_admin_may_not_touch_a_venue_no_team_uses(): void
    {
        $unused = $this->mandantA->venues()->create(['name' => 'Tippfehler']);
        $teamAdmin = $this->teamAdmin();

        $client = $this->actingAsApi($teamAdmin);

        $client->putJson('/api/admin/venues/'.$unused->id, ['name' => 'Neu'])->assertStatus(403);
        $client->putJson('/api/admin/venues/'.$unused->id, ['is_active' => false])->assertStatus(403);
        $client->deleteJson('/api/admin/venues/'.$unused->id)->assertStatus(403);

        $this->assertDatabaseHas('venues', ['id' => $unused->id, 'name' => 'Tippfehler', 'is_active' => true]);
    }

    /**
     * An EVENT reference is not ownership. `events.venue_id` is an assignment
     * on a single fixture ("this derby is played here"), while `teams.venue_id`
     * is the club's standing home ground — that is the one that says "this
     * venue belongs to my club". Letting an event at a venue hand its admin a
     * rename/deactivate right on the whole Verband's stadium would put the
     * neighbour's data back under his control through the back door.
     *
     * Note what this costs: the 409 "1 Event" message is unreachable for a
     * team_admin on a venue no team of his uses (it is pinned for the
     * mandant level in `test_deleting_a_venue_referenced_by_an_event_is_409_naming_the_counts`).
     */
    public function test_a_team_admin_may_not_touch_a_venue_only_an_event_of_his_mandant_uses(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Auswaertsplatz']);
        $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $venue->id]);

        $client = $this->actingAsApi($this->teamAdmin());

        $client->putJson('/api/admin/venues/'.$venue->id, ['name' => 'Neu'])->assertStatus(403);
        $client->deleteJson('/api/admin/venues/'.$venue->id)->assertStatus(403);

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Auswaertsplatz']);
    }

    /**
     * Which of the two 403s is it? This exists so the 403s above can never be
     * misread as a broken grant: the SAME user passes the `venues.manage` GATE
     * on both the read and the create — so the refusal on the write is the
     * narrowed write scope, and not the permission refusing him. The message is
     * what tells the two apart (the gate answers with Laravel's default).
     *
     * SUPERSEDES `test_a_team_admin_may_delete_an_unreferenced_venue`, which
     * pinned a team_admin deleting a venue no team uses — the exact grant this
     * decision withdrew. Its stated purpose ("prove the gate is genuinely open
     * for a team_admin, so the 409 is the referential guard and not the
     * permission") is the `index` 200 + `store` 201 below, one role unchanged
     * and now on the same user as the 403, which is strictly more than the
     * original could show.
     */
    public function test_the_write_scope_403_is_not_the_permission_gate(): void
    {
        $unused = $this->mandantA->venues()->create(['name' => 'Tippfehler']);
        $teamAdmin = $this->teamAdmin();

        $this->actingAsApi($teamAdmin)->getJson('/api/admin/venues')->assertOk();
        $this->actingAsApi($teamAdmin)->postJson('/api/admin/venues', ['name' => 'Neu'])->assertStatus(201);

        $response = $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/venues/'.$unused->id)
            ->assertStatus(403);

        $this->assertStringContainsString('own teams', (string) $response->json('message'));
    }

    /**
     * The mandant level keeps the broad grant this scope took away from the
     * team_admin — renaming AND deleting a venue no team uses stays a
     * mandant_admin's job, unchanged by the decision above.
     *
     * Together with
     * `test_a_team_admin_may_rename_and_deactivate_the_venue_his_own_team_uses`
     * this carries the coverage of the withdrawn
     * `test_a_team_admin_manages_the_venue_list_of_his_whole_mandant`, one role
     * down for each half.
     */
    public function test_a_mandant_admin_may_still_rename_and_delete_an_unused_venue(): void
    {
        $unused = $this->mandantA->venues()->create(['name' => 'Auswaertsplatz']);
        $admin = $this->mandantAdmin();

        $this->actingAsApi($admin)
            ->putJson('/api/admin/venues/'.$unused->id, ['name' => 'Auswaertsplatz neu'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Auswaertsplatz neu');

        $this->actingAsApi($admin)
            ->deleteJson('/api/admin/venues/'.$unused->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('venues', ['id' => $unused->id]);
    }

    /**
     * The 409 path stays reachable for a team_admin — on the venues his own
     * team uses. Both venues below are pointed at by HIS team on purpose: with
     * a foreign or unreferenced venue the narrowed write scope answers 403
     * first and the referential guard would never be reached, which would
     * silently drop the "deactivate instead of delete" coverage for this role.
     *
     * REPLACES `test_a_team_admin_may_not_delete_a_venue_a_team_or_an_event_still_references`,
     * which used a venue referenced by an arbitrary team plus an event-only one
     * — neither of them his, so both now answer 403 before the referential
     * guard is ever consulted. The policy under test is unchanged, only the
     * fixture had to move onto his own team, which also makes the combined
     * "1 Verein und 1 Event" message reachable for this role for the first
     * time. The event-only variant stays pinned at the mandant level in
     * `test_deleting_a_venue_referenced_by_an_event_is_409_naming_the_counts`.
     */
    public function test_a_team_admin_may_not_delete_a_venue_his_own_team_uses_still_references(): void
    {
        // Two clubs, so each venue has its own owner: a team row carries ONE
        // `venue_id`, and flipping it mid-test would have made the two halves
        // of this test depend on each other.
        [$plainAdmin, $plainTeam] = $this->teamAdminWithTeam();
        $byTeam = $this->mandantA->venues()->create(['name' => 'Vereinsheim']);
        $plainTeam->update(['venue_id' => $byTeam->id]);

        [$derbyAdmin, $derbyTeam] = $this->teamAdminWithTeam();
        $byTeamAndEvent = $this->mandantA->venues()->create(['name' => 'Derbyplatz']);
        $derbyTeam->update(['venue_id' => $byTeamAndEvent->id]);
        $this->mandantA->events()->create(['title' => 'Derby', 'venue_id' => $byTeamAndEvent->id]);

        $onlyTeam = $this->actingAsApi($plainAdmin)
            ->deleteJson('/api/admin/venues/'.$byTeam->id)
            ->assertStatus(409);
        $this->assertStringContainsString('1 Verein', $onlyTeam->json('message'));

        $both = $this->actingAsApi($derbyAdmin)
            ->deleteJson('/api/admin/venues/'.$byTeamAndEvent->id)
            ->assertStatus(409);
        $this->assertStringContainsString('1 Verein', $both->json('message'));
        $this->assertStringContainsString('1 Event', $both->json('message'));

        // Deactivate stays available — that is the escape hatch, and it is a
        // write too, so the narrowed scope must not take it away either.
        $this->actingAsApi($plainAdmin)
            ->putJson('/api/admin/venues/'.$byTeam->id, ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('venues', ['id' => $byTeam->id, 'is_active' => false]);
        $this->assertDatabaseHas('venues', ['id' => $byTeamAndEvent->id, 'name' => 'Derbyplatz']);
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
        return $this->teamAdminWithTeam($mandant)[0];
    }

    /**
     * The same, but hands back his TEAM too — the write-scope tests need to
     * decide whether a venue is one "his own team uses", which means pointing
     * a team at it.
     *
     * @return array{0: User, 1: Team}
     */
    private function teamAdminWithTeam(?Mandant $mandant = null): array
    {
        $mandant ??= $this->mandantA;

        $team = Team::factory()->create(['mandant_id' => $mandant->id]);

        return [
            $this->createUserWithRole(UserRole::TEAM_ADMIN, $mandant->id, $team->id),
            $team,
        ];
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

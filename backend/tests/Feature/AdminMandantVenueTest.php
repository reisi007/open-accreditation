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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The venue surface ADDRESSED BY MANDANT (`/api/admin/mandants/{mandant}/venues`).
 *
 * Why this second surface exists at all: `/api/admin/mandants/{id}` is the one
 * admin page that explicitly addresses a mandant by URL, and its team form
 * carries the venue combobox. The host-scoped `/api/admin/venues` resolved its
 * rows through `MandantContext` (the request HOST), so on that page the picker
 * offered the HOST mandant's venues — and its inline create WROTE into the host
 * mandant without any error, while the team save that followed failed with a
 * 404 (`TeamController::assertVenueOfMandant`). A silent write into the wrong
 * tenant is the defect this surface closes.
 *
 * Two access rules, and they are deliberately different:
 * - **super_admin** may address ANY mandant from ANY host (his entire purpose;
 *   `ResolvesMandantRouteParameter` returns early for him). This is the branch
 *   the fix must not tighten.
 * - **everyone else** may only address the mandant he is already on — the
 *   current `MandantContext` — else 404. So the addressed surface grants a
 *   non-super-admin exactly the venues the host-scoped surface already gave
 *   him: no new reach, only a different (correct) scope for the page that
 *   addresses a mandant.
 *
 * The host-scoped surface stays: the combobox on the categories, events and
 * accreditations pages is host-relative, and `VenuesPage` lists the host's
 * venues. Both surfaces are covered below so neither can regress into the
 * other.
 */
class AdminMandantVenueTest extends TestCase
{
    use RefreshDatabase;

    /** The mandant the URL addresses. */
    private Mandant $mandantA;

    /** The mandant the request HOST resolves to — deliberately a different one. */
    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create([
            'slug' => 'verband-a',
            'name' => 'Verband A',
            'teams_enabled' => true,
        ]);
        $this->mandantB = Mandant::factory()->create([
            'slug' => 'verband-b',
            'name' => 'Verband B',
            'teams_enabled' => true,
        ]);

        // The host is B throughout: every request below is made "from" a
        // domain that does not belong to the mandant the URL addresses.
        MandantContext::set($this->mandantB);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The defect: the page addressed A while the write landed in B
     | ------------------------------------------------------------------- */

    public function test_the_addressed_mandants_venues_are_listed_even_when_the_host_is_another_mandant(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zeppelin Arena')
            // The derived count the team save invalidates must belong to the
            // addressed mandant as well.
            ->assertJsonPath('data.0.teams_count', 1)
            ->assertJsonPath('data.0.events_count', 0);

        // No leak in either direction.
        $response->assertDontSee('Fremde Halle');
    }

    public function test_the_inline_create_lands_in_the_addressed_mandant_and_not_in_the_host_mandant(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/venues', ['name' => 'Neue Halle'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Neue Halle');

        $venueId = $response->json('data.id');

        $this->assertDatabaseHas('venues', [
            'id' => $venueId,
            'name' => 'Neue Halle',
            'mandant_id' => $this->mandantA->id,
        ]);
        // The bug: this row used to be created in the HOST mandant.
        $this->assertDatabaseMissing('venues', [
            'name' => 'Neue Halle',
            'mandant_id' => $this->mandantB->id,
        ]);

        // And the team save that follows no longer 404s on the venue id.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/teams', [
                'name' => 'FC A',
                'slug' => 'fc-a',
                'venue_id' => $venueId,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.venue.id', $venueId);
    }

    public function test_the_payload_cannot_redirect_the_create_into_another_mandant(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/venues', [
                'name' => 'Hack',
                'mandant_id' => $this->mandantB->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('venues', ['name' => 'Hack', 'mandant_id' => $this->mandantA->id]);
        $this->assertDatabaseMissing('venues', ['name' => 'Hack', 'mandant_id' => $this->mandantB->id]);
    }

    /* ---------------------------------------------------------------------
     | Update / destroy through the addressed surface
     | ------------------------------------------------------------------- */

    public function test_the_addressed_update_renames_and_reactivates_the_addressed_row(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Alt', 'is_active' => false]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id.'/venues/'.$venue->id, [
                'name' => 'Neu',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'mandant_id' => $this->mandantA->id,
            'name' => 'Neu',
            'is_active' => true,
        ]);
    }

    public function test_a_venue_of_another_mandant_is_404_on_the_addressed_update(): void
    {
        $own = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);
        $base = '/api/admin/mandants/'.$this->mandantA->id.'/venues';
        $admin = $this->actingAsApi($this->superAdmin());

        // The positive half first: without it a 404 from a MISSING route would
        // make this test pass for the wrong reason.
        $admin->putJson($base.'/'.$own->id, ['name' => 'Neu'])->assertOk();

        $admin->putJson($base.'/'.$foreign->id, ['name' => 'Gehackt'])->assertStatus(404);

        $this->assertDatabaseHas('venues', ['id' => $own->id, 'name' => 'Neu']);
        $this->assertDatabaseHas('venues', ['id' => $foreign->id, 'name' => 'Fremde Halle']);
    }

    public function test_a_venue_of_another_mandant_is_404_on_the_addressed_delete(): void
    {
        $own = $this->mandantA->venues()->create(['name' => 'Tippfehler']);
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);
        $base = '/api/admin/mandants/'.$this->mandantA->id.'/venues';
        $admin = $this->actingAsApi($this->superAdmin());

        $admin->deleteJson($base.'/'.$own->id)->assertStatus(204);

        $admin->deleteJson($base.'/'.$foreign->id)->assertStatus(404);

        $this->assertDatabaseMissing('venues', ['id' => $own->id]);
        $this->assertDatabaseHas('venues', ['id' => $foreign->id]);
    }

    public function test_the_addressed_delete_answers_409_while_referenced_and_204_once_free(): void
    {
        $venue = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $team = $this->mandantA->teams()->create(['name' => 'FC A', 'slug' => 'fc-a', 'venue_id' => $venue->id]);

        $response = $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/venues/'.$venue->id)
            ->assertStatus(409);

        $this->assertStringContainsString('1 Verein', $response->json('message'));

        $team->delete();

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/venues/'.$venue->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('venues', ['id' => $venue->id]);
    }

    public function test_the_addressed_validation_failures_are_422(): void
    {
        $admin = $this->actingAsApi($this->superAdmin());
        $base = '/api/admin/mandants/'.$this->mandantA->id.'/venues';

        $admin->postJson($base, [])->assertStatus(422)->assertJsonValidationErrors('name');
        $admin->postJson($base, ['name' => "\xFF"])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('venues', 0);
    }

    public function test_the_addressed_create_is_scoped_by_the_route_even_without_a_mandant_context(): void
    {
        MandantContext::reset();

        // A super_admin needs no context (that early return is his purpose), and
        // the row still lands in the mandant the URL addressed — an addressed
        // write can never become an unscoped one.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/venues', ['name' => 'Ohne Kontext'])
            ->assertStatus(201);

        $this->assertDatabaseHas('venues', ['name' => 'Ohne Kontext', 'mandant_id' => $this->mandantA->id]);
        $this->assertDatabaseMissing('venues', ['name' => 'Ohne Kontext', 'mandant_id' => $this->mandantB->id]);
    }

    public function test_a_mandant_admin_without_a_mandant_context_is_refused_on_the_addressed_surface(): void
    {
        MandantContext::reset();
        $base = '/api/admin/mandants/'.$this->mandantA->id.'/venues';
        $admin = $this->actingAsApi($this->mandantAdmin($this->mandantA));

        // 403, not 404: without a context the `venues.manage` GATE cannot
        // resolve his role assignment and refuses before the controller runs.
        // Identical to the host-scoped surface, which is the point — the
        // addressed routes add no new failure mode.
        $admin->postJson($base, ['name' => 'Ohne Kontext'])->assertStatus(403);
        $admin->getJson($base)->assertStatus(403);
        $this->assertDatabaseMissing('venues', ['name' => 'Ohne Kontext']);
    }

    /* ---------------------------------------------------------------------
     | Name uniqueness follows the ROUTE mandant
     | ------------------------------------------------------------------- */

    public function test_the_name_uniqueness_is_scoped_to_the_addressed_mandant(): void
    {
        $this->mandantA->venues()->create(['name' => 'Stadion']);
        $this->mandantB->venues()->create(['name' => 'Arena']);

        $base = '/api/admin/mandants/'.$this->mandantA->id.'/venues';
        $admin = $this->actingAsApi($this->superAdmin());

        // A's own name is taken — 422, not a DB constraint error.
        $admin->postJson($base, ['name' => 'Stadion'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // B's name does not reserve anything in A.
        $admin->postJson($base, ['name' => 'Arena'])->assertStatus(201);

        // A deactivated name stays taken on the addressed surface as well.
        $admin->putJson($base.'/'.($this->mandantA->venues()->where('name', 'Stadion')->firstOrFail()->id), [
            'is_active' => false,
        ])->assertOk();
        $admin->postJson($base, ['name' => 'Stadion'])->assertStatus(422);
        $this->assertDatabaseCount('venues', 3);
    }

    /* ---------------------------------------------------------------------
     | The host-scoped surface keeps answering the host mandant
     | ------------------------------------------------------------------- */

    public function test_the_host_scoped_surface_still_reads_and_writes_the_host_mandant(): void
    {
        $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $admin = $this->actingAsApi($this->superAdmin());

        $admin->getJson('/api/admin/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fremde Halle');

        $response = $admin->postJson('/api/admin/venues', ['name' => 'Host Halle'])->assertStatus(201);
        $this->assertDatabaseHas('venues', [
            'id' => $response->json('data.id'),
            'name' => 'Host Halle',
            'mandant_id' => $this->mandantB->id,
        ]);

        $admin->deleteJson('/api/admin/venues/'.$response->json('data.id'))->assertStatus(204);
    }

    /* ---------------------------------------------------------------------
     | Isolation: everyone but the super admin stays on his own mandant
     | ------------------------------------------------------------------- */

    public function test_a_mandant_admin_addresses_his_own_mandant(): void
    {
        MandantContext::set($this->mandantA);
        $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        // The page's own surface — a mandant_admin is not locked out of it.
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zeppelin Arena');
    }

    public function test_a_mandant_admin_addressing_a_foreign_mandant_is_404_and_leaks_nothing(): void
    {
        MandantContext::set($this->mandantA);
        $admin = $this->actingAsApi($this->mandantAdmin($this->mandantA));
        $own = $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $foreign = $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        // The positive half: his OWN mandant is addressable, so the 404 below
        // can only come from the scope check and not from a missing route.
        $admin->getJson('/api/admin/mandants/'.$this->mandantA->id.'/venues')
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id);
        $admin->putJson('/api/admin/mandants/'.$this->mandantA->id.'/venues/'.$own->id, ['name' => 'Neu'])
            ->assertOk();

        $base = '/api/admin/mandants/'.$this->mandantB->id.'/venues';

        $admin->getJson($base)->assertStatus(404)->assertDontSee('Fremde Halle');
        $admin->postJson($base, ['name' => 'Hack'])->assertStatus(404);
        $admin->putJson($base.'/'.$foreign->id, ['name' => 'Gehackt'])->assertStatus(404);
        $admin->deleteJson($base.'/'.$foreign->id)->assertStatus(404);

        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
        $this->assertDatabaseHas('venues', ['id' => $foreign->id, 'name' => 'Fremde Halle']);
    }

    public function test_a_team_admin_addresses_his_own_mandant_and_never_another(): void
    {
        MandantContext::set($this->mandantA);
        $this->mandantA->venues()->create(['name' => 'Zeppelin Arena']);
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);

        $teamAdmin = $this->actingAsApi($this->teamAdmin($this->mandantA));

        // The picker and its inline create have to work for him here too —
        // that grant is the reason `venues.manage` covers this surface.
        $teamAdmin->getJson('/api/admin/mandants/'.$this->mandantA->id.'/venues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zeppelin Arena');

        $teamAdmin->postJson('/api/admin/mandants/'.$this->mandantA->id.'/venues', ['name' => 'Vereinsheim'])
            ->assertStatus(201);
        $this->assertDatabaseHas('venues', ['name' => 'Vereinsheim', 'mandant_id' => $this->mandantA->id]);

        $teamAdmin->getJson('/api/admin/mandants/'.$this->mandantB->id.'/venues')
            ->assertStatus(404)
            ->assertDontSee('Fremde Halle');
        $teamAdmin->postJson('/api/admin/mandants/'.$this->mandantB->id.'/venues', ['name' => 'Hack'])
            ->assertStatus(404);
        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
    }

    public function test_a_team_admin_of_another_mandant_never_reaches_the_addressed_surface_of_the_host(): void
    {
        // The confused-deputy case: he sits on B's domain, but his role
        // assignment is on A — and B is exactly the mandant whose URL he could
        // name. The gate has to refuse (his permission is not resolvable in
        // B's context) BEFORE the controller's 404 would; either way no data.
        $this->mandantB->venues()->create(['name' => 'Fremde Halle']);
        $teamAdmin = $this->actingAsApi($this->teamAdmin($this->mandantA));
        $base = '/api/admin/mandants/'.$this->mandantB->id.'/venues';

        $this->assertSame($this->mandantB->id, MandantContext::currentId());

        $teamAdmin->getJson($base)->assertStatus(403)->assertDontSee('Fremde Halle');
        $teamAdmin->postJson($base, ['name' => 'Hack'])->assertStatus(403);
        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
    }

    public function test_the_addressed_surface_requires_authentication(): void
    {
        $this->getJson('/api/admin/mandants/'.$this->mandantA->id.'/venues')->assertStatus(401);
        $this->postJson('/api/admin/mandants/'.$this->mandantA->id.'/venues', ['name' => 'Hack'])->assertStatus(401);
    }

    public static function deniedRoleProvider(): array
    {
        return [
            'user' => [UserRole::USER],
            'verifier' => [UserRole::VERIFIER],
        ];
    }

    #[DataProvider('deniedRoleProvider')]
    public function test_the_end_user_roles_are_refused_on_the_addressed_surface(UserRole $role): void
    {
        $user = $this->createUserWithRole($role, $this->mandantA->id);
        $base = '/api/admin/mandants/'.$this->mandantA->id.'/venues';

        // `can:venues.manage` is the same gate as on the host-scoped surface, so
        // the refused roles are refused identically there.
        $this->actingAsApi($user)->getJson('/api/admin/venues')->assertStatus(403);
        $this->actingAsApi($user)->getJson($base)->assertStatus(403);
        $this->actingAsApi($user)->postJson($base, ['name' => 'Hack'])->assertStatus(403);
        $this->assertDatabaseMissing('venues', ['name' => 'Hack']);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN, null);
    }

    private function mandantAdmin(Mandant $mandant): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN, $mandant->id);
    }

    /** A team_admin WITH a team assignment — without one the gate denies everything. */
    private function teamAdmin(Mandant $mandant): User
    {
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

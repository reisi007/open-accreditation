<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Blacklist;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\Team;
use App\Models\User;
use App\Services\SubAllocationService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P3d sub-accreditations (Park-/Sitzkarten, D9).
 *
 * The sub-accreditation is a per-main-accreditation quota object with its own
 * allocation (SubAllocationService — same core rules as P3c: deterministic
 * VIP → FCFS → id order, quota never exceeded, overbooking → `Quota
 * erschöpft`, mandant-scoped blacklist never approved) and the mandatory main
 * dependency: a sub-application may only be created on top of an approved
 * main application. Admin CRUD mirrors the P2b/P3b patterns (can
 * `accreditations.manage`, team_admin locked to own teams); the public list
 * shows only active subs of an active main accreditation.
 */
class SubAccreditationTest extends TestCase
{
    use RefreshDatabase;

    private SubAllocationService $subAllocation;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->subAllocation = app(SubAllocationService::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->teamB = $this->mandantA->teams()->create(['name' => 'Team B', 'slug' => 'team-b']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Admin CRUD — gates, team scoping, validation
     | ------------------------------------------------------------------- */

    public function test_sub_accreditation_endpoints_require_authentication(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        $this->getJson('/api/admin/accreditations/'.$sub->accreditation_id.'/sub-accreditations')->assertStatus(401);
        $this->postJson('/api/admin/accreditations/'.$sub->accreditation_id.'/sub-accreditations', [])->assertStatus(401);
        $this->putJson('/api/admin/sub-accreditations/'.$sub->id, [])->assertStatus(401);
        $this->deleteJson('/api/admin/sub-accreditations/'.$sub->id)->assertStatus(401);
        $this->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'all'])->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->getJson('/api/admin/accreditations/'.$sub->accreditation_id.'/sub-accreditations')
                ->assertStatus(403, "expected 403 for {$role->value} on sub-accreditations index");

            $this->actingAsApi($user)
                ->postJson('/api/admin/accreditations/'.$sub->accreditation_id.'/sub-accreditations', ['type' => 'park', 'quota' => 5])
                ->assertStatus(403, "expected 403 for {$role->value} on sub-accreditations store");
        }
    }

    public function test_super_admin_can_create_park_and_seat_sub_accreditation(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', [
                'type' => 'park',
                'quota' => 50,
                'deadline_start' => '2026-08-01',
                'deadline_end' => '2026-08-15',
                'auto_approve' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.accreditation_id', $accreditation->id)
            ->assertJsonPath('data.type', 'park')
            ->assertJsonPath('data.quota', 50)
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.available', 50)
            ->assertJsonPath('data.deadline_start', '2026-08-01')
            ->assertJsonPath('data.deadline_end', '2026-08-15')
            ->assertJsonPath('data.auto_approve', true)
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('sub_accreditations', [
            'accreditation_id' => $accreditation->id,
            'type' => 'park',
            'quota' => 50,
            'active' => true,
        ]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', [
                'type' => 'seat',
                'quota' => 10,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'seat');
    }

    public function test_sub_accreditation_validation_type_quota_deadline(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', ['type' => 'banana', 'quota' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', ['type' => 'park', 'quota' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quota');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'quota']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', [
                'type' => 'park',
                'quota' => 5,
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_end');

        // Equal dates are a valid single-day window.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', [
                'type' => 'park',
                'quota' => 5,
                'deadline_start' => '2026-08-15',
                'deadline_end' => '2026-08-15',
            ])
            ->assertStatus(201);
    }

    public function test_team_admin_can_create_for_own_team_accreditation_only(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $ownAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamA->id]);
        $foreignAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamB->id]);
        $mandantLevel = $this->createAccreditation(['quota' => 20]);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$ownAccreditation->id.'/sub-accreditations', ['type' => 'park', 'quota' => 5])
            ->assertStatus(201);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$foreignAccreditation->id.'/sub-accreditations', ['type' => 'park', 'quota' => 5])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$mandantLevel->id.'/sub-accreditations', ['type' => 'park', 'quota' => 5])
            ->assertStatus(403);
    }

    public function test_foreign_mandant_accreditation_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations')
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations', ['type' => 'park', 'quota' => 5])
            ->assertStatus(404);
    }

    public function test_admin_index_lists_subs_with_counts_and_available(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 3]);
        $seat = $accreditation->subAccreditations()->create(['type' => 'seat', 'quota' => 5]);

        $this->subRequest($park, User::factory()->create());
        $this->subRequest($park, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/accreditations/'.$accreditation->id.'/sub-accreditations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $park->id)
            ->assertJsonPath('data.0.applications_count', 2)
            ->assertJsonPath('data.0.available', 1)
            ->assertJsonPath('data.1.id', $seat->id)
            ->assertJsonPath('data.1.applications_count', 0)
            ->assertJsonPath('data.1.available', 5);
    }

    public function test_team_admin_index_only_own_team_accreditation(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $ownAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamA->id]);
        $foreignAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamB->id]);

        $ownAccreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $foreignAccreditation->subAccreditations()->create(['type' => 'seat', 'quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/accreditations/'.$ownAccreditation->id.'/sub-accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'park');

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/accreditations/'.$foreignAccreditation->id.'/sub-accreditations')
            ->assertStatus(403);
    }

    public function test_super_admin_can_update_partially(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5, 'auto_approve' => false]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-accreditations/'.$sub->id, ['quota' => 30, 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.quota', 30)
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('sub_accreditations', ['id' => $sub->id, 'quota' => 30, 'active' => false]);
    }

    public function test_team_admin_cannot_update_or_delete_foreign_team_sub(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $foreignAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamB->id]);
        $sub = $foreignAccreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/sub-accreditations/'.$sub->id, ['quota' => 1])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/sub-accreditations/'.$sub->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('sub_accreditations', ['id' => $sub->id, 'quota' => 5]);
    }

    public function test_team_admin_cannot_update_or_delete_mandant_level_sub(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $sub = $this->createSubAccreditation(['quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/sub-accreditations/'.$sub->id, ['quota' => 1])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/sub-accreditations/'.$sub->id)
            ->assertStatus(403);
    }

    public function test_foreign_mandant_sub_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $sub = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-accreditations/'.$sub->id, ['quota' => 1])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/sub-accreditations/'.$sub->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('sub_accreditations', ['id' => $sub->id, 'quota' => 5]);
    }

    public function test_super_admin_can_delete_sub_accreditation(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/sub-accreditations/'.$sub->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('sub_accreditations', ['id' => $sub->id]);
    }

    public function test_deleting_accreditation_cascades_subs(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $sub = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $accreditation->delete();

        $this->assertDatabaseMissing('sub_accreditations', ['id' => $sub->id]);
    }

    public function test_deleting_sub_cascades_sub_applications(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $subApplication = $this->subRequest($sub, User::factory()->create());

        $sub->delete();

        $this->assertDatabaseMissing('sub_applications', ['id' => $subApplication->id]);
    }

    /* ---------------------------------------------------------------------
     | Public list (auth-free, active only, mandant-scoped)
     | ------------------------------------------------------------------- */

    public function test_public_list_returns_only_active_subs_of_active_main(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 3]);
        $accreditation->subAccreditations()->create(['type' => 'seat', 'quota' => 5, 'active' => false]);

        $this->subRequest($park, User::factory()->create());

        $this->getJson('/api/accreditations/'.$accreditation->id.'/sub-accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $park->id)
            ->assertJsonPath('data.0.type', 'park')
            ->assertJsonPath('data.0.applications_count', 1)
            ->assertJsonPath('data.0.available', 2);
    }

    public function test_public_list_inactive_or_foreign_main_is_404(): void
    {
        $inactive = $this->createAccreditation(['quota' => 20, 'active' => false]);
        $inactive->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $this->getJson('/api/accreditations/'.$inactive->id.'/sub-accreditations')->assertStatus(404);

        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreign = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $foreign->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $this->getJson('/api/accreditations/'.$foreign->id.'/sub-accreditations')->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Apply — main dependency, deadline, duplicate, overbooking
     | ------------------------------------------------------------------- */

    public function test_sub_apply_requires_authentication(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        $this->postJson('/api/sub-accreditations/'.$sub->id.'/apply')->assertStatus(401);
    }

    public function test_sub_apply_without_approved_main_is_422(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Approve the main accreditation first.');
    }

    public function test_sub_apply_with_requested_or_denied_main_is_422(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $user = User::factory()->create();

        Application::create([
            'accreditation_id' => $sub->accreditation_id,
            'user_id' => $user->id,
            'status' => 'requested',
        ]);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422);

        Application::query()->where('user_id', $user->id)->update(['status' => 'denied']);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422);
    }

    public function test_sub_apply_with_approved_main_links_the_approved_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $sub = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $user = User::factory()->create();
        $application = $this->approveMain($accreditation, $user);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(201)
            ->assertJsonPath('data.sub_accreditation.type', 'park')
            ->assertJsonPath('data.sub_accreditation.quota', 5)
            ->assertJsonPath('data.accreditation.id', $accreditation->id)
            ->assertJsonPath('data.accreditation.category.name', 'Presse')
            ->assertJsonPath('data.accreditation.event', null)
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.priority', false);

        $this->assertDatabaseHas('sub_applications', [
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'requested',
        ]);
    }

    public function test_sub_apply_inactive_or_foreign_sub_is_404(): void
    {
        $inactive = $this->createSubAccreditation(['quota' => 5, 'active' => false]);
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$inactive->id.'/apply')
            ->assertStatus(404);

        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $foreign = $accreditationB->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$foreign->id.'/apply')
            ->assertStatus(404);
    }

    public function test_sub_apply_deadline_window(): void
    {
        $sub = $this->createSubAccreditation([
            'quota' => 5,
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);
        $user = User::factory()->create();
        $this->approveMain($sub->accreditation, $user);

        Carbon::setTestNow('2026-08-10 00:00:00');
        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(201);

        SubApplication::query()->where('user_id', $user->id)->delete();

        // Before the window (one second early) → 422.
        Carbon::setTestNow('2026-08-09 23:59:59');
        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422);

        // The last full second of the deadline day counts → 201.
        Carbon::setTestNow('2026-08-20 23:59:59');
        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(201);

        SubApplication::query()->where('user_id', $user->id)->delete();

        // After the window → 422.
        Carbon::setTestNow('2026-08-21 00:00:00');
        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_sub_apply_duplicate_is_422(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $user = User::factory()->create();
        $this->approveMain($sub->accreditation, $user);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(201);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422);

        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $sub->id)->count());
    }

    public function test_sub_apply_duplicate_unique_constraint_blocks_second_row(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $user = User::factory()->create();
        $application = $this->approveMain($sub->accreditation, $user);

        SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'requested',
        ]);

        $this->expectException(QueryException::class);

        SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'requested',
        ]);
    }

    public function test_sub_apply_allows_overbooking(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1]);
        $users = $this->users(3);

        foreach ($users as $user) {
            $this->approveMain($sub->accreditation, $user);

            $this->actingAsApi($user)
                ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
                ->assertStatus(201);
        }

        // The quota (1) is not enforced at apply time — all three rows exist,
        // the P3d allocation engine decides who gets the single slot.
        $this->assertSame(3, SubApplication::query()->where('sub_accreditation_id', $sub->id)->count());
    }

    public function test_sub_apply_rate_limit_blocks_31st_request(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $user = User::factory()->create();
        $this->approveMain($sub->accreditation, $user);

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(201);

        // Duplicate applies stay 422 but still consume the shared `apply`
        // budget (30/min per user) — the 31st request is throttled.
        for ($i = 0; $i < 29; $i++) {
            $this->actingAsApi($user)
                ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
                ->assertStatus(422);
        }

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(429);

        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $sub->id)->count());
    }

    /* ---------------------------------------------------------------------
     | Sub allocation engine — quota, ordering, blacklist, idempotency
     | ------------------------------------------------------------------- */

    public function test_sub_approve_all_overbooking_approves_quota_and_denies_rest(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 50]);

        foreach ($this->users(75) as $user) {
            $this->subRequest($sub, $user);
        }

        $result = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(50, $result->approved);
        $this->assertSame(25, $result->denied);
        $this->assertSame(0, $result->skipped_blacklist);

        $this->assertSame(50, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'approved')->count());
        $this->assertSame(25, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'denied')->where('reason', 'Quota erschöpft')->count());
    }

    public function test_sub_vip_is_approved_before_earlier_non_vip(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $nonVip = $this->subRequest($sub, User::factory()->create());
        Carbon::setTestNow('2026-08-01 11:00:00');
        $vip = $this->subRequest($sub, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow();

        $this->subAllocation->approveAllEligible($sub);

        $this->assertDatabaseHas('sub_applications', ['id' => $vip->id, 'status' => 'approved']);
        $this->assertDatabaseHas('sub_applications', ['id' => $nonVip->id, 'status' => 'denied', 'reason' => 'Quota erschöpft']);
    }

    public function test_sub_two_vips_are_approved_fcfs_when_quota_is_one(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $first = $this->subRequest($sub, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow('2026-08-01 11:00:00');
        $second = $this->subRequest($sub, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow();

        $this->subAllocation->approveAllEligible($sub);

        $this->assertDatabaseHas('sub_applications', ['id' => $first->id, 'status' => 'approved']);
        $this->assertDatabaseHas('sub_applications', ['id' => $second->id, 'status' => 'denied', 'reason' => 'Quota erschöpft']);
    }

    public function test_sub_tiebreak_equal_created_at_resolves_by_id(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $first = $this->subRequest($sub, User::factory()->create());
        $second = $this->subRequest($sub, User::factory()->create());
        $third = $this->subRequest($sub, User::factory()->create());
        Carbon::setTestNow();

        $this->subAllocation->approveAllEligible($sub);

        $this->assertDatabaseHas('sub_applications', ['id' => $first->id, 'status' => 'approved']);
        $this->assertDatabaseHas('sub_applications', ['id' => $second->id, 'status' => 'denied']);
        $this->assertDatabaseHas('sub_applications', ['id' => $third->id, 'status' => 'denied']);
    }

    public function test_sub_blacklisted_email_is_never_approved(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $clean = User::factory()->create();

        $this->subRequest($sub, $banned);
        $this->subRequest($sub, $clean);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $result = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(1, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $this->assertDatabaseHas('sub_applications', [
            'sub_accreditation_id' => $sub->id,
            'user_id' => $banned->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
        $this->assertDatabaseHas('sub_applications', [
            'sub_accreditation_id' => $sub->id,
            'user_id' => $clean->id,
            'status' => 'approved',
        ]);
    }

    public function test_sub_blacklisted_domain_is_never_approved(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->subRequest($sub, $user);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'example.com']);

        $result = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(0, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $this->assertDatabaseHas('sub_applications', [
            'sub_accreditation_id' => $sub->id,
            'user_id' => $user->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
    }

    public function test_sub_blacklist_matches_only_own_mandant_rows(): void
    {
        $user = User::factory()->create(['email' => 'shared@example.com']);

        $subA = $this->createSubAccreditation(['quota' => 5]);
        $this->subRequest($subA, $user);

        // The blacklist row lives in mandant B → it must not affect mandant A.
        Blacklist::create(['mandant_id' => $this->mandantB->id, 'email' => 'shared@example.com']);

        $result = $this->subAllocation->approveAllEligible($subA);

        $this->assertSame(1, $result->approved);
        $this->assertSame(0, $result->denied);

        // The same user on a sub of mandant B IS blacklisted.
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $subB = $accreditationB->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $this->subRequest($subB, $user);

        $resultB = $this->subAllocation->approveAllEligible($subB);

        $this->assertSame(0, $resultB->approved);
        $this->assertSame(1, $resultB->denied);
    }

    public function test_sub_allocation_only_touches_applications_of_this_sub(): void
    {
        $subA = $this->createSubAccreditation(['quota' => 1]);
        $subB = $this->createSubAccreditation(['quota' => 1]);

        $this->subRequest($subA, User::factory()->create());
        $this->subRequest($subB, User::factory()->create());

        $this->subAllocation->approveAllEligible($subA);

        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $subA->id)->where('status', 'approved')->count());
        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $subB->id)->where('status', 'requested')->count());
    }

    public function test_sub_approve_all_is_idempotent(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->subRequest($sub, $user);
        }

        $first = $this->subAllocation->approveAllEligible($sub);
        $this->assertSame(2, $first->approved);
        $this->assertSame(3, $first->denied);

        $snapshot = SubApplication::query()->where('sub_accreditation_id', $sub->id)->orderBy('id')->pluck('status')->all();

        $second = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(0, $second->approved);
        $this->assertSame(0, $second->denied);
        $this->assertSame(0, $second->skipped_blacklist);
        $this->assertSame($snapshot, SubApplication::query()->where('sub_accreditation_id', $sub->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_sub_approve_selection_first_x_prioritizes_vip(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 10]);

        Carbon::setTestNow('2026-08-01 09:00:00');
        $a1 = $this->subRequest($sub, User::factory()->create());
        Carbon::setTestNow('2026-08-01 10:00:00');
        $a2 = $this->subRequest($sub, User::factory()->create());
        Carbon::setTestNow('2026-08-01 11:00:00');
        $vip = $this->subRequest($sub, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow('2026-08-01 12:00:00');
        $a3 = $this->subRequest($sub, User::factory()->create());
        Carbon::setTestNow();

        $result = $this->subAllocation->approveSelection($sub, 3);

        $this->assertSame(3, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(0, $result->skipped_blacklist);

        $approvedIds = SubApplication::query()->where('sub_accreditation_id', $sub->id)
            ->where('status', 'approved')->orderBy('id')->pluck('id')->all();
        $this->assertSame([$a1->id, $a2->id, $vip->id], $approvedIds);
        $this->assertDatabaseHas('sub_applications', ['id' => $a3->id, 'status' => 'requested']);
    }

    public function test_sub_approve_selection_skips_blacklisted_and_leaves_requested(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);

        $this->subRequest($sub, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $result = $this->subAllocation->approveSelection($sub, 1);

        $this->assertSame(0, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);
        $this->assertDatabaseHas('sub_applications', ['sub_accreditation_id' => $sub->id, 'user_id' => $banned->id, 'status' => 'requested']);
    }

    public function test_sub_approve_selection_limit_above_quota_is_capped_at_quota(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->subRequest($sub, $user);
        }

        $result = $this->subAllocation->approveSelection($sub, 99);

        $this->assertSame(2, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(3, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'requested')->count());
    }

    public function test_sub_approve_selection_respects_existing_approved_count(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 2]);

        $this->subRequest($sub, User::factory()->create(), ['status' => 'approved']);
        $this->subRequest($sub, User::factory()->create());
        $this->subRequest($sub, User::factory()->create());

        $result = $this->subAllocation->approveSelection($sub, 5);

        $this->assertSame(1, $result->approved);
        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'requested')->count());
    }

    public function test_sub_approve_selection_is_idempotent(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        foreach ($this->users(3) as $user) {
            $this->subRequest($sub, $user);
        }

        $this->subAllocation->approveSelection($sub, 3);

        $snapshot = SubApplication::query()->where('sub_accreditation_id', $sub->id)->orderBy('id')->pluck('status')->all();

        $second = $this->subAllocation->approveSelection($sub, 3);

        $this->assertSame(0, $second->approved);
        $this->assertSame(0, $second->denied);
        $this->assertSame(0, $second->skipped_blacklist);
        $this->assertSame($snapshot, SubApplication::query()->where('sub_accreditation_id', $sub->id)->orderBy('id')->pluck('status')->all());
    }

    /* ---------------------------------------------------------------------
     | runAutoSubAllocations — deadline boundaries, flags, command
     | ------------------------------------------------------------------- */

    public function test_sub_auto_trigger_does_not_run_before_deadline_end(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 2, 'auto_approve' => true, 'deadline_end' => '2026-08-20']);

        foreach ($this->users(2) as $user) {
            $this->subRequest($sub, $user);
        }

        Carbon::setTestNow('2026-08-20 23:59:58');
        $results = $this->subAllocation->runAutoSubAllocations();
        Carbon::setTestNow();

        $this->assertSame([], $results);
        $this->assertSame(2, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'requested')->count());
    }

    public function test_sub_auto_trigger_runs_at_exact_deadline_end(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1, 'auto_approve' => true, 'deadline_end' => '2026-08-20']);

        foreach ($this->users(2) as $user) {
            $this->subRequest($sub, $user);
        }

        Carbon::setTestNow('2026-08-20 23:59:59');
        $results = $this->subAllocation->runAutoSubAllocations();
        Carbon::setTestNow();

        $this->assertSame(['approved' => 1, 'denied' => 1], $results[$sub->id]);
    }

    public function test_sub_run_auto_only_processes_active_auto_approve_expired(): void
    {
        $autoExpired = $this->createSubAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);
        $autoNotExpired = $this->createSubAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-30']);
        $manualExpired = $this->createSubAccreditation(['quota' => 5, 'auto_approve' => false, 'deadline_end' => '2026-08-10']);
        $inactiveExpired = $this->createSubAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-10', 'active' => false]);

        foreach ([$autoExpired, $autoNotExpired, $manualExpired, $inactiveExpired] as $sub) {
            $this->subRequest($sub, User::factory()->create());
        }

        Carbon::setTestNow('2026-08-15 12:00:00');
        $results = $this->subAllocation->runAutoSubAllocations();
        Carbon::setTestNow();

        $this->assertSame([$autoExpired->id], array_keys($results));
        $this->assertSame(['approved' => 1, 'denied' => 0], $results[$autoExpired->id]);

        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $autoNotExpired->id)->where('status', 'requested')->count());
        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $manualExpired->id)->where('status', 'requested')->count());
        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $inactiveExpired->id)->where('status', 'requested')->count());
    }

    public function test_sub_auto_trigger_denies_blacklisted_with_reason_blacklist(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);
        $banned = User::factory()->create(['email' => 'banned@example.com']);

        $this->subRequest($sub, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        Carbon::setTestNow('2026-08-15 00:00:00');
        $results = $this->subAllocation->runAutoSubAllocations();
        Carbon::setTestNow();

        $this->assertSame(['approved' => 0, 'denied' => 1], $results[$sub->id]);
        $this->assertDatabaseHas('sub_applications', [
            'sub_accreditation_id' => $sub->id,
            'user_id' => $banned->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
    }

    public function test_sub_allocation_run_command_processes_expired_subs(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 1, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);

        foreach ($this->users(2) as $user) {
            $this->subRequest($sub, $user);
        }

        Carbon::setTestNow('2026-08-15 00:00:00');
        $this->artisan('allocation:run')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'approved')->count());
        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'denied')->count());
    }

    public function test_sub_allocation_run_command_is_idempotent(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 2, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);

        foreach ($this->users(3) as $user) {
            $this->subRequest($sub, $user);
        }

        Carbon::setTestNow('2026-08-15 00:00:00');
        $this->artisan('allocation:run')->assertSuccessful();
        $this->artisan('allocation:run')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(2, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'approved')->count());
        $this->assertSame(1, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'denied')->count());
    }

    /* ---------------------------------------------------------------------
     | Manual admin allocate endpoint
     | ------------------------------------------------------------------- */

    public function test_sub_allocate_user_and_verifier_are_forbidden(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'all'])
                ->assertStatus(403);
        }
    }

    public function test_sub_allocate_all_mode_by_super_admin(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->subRequest($sub, $user);
        }

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.denied', 3)
            ->assertJsonPath('data.skipped_blacklist', 0);
    }

    public function test_sub_allocate_first_mode_by_mandant_admin(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 10]);

        foreach ($this->users(5) as $user) {
            $this->subRequest($sub, $user);
        }

        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'first', 'limit' => 2])
            ->assertOk()
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.denied', 0)
            ->assertJsonPath('data.skipped_blacklist', 0);

        $this->assertSame(3, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'requested')->count());
    }

    public function test_sub_allocate_first_mode_requires_valid_limit(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 10]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'first'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'first', 'limit' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->assertSame(0, SubApplication::query()->where('sub_accreditation_id', $sub->id)->count());
    }

    public function test_sub_allocate_invalid_mode_is_422(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_sub_allocate_team_admin_can_allocate_own_team_sub(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $accreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamA->id]);
        $sub = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 1]);

        $this->subRequest($sub, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 1);
    }

    public function test_sub_allocate_team_admin_foreign_team_is_403(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $accreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamB->id]);
        $sub = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 1]);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'all'])
            ->assertStatus(403);
    }

    public function test_sub_allocate_foreign_mandant_sub_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $sub = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 1]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/sub-accreditations/'.$sub->id.'/allocate', ['mode' => 'all'])
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | P3e-B4: mandant-wide filtered list (GET /api/admin/sub-accreditations)
     | ------------------------------------------------------------------- */

    public function test_admin_sub_accreditations_filter_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/admin/sub-accreditations')->assertStatus(401);
    }

    public function test_admin_sub_accreditations_filter_endpoint_forbidden_for_user_and_verifier(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->getJson('/api/admin/sub-accreditations')
                ->assertStatus(403, "expected 403 for {$role->value} on the sub-accreditations filter index");
        }
    }

    public function test_admin_sub_accreditations_filter_scopes_to_current_mandant(): void
    {
        $accreditationA1 = $this->createAccreditation(['quota' => 20]);
        $accreditationA2 = $this->createAccreditation(['quota' => 20]);
        $subA1 = $accreditationA1->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $subA2 = $accreditationA2->subAccreditations()->create(['type' => 'seat', 'quota' => 10]);

        // Rows of another mandant never leak into the response.
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $subB = $accreditationB->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$subA1->id, $subA2->id], $ids);
        $this->assertNotContains($subB->id, $ids);

        // Same resource shape as the per-accreditation index endpoint.
        $response
            ->assertJsonPath('data.0.accreditation_id', $accreditationA1->id)
            ->assertJsonPath('data.0.type', 'park')
            ->assertJsonPath('data.0.quota', 5)
            ->assertJsonPath('data.0.applications_count', 0)
            ->assertJsonPath('data.0.available', 5)
            ->assertJsonPath('data.0.active', true);
    }

    public function test_admin_sub_accreditations_filter_includes_counts_and_available(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 3]);
        $this->subRequest($sub, User::factory()->create());
        $this->subRequest($sub, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sub->id)
            ->assertJsonPath('data.0.applications_count', 2)
            ->assertJsonPath('data.0.available', 1);
    }

    public function test_admin_sub_accreditations_filter_by_type_and_active(): void
    {
        $parkActive = $this->createSubAccreditation(['type' => 'park', 'quota' => 5]);
        $seatInactive = $this->createSubAccreditation(['type' => 'seat', 'quota' => 8, 'active' => false]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?type=seat')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $seatInactive->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $seatInactive->id);

        // Filters combine (AND semantics).
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?active=1&type=park')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $parkActive->id);

        // An unknown type is rejected like on the CRUD endpoints.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?type=banana')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_admin_sub_accreditations_filter_by_accreditation_category_event_team(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale']);
        $withRefs = $this->createAccreditation(['quota' => 20, 'event_id' => $event->id, 'team_id' => $this->teamA->id]);
        $plain = $this->createAccreditation(['quota' => 20]);

        $subWithRefs = $withRefs->subAccreditations()->create(['type' => 'park', 'quota' => 2]);
        $plain->subAccreditations()->create(['type' => 'park', 'quota' => 3]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?accreditation_id='.$withRefs->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subWithRefs->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?event_id='.$event->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subWithRefs->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?category_id='.$withRefs->category_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subWithRefs->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subWithRefs->id);

        // An id without any matching parent yields an empty list.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?team_id='.$this->teamB->id.'&event_id='.$event->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_sub_accreditations_search_filters_by_parent_category_or_event(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Sonderpresse', 'slug' => 'sonderpresse']);
        $byCategory = $this->mandantA->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 20]);
        $subCategory = $byCategory->subAccreditations()->create(['type' => 'park', 'quota' => 2]);

        $event = $this->mandantA->events()->create(['title' => 'Weltpokalfinale']);
        $byEvent = $this->createAccreditation(['quota' => 20, 'event_id' => $event->id]);
        $subEvent = $byEvent->subAccreditations()->create(['type' => 'seat', 'quota' => 4]);

        // Default helper category ("Presse-<seq>"), no event → no match below.
        $other = $this->createAccreditation(['quota' => 20]);
        $other->subAccreditations()->create(['type' => 'park', 'quota' => 6]);

        // Case-insensitive substring match on the parent category name.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?search='.rawurlencode('SONDER'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subCategory->id);

        // Match on the parent event title.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?search='.rawurlencode('pokal'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subEvent->id);

        // LIKE wildcards are escaped — "%" matches literally, nothing else.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?search='.rawurlencode('%'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // No match anywhere → empty list, not an error.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?search=nirgendwo')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_sub_accreditations_foreign_accreditation_filter_is_rejected(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreign = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);

        // super_admin/mandant_admin: a cross-mandant filter target is 422.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?accreditation_id='.$foreign->id)
            ->assertStatus(422);

        // Unknown-but-valid ids are rejected identically (no silent bypass).
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-accreditations?accreditation_id=999999')
            ->assertStatus(422);
    }

    public function test_admin_sub_accreditations_team_admin_sees_only_own_teams(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $ownAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamA->id]);
        $foreignTeamAccreditation = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamB->id]);
        $mandantLevel = $this->createAccreditation(['quota' => 20]);

        $own = $ownAccreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $foreignTeamAccreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $mandantLevel->subAccreditations()->create(['type' => 'park', 'quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/sub-accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        // The filter cannot widen the team scope (mandant-level row → 403).
        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/sub-accreditations?accreditation_id='.$mandantLevel->id)
            ->assertStatus(403);

        // Filtering within his own teams works.
        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/sub-accreditations?accreditation_id='.$ownAccreditation->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    /* ---------------------------------------------------------------------
     | Meine Sub-Akkreditierungen — list + withdraw
     | ------------------------------------------------------------------- */

    public function test_sub_applications_endpoints_require_authentication(): void
    {
        $this->getJson('/api/sub-applications')->assertStatus(401);

        $sub = $this->createSubAccreditation(['quota' => 5]);
        $subApplication = $this->subRequest($sub, User::factory()->create());

        $this->deleteJson('/api/sub-applications/'.$subApplication->id)->assertStatus(401);
    }

    public function test_sub_applications_index_lists_only_own_and_mandant_scoped(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->subRequest($sub, $me);
        $this->subRequest($sub, $other);

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.sub_accreditation.type', 'park')
            ->assertJsonPath('data.0.accreditation.id', $sub->accreditation_id);

        // A sub-application of a foreign mandant never shows up.
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $subB = $accreditationB->subAccreditations()->create(['type' => 'seat', 'quota' => 5]);
        $this->subRequest($subB, $me);

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sub_applications_index_newest_first(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $seat = $accreditation->subAccreditations()->create(['type' => 'seat', 'quota' => 5]);
        $me = User::factory()->create();

        Carbon::setTestNow('2026-08-01 10:00:00');
        $older = $this->subRequest($park, $me);
        Carbon::setTestNow('2026-08-02 10:00:00');
        $newer = $this->subRequest($seat, $me);
        Carbon::setTestNow();

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.sub_accreditation.type', 'seat')
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_sub_applications_withdraw_own_requested(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $me = User::factory()->create();
        $mine = $this->subRequest($sub, $me);

        $this->actingAsApi($me)
            ->deleteJson('/api/sub-applications/'.$mine->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('sub_applications', ['id' => $mine->id]);
    }

    public function test_sub_applications_withdraw_only_requested(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $accreditation->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $seat = $accreditation->subAccreditations()->create(['type' => 'seat', 'quota' => 5]);
        $me = User::factory()->create();

        $approved = $this->subRequest($park, $me, ['status' => 'approved']);
        $denied = $this->subRequest($seat, $me, ['status' => 'denied']);

        $this->actingAsApi($me)
            ->deleteJson('/api/sub-applications/'.$approved->id)
            ->assertStatus(422);

        $this->actingAsApi($me)
            ->deleteJson('/api/sub-applications/'.$denied->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('sub_applications', ['id' => $approved->id]);
        $this->assertDatabaseHas('sub_applications', ['id' => $denied->id]);
    }

    public function test_sub_applications_withdraw_foreign_is_404(): void
    {
        $sub = $this->createSubAccreditation(['quota' => 5]);
        $me = User::factory()->create();
        $other = User::factory()->create();

        $theirs = $this->subRequest($sub, $other);

        $this->actingAsApi($me)
            ->deleteJson('/api/sub-applications/'.$theirs->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('sub_applications', ['id' => $theirs->id]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private function createAccreditation(array $attributes = []): Accreditation
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

    private function createSubAccreditation(array $attributes = [], ?Accreditation $accreditation = null): SubAccreditation
    {
        $accreditation ??= $this->createAccreditation();

        return $accreditation->subAccreditations()->create([
            'type' => 'park',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function approveMain(Accreditation $accreditation, User $user): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    private function subRequest(SubAccreditation $sub, User $user, array $attributes = []): SubApplication
    {
        $application = Application::query()
            ->where('accreditation_id', $sub->accreditation_id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->orderBy('id')
            ->first() ?? $this->approveMain($sub->accreditation, $user);

        return SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    /**
     * @return list<User>
     */
    private function users(int $count): array
    {
        return User::factory()->count($count)->create()->all();
    }

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

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Blacklist;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Services\AllocationService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P3c allocation engine — the authoritative "who gets a quota slot" decision.
 *
 * Deterministic order: VIP before non-VIP, then FCFS (`created_at ASC`),
 * tie-break `id ASC`. Blacklisted users (email or domain, mandant-scoped) are
 * never approved. The quota (max `approved`) is never exceeded. The manual
 * trigger is the admin endpoint `POST /api/admin/accreditations/{id}/allocate`
 * (`mode=all|first`); the automatic trigger is `allocation:run` (hourly),
 * processing only active `auto_approve` accreditations whose deadline has
 * passed. The apply route is throttled per user (`throttle:apply`, 30/min).
 */
class AllocationTest extends TestCase
{
    use RefreshDatabase;

    private AllocationService $allocation;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->allocation = app(AllocationService::class);

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
     | approveAllEligible — quota, ordering, blacklist
     | ------------------------------------------------------------------- */

    public function test_approve_all_overbooking_approves_quota_and_denies_rest(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->request($accreditation, $user);
        }

        $result = $this->allocation->approveAllEligible($accreditation);

        $this->assertSame(2, $result->approved);
        $this->assertSame(3, $result->denied);
        $this->assertSame(0, $result->skipped_blacklist);

        $this->assertSame(2, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());
        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'denied')->where('reason', 'Quota erschöpft')->count());
    }

    public function test_vip_is_approved_before_earlier_non_vip(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $nonVip = $this->request($accreditation, User::factory()->create());
        Carbon::setTestNow('2026-08-01 11:00:00');
        $vip = $this->request($accreditation, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow();

        $this->allocation->approveAllEligible($accreditation);

        $this->assertDatabaseHas('applications', ['id' => $vip->id, 'status' => 'approved']);
        $this->assertDatabaseHas('applications', ['id' => $nonVip->id, 'status' => 'denied']);
    }

    public function test_two_vips_are_approved_fcfs_when_quota_is_one(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $first = $this->request($accreditation, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow('2026-08-01 11:00:00');
        $second = $this->request($accreditation, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow();

        $this->allocation->approveAllEligible($accreditation);

        $this->assertDatabaseHas('applications', ['id' => $first->id, 'status' => 'approved']);
        $this->assertDatabaseHas('applications', ['id' => $second->id, 'status' => 'denied', 'reason' => 'Quota erschöpft']);
    }

    public function test_tiebreak_equal_created_at_resolves_by_id(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $first = $this->request($accreditation, User::factory()->create());
        $second = $this->request($accreditation, User::factory()->create());
        $third = $this->request($accreditation, User::factory()->create());
        Carbon::setTestNow();

        $this->allocation->approveAllEligible($accreditation);

        $this->assertDatabaseHas('applications', ['id' => $first->id, 'status' => 'approved']);
        $this->assertDatabaseHas('applications', ['id' => $second->id, 'status' => 'denied']);
        $this->assertDatabaseHas('applications', ['id' => $third->id, 'status' => 'denied']);
    }

    public function test_blacklisted_email_is_never_approved(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $clean = User::factory()->create();

        $this->request($accreditation, $banned);
        $this->request($accreditation, $clean);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $result = $this->allocation->approveAllEligible($accreditation);

        $this->assertSame(1, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $banned->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $clean->id,
            'status' => 'approved',
        ]);
    }

    public function test_blacklisted_domain_is_never_approved(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->request($accreditation, $user);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'example.com']);

        $result = $this->allocation->approveAllEligible($accreditation);

        $this->assertSame(0, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
    }

    public function test_blacklist_matches_only_own_mandant_rows(): void
    {
        $user = User::factory()->create(['email' => 'shared@example.com']);

        $accreditationA = $this->createAccreditation(['quota' => 5]);
        $this->request($accreditationA, $user);

        // The blacklist row lives in mandant B → it must not affect mandant A.
        Blacklist::create(['mandant_id' => $this->mandantB->id, 'email' => 'shared@example.com']);

        $result = $this->allocation->approveAllEligible($accreditationA);

        $this->assertSame(1, $result->approved);
        $this->assertSame(0, $result->denied);

        // The same user on an accreditation of mandant B IS blacklisted.
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);
        $this->request($accreditationB, $user);

        $resultB = $this->allocation->approveAllEligible($accreditationB);

        $this->assertSame(0, $resultB->approved);
        $this->assertSame(1, $resultB->denied);
    }

    public function test_allocation_only_touches_applications_of_this_accreditation(): void
    {
        $accreditationA = $this->createAccreditation(['quota' => 1]);
        $accreditationB = $this->createAccreditation(['quota' => 1]);

        $this->request($accreditationA, User::factory()->create());
        $this->request($accreditationB, User::factory()->create());

        $this->allocation->approveAllEligible($accreditationA);

        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditationA->id)->where('status', 'approved')->count());
        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditationB->id)->where('status', 'requested')->count());
    }

    public function test_approve_all_is_idempotent(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->request($accreditation, $user);
        }

        $first = $this->allocation->approveAllEligible($accreditation);
        $this->assertSame(2, $first->approved);
        $this->assertSame(3, $first->denied);

        $snapshot = Application::query()->where('accreditation_id', $accreditation->id)->orderBy('id')->pluck('status')->all();

        $second = $this->allocation->approveAllEligible($accreditation);

        $this->assertSame(0, $second->approved);
        $this->assertSame(0, $second->denied);
        $this->assertSame(0, $second->skipped_blacklist);

        $this->assertSame($snapshot, Application::query()->where('accreditation_id', $accreditation->id)->orderBy('id')->pluck('status')->all());
    }

    /* ---------------------------------------------------------------------
     | approveSelection — "erste X"
     | ------------------------------------------------------------------- */

    public function test_approve_selection_first_x_prioritizes_vip(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);

        Carbon::setTestNow('2026-08-01 09:00:00');
        $a1 = $this->request($accreditation, User::factory()->create());
        Carbon::setTestNow('2026-08-01 10:00:00');
        $a2 = $this->request($accreditation, User::factory()->create());
        Carbon::setTestNow('2026-08-01 11:00:00');
        $vip = $this->request($accreditation, User::factory()->create(), ['priority' => true]);
        Carbon::setTestNow('2026-08-01 12:00:00');
        $a3 = $this->request($accreditation, User::factory()->create());
        Carbon::setTestNow('2026-08-01 13:00:00');
        $a4 = $this->request($accreditation, User::factory()->create());
        Carbon::setTestNow();

        $result = $this->allocation->approveSelection($accreditation, 3);

        $this->assertSame(3, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(0, $result->skipped_blacklist);

        // The approved set is the VIP plus the two earliest non-VIPs — the
        // later applicants (a3, a4) must stay requested.
        $approvedIds = Application::query()->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')->orderBy('id')->pluck('id')->all();
        $this->assertSame([$a1->id, $a2->id, $vip->id], $approvedIds);
        $this->assertSame(2, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());
        $this->assertDatabaseHas('applications', ['id' => $a3->id, 'status' => 'requested']);
        $this->assertDatabaseHas('applications', ['id' => $a4->id, 'status' => 'requested']);
    }

    public function test_approve_selection_is_deterministic(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);

        $this->createOrderedApplicants($accreditation, 5);

        $firstRun = $this->allocation->approveSelection($accreditation, 3);
        $firstIds = Application::query()->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')->orderBy('id')->pluck('id')->all();

        // Reset to the identical input → identical result.
        Application::query()->where('accreditation_id', $accreditation->id)->update(['status' => 'requested', 'reason' => null]);

        $secondRun = $this->allocation->approveSelection($accreditation, 3);
        $secondIds = Application::query()->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')->orderBy('id')->pluck('id')->all();

        $this->assertSame(3, $firstRun->approved);
        $this->assertSame(3, $secondRun->approved);
        $this->assertSame($firstIds, $secondIds);
    }

    public function test_approve_selection_limit_above_quota_is_capped_at_quota(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->request($accreditation, $user);
        }

        $result = $this->allocation->approveSelection($accreditation, 99);

        $this->assertSame(2, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());
    }

    public function test_approve_selection_respects_existing_approved_count(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2]);

        $this->request($accreditation, User::factory()->create(), ['status' => 'approved']);
        $this->request($accreditation, User::factory()->create());
        $this->request($accreditation, User::factory()->create());

        $result = $this->allocation->approveSelection($accreditation, 5);

        $this->assertSame(1, $result->approved);
        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());
    }

    public function test_approve_selection_skips_blacklisted_and_leaves_requested(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);

        $this->request($accreditation, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $result = $this->allocation->approveSelection($accreditation, 1);

        $this->assertSame(0, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);
        $this->assertDatabaseHas('applications', ['accreditation_id' => $accreditation->id, 'user_id' => $banned->id, 'status' => 'requested']);
    }

    public function test_approve_selection_limit_zero_or_negative_does_nothing(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $this->request($accreditation, User::factory()->create());

        foreach ([0, -3] as $limit) {
            $result = $this->allocation->approveSelection($accreditation, $limit);

            $this->assertSame(0, $result->approved);
            $this->assertSame(0, $result->denied);
            $this->assertSame(0, $result->skipped_blacklist);
        }

        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());
    }

    public function test_approve_selection_is_idempotent(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        foreach ($this->users(3) as $user) {
            $this->request($accreditation, $user);
        }

        // A limit covering every candidate exhausts the requested pool, so a
        // second run has nothing left to approve.
        $this->allocation->approveSelection($accreditation, 3);

        $snapshot = Application::query()->where('accreditation_id', $accreditation->id)->orderBy('id')->pluck('status')->all();

        $second = $this->allocation->approveSelection($accreditation, 3);

        $this->assertSame(0, $second->approved);
        $this->assertSame(0, $second->denied);
        $this->assertSame(0, $second->skipped_blacklist);
        $this->assertSame($snapshot, Application::query()->where('accreditation_id', $accreditation->id)->orderBy('id')->pluck('status')->all());
    }

    /* ---------------------------------------------------------------------
     | runAutoAllocations — deadline boundaries, auto_approve, command
     | ------------------------------------------------------------------- */

    public function test_auto_trigger_does_not_run_before_deadline_end(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2, 'auto_approve' => true, 'deadline_end' => '2026-08-20']);

        foreach ($this->users(2) as $user) {
            $this->request($accreditation, $user);
        }

        Carbon::setTestNow('2026-08-20 23:59:58');
        $results = $this->allocation->runAutoAllocations();
        Carbon::setTestNow();

        $this->assertSame([], $results);
        $this->assertSame(2, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());
    }

    public function test_auto_trigger_runs_at_exact_deadline_end(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1, 'auto_approve' => true, 'deadline_end' => '2026-08-20']);

        foreach ($this->users(2) as $user) {
            $this->request($accreditation, $user);
        }

        Carbon::setTestNow('2026-08-20 23:59:59');
        $results = $this->allocation->runAutoAllocations();
        Carbon::setTestNow();

        $this->assertSame(['approved' => 1, 'denied' => 1], $results[$accreditation->id]);
    }

    public function test_auto_trigger_runs_after_deadline_end(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1, 'auto_approve' => true, 'deadline_end' => '2026-08-20']);

        foreach ($this->users(2) as $user) {
            $this->request($accreditation, $user);
        }

        Carbon::setTestNow('2026-08-21 00:00:00');
        $results = $this->allocation->runAutoAllocations();
        Carbon::setTestNow();

        $this->assertSame(['approved' => 1, 'denied' => 1], $results[$accreditation->id]);
    }

    public function test_run_auto_allocations_only_processes_auto_approve_expired_active(): void
    {
        $autoExpired = $this->createAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);
        $autoNotExpired = $this->createAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-30']);
        $manualExpired = $this->createAccreditation(['quota' => 5, 'auto_approve' => false, 'deadline_end' => '2026-08-10']);
        $inactiveExpired = $this->createAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-10', 'active' => false]);

        foreach ([$autoExpired, $autoNotExpired, $manualExpired, $inactiveExpired] as $accreditation) {
            $this->request($accreditation, User::factory()->create());
        }

        Carbon::setTestNow('2026-08-15 12:00:00');
        $results = $this->allocation->runAutoAllocations();
        Carbon::setTestNow();

        $this->assertSame([$autoExpired->id], array_keys($results));
        $this->assertSame(['approved' => 1, 'denied' => 0], $results[$autoExpired->id]);

        $this->assertSame(1, Application::query()->where('accreditation_id', $autoNotExpired->id)->where('status', 'requested')->count());
        $this->assertSame(1, Application::query()->where('accreditation_id', $manualExpired->id)->where('status', 'requested')->count());
        $this->assertSame(1, Application::query()->where('accreditation_id', $inactiveExpired->id)->where('status', 'requested')->count());
    }

    public function test_auto_trigger_denies_blacklisted_with_reason_blacklist(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);
        $banned = User::factory()->create(['email' => 'banned@example.com']);

        $this->request($accreditation, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        Carbon::setTestNow('2026-08-15 00:00:00');
        $results = $this->allocation->runAutoAllocations();
        Carbon::setTestNow();

        $this->assertSame(['approved' => 0, 'denied' => 1], $results[$accreditation->id]);
        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $banned->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
    }

    public function test_allocation_run_command_processes_expired_accreditations(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);

        foreach ($this->users(2) as $user) {
            $this->request($accreditation, $user);
        }

        Carbon::setTestNow('2026-08-15 00:00:00');
        $this->artisan('allocation:run')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());
        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'denied')->count());
    }

    public function test_allocation_run_command_is_idempotent(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2, 'auto_approve' => true, 'deadline_end' => '2026-08-10']);

        foreach ($this->users(3) as $user) {
            $this->request($accreditation, $user);
        }

        Carbon::setTestNow('2026-08-15 00:00:00');
        $this->artisan('allocation:run')->assertSuccessful();
        $this->artisan('allocation:run')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(2, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());
        $this->assertSame(1, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'denied')->count());
    }

    /* ---------------------------------------------------------------------
     | Manual admin endpoint
     | ------------------------------------------------------------------- */

    public function test_allocate_endpoint_requires_authentication(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertStatus(401);
    }

    public function test_allocate_endpoint_user_and_verifier_are_forbidden(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
                ->assertStatus(403);
        }
    }

    public function test_allocate_all_mode_by_super_admin(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2]);

        foreach ($this->users(5) as $user) {
            $this->request($accreditation, $user);
        }

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.denied', 3)
            ->assertJsonPath('data.skipped_blacklist', 0);
    }

    public function test_allocate_first_mode_by_mandant_admin(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);

        foreach ($this->users(5) as $user) {
            $this->request($accreditation, $user);
        }

        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'first', 'limit' => 2])
            ->assertOk()
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.denied', 0)
            ->assertJsonPath('data.skipped_blacklist', 0);

        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());
    }

    public function test_allocate_all_mode_ignores_limit(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1]);

        foreach ($this->users(2) as $user) {
            $this->request($accreditation, $user);
        }

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all', 'limit' => 999])
            ->assertOk()
            ->assertJsonPath('data.approved', 1)
            ->assertJsonPath('data.denied', 1);
    }

    public function test_allocate_first_mode_requires_valid_limit(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'first'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'first', 'limit' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');

        $this->assertSame(0, Application::query()->where('accreditation_id', $accreditation->id)->count());
    }

    public function test_allocate_invalid_mode_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_allocate_team_admin_can_allocate_own_team_accreditation(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $accreditation = $this->createAccreditation(['quota' => 1, 'team_id' => $this->teamA->id]);

        $this->request($accreditation, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 1);
    }

    public function test_allocate_team_admin_foreign_team_is_403(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $accreditation = $this->createAccreditation(['quota' => 1, 'team_id' => $this->teamB->id]);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertStatus(403);
    }

    public function test_allocate_foreign_mandant_accreditation_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertStatus(404);
    }

    public function test_auto_approve_false_not_processed_but_manual_trigger_works(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 2, 'auto_approve' => false, 'deadline_end' => '2026-08-10']);

        foreach ($this->users(3) as $user) {
            $this->request($accreditation, $user);
        }

        Carbon::setTestNow('2026-08-15 00:00:00');
        $this->assertSame([], $this->allocation->runAutoAllocations());
        Carbon::setTestNow();

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.denied', 1)
            ->assertJsonPath('data.skipped_blacklist', 0);
    }

    /* ---------------------------------------------------------------------
     | Apply rate limit (P3b-F1)
     | ------------------------------------------------------------------- */

    public function test_apply_rate_limit_blocks_31st_request(): void
    {
        $user = User::factory()->create();

        $accreditations = [];
        for ($i = 0; $i < 31; $i++) {
            $accreditations[] = $this->createAccreditation(['quota' => 5]);
        }

        for ($i = 0; $i < 30; $i++) {
            $this->actingAsApi($user)
                ->postJson('/api/accreditations/'.$accreditations[$i]->id.'/apply')
                ->assertStatus(201);
        }

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditations[30]->id.'/apply')
            ->assertStatus(429);

        $this->assertSame(30, Application::query()->count());
    }

    /* ---------------------------------------------------------------------
     | Coverage gaps (P3c-F4) — blacklist precedence, case-insensitivity,
     | pre-existing approvals, exact-fit quota
     | ------------------------------------------------------------------- */

    public function test_vip_that_is_blacklisted_is_denied_not_approved(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $vipBanned = User::factory()->create(['email' => 'vip-banned@example.com']);
        $clean = User::factory()->create();

        $this->request($accreditation, $vipBanned, ['priority' => true]);
        $this->request($accreditation, $clean);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'vip-banned@example.com']);

        $result = $this->allocation->approveAllEligible($accreditation);

        // Blacklist precedence: the VIP is denied (reason Blacklist) despite
        // priority, never approved.
        $this->assertSame(1, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $vipBanned->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $clean->id,
            'status' => 'approved',
        ]);
    }

    public function test_blacklisted_email_match_is_case_insensitive(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        // Blacklist stored lowercase; the applicant email uses mixed case.
        $user = User::factory()->create(['email' => 'MixedCase@Example.COM']);

        $this->request($accreditation, $user);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'mixedcase@example.com']);

        $result = $this->allocation->approveAllEligible($accreditation);

        $this->assertSame(0, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
    }

    public function test_approve_all_exact_quota_approves_all_without_surplus(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 4]);

        foreach ($this->users(4) as $user) {
            $this->request($accreditation, $user);
        }

        $result = $this->allocation->approveAllEligible($accreditation);

        // Applicants == quota → every one approved, none denied/surplus.
        $this->assertSame(4, $result->approved);
        $this->assertSame(0, $result->denied);
        $this->assertSame(0, $result->skipped_blacklist);
        $this->assertSame(0, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'denied')->count());
    }

    public function test_approve_all_is_idempotent_with_pre_existing_approved(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 3]);

        // One row already approved (e.g. a prior single-approve admin action).
        $this->request($accreditation, User::factory()->create(), ['status' => 'approved']);
        foreach ($this->users(4) as $user) {
            $this->request($accreditation, $user);
        }

        $first = $this->allocation->approveAllEligible($accreditation);

        // Quota 3, 1 already approved → only 2 more approved, 2 denied.
        $this->assertSame(2, $first->approved);
        $this->assertSame(2, $first->denied);
        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());

        $second = $this->allocation->approveAllEligible($accreditation);

        // Re-running must not duplicate approvals or alter any status.
        $this->assertSame(0, $second->approved);
        $this->assertSame(0, $second->denied);
        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());
        $this->assertSame(2, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'denied')->count());
    }

    public function test_approve_selection_is_idempotent_with_pre_existing_approved(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 3]);

        $this->request($accreditation, User::factory()->create(), ['status' => 'approved']);
        foreach ($this->users(2) as $user) {
            $this->request($accreditation, $user);
        }

        $first = $this->allocation->approveSelection($accreditation, 2);

        // 1 already approved + 2 newly approved = quota; no requested left.
        $this->assertSame(2, $first->approved);
        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());
        $this->assertSame(0, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'requested')->count());

        $second = $this->allocation->approveSelection($accreditation, 2);

        $this->assertSame(0, $second->approved);
        $this->assertSame(3, Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count());
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

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
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function request(Accreditation $accreditation, User $user, array $attributes = []): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
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

    /**
     * Distinct `created_at` timestamps (one per second) so the FCFS order is
     * unambiguous.
     */
    private function createOrderedApplicants(Accreditation $accreditation, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Carbon::setTestNow('2026-08-01 '.str_pad((string) (9 + $i), 2, '0', STR_PAD_LEFT).':00:00');
            $this->request($accreditation, User::factory()->create());
        }

        Carbon::setTestNow();
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

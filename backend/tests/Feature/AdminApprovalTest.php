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
use App\Models\UserMedia;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P3e admin approval view — the single-application actions behind
 * `GET/PUT /api/admin/applications` and `/api/admin/sub-applications` plus
 * the admin media list/delivery.
 *
 * Every status change goes through `AllocationService` /
 * `SubAllocationService` (the central status writers): approve respects the
 * blacklist and the quota, deny requires a reason, only the documented status
 * transitions are legal, priority is a direct field update. team_admin is
 * scoped to his own team's accreditations (mandant-level rows read-only).
 */
class AdminApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');

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
     | Auth & gates
     | ------------------------------------------------------------------- */

    public function test_admin_approval_endpoints_require_authentication(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());
        $sub = $this->createSub($accreditation, 'park', 5);
        $subApplication = $this->createSubApplication($sub, User::factory()->create());

        $this->getJson('/api/admin/applications')->assertStatus(401);
        $this->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])->assertStatus(401);
        $this->getJson('/api/admin/applications/'.$application->id.'/media')->assertStatus(401);
        $this->getJson('/api/admin/user-media/'.$subApplication->id)->assertStatus(401);
        $this->getJson('/api/admin/sub-applications')->assertStatus(401);
        $this->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'approved'])->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/applications')
                ->assertStatus(403, "expected 403 for {$role->value} on applications index");

            $this->actingAsApi($user)->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
                ->assertStatus(403, "expected 403 for {$role->value} on applications update");
        }
    }

    /* ---------------------------------------------------------------------
     | Applications list — scope, filters, ordering
     | ------------------------------------------------------------------- */

    public function test_applications_index_is_mandant_scoped_and_newest_first(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $older = $this->makeApplication($accreditation, User::factory()->create());
        Carbon::setTestNow('2026-08-02 10:00:00');
        $newer = $this->makeApplication($accreditation, User::factory()->create());
        Carbon::setTestNow();

        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreignAccreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);
        $foreignApplication = $this->makeApplication($foreignAccreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.user.email', $newer->user->email)
            ->assertJsonPath('data.0.accreditation.id', $accreditation->id)
            ->assertJsonPath('data.0.accreditation.category.name', 'Presse')
            ->assertJsonPath('data.0.accreditation.quota', 10);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications')
            ->assertJsonMissing(['id' => $foreignApplication->id]);
    }

    public function test_applications_index_available_reflects_approved_count(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 3]);

        $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);
        $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);
        $requested = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications')
            ->assertOk()
            ->assertJsonPath('data.0.accreditation.available', 1)
            ->assertJsonPath('data.0.accreditation.id', $accreditation->id)
            ->assertJsonPath('data.0.id', $requested->id);
    }

    public function test_applications_index_filters_by_accreditation_status_and_search(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);
        $jane = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $john = User::factory()->create(['name' => 'John Smith', 'email' => 'john@example.com']);

        $approved = $this->makeApplication($accreditation, $jane, ['status' => 'approved']);
        $requested = $this->makeApplication($accreditation, $john, ['status' => 'requested']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications?accreditation_id='.$accreditation->id.'&status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications?status=requested')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requested->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications?search=jane')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications?search=example.com')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_applications_index_foreign_accreditation_filter_is_422(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreignAccreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications?accreditation_id='.$foreignAccreditation->id)
            ->assertStatus(422);
    }

    public function test_applications_index_invalid_status_is_422(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications?status=banana')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_team_admin_sees_only_own_teams_applications(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $own = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamA->id]);
        $foreign = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamB->id]);
        $mandantLevel = $this->createAccreditation(['quota' => 5]);

        $ownApp = $this->makeApplication($own, User::factory()->create());
        $this->makeApplication($foreign, User::factory()->create());
        $this->makeApplication($mandantLevel, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownApp->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/applications?accreditation_id='.$own->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/applications?accreditation_id='.$foreign->id)
            ->assertStatus(403);
    }

    public function test_team_admin_cannot_update_foreign_team_application(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $foreign = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamB->id]);
        $app = $this->makeApplication($foreign, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/applications/'.$app->id, ['status' => 'approved'])
            ->assertStatus(403);

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'status' => 'requested']);
    }

    public function test_application_of_foreign_mandant_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreignAccreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);
        $foreignApplication = $this->makeApplication($foreignAccreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$foreignApplication->id, ['status' => 'approved'])
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Single actions — approve / deny / priority (main applications)
     | ------------------------------------------------------------------- */

    public function test_approve_requested_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['reason' => 'old-reason']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.accreditation.available', 4);

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'approved', 'reason' => null]);
    }

    public function test_approve_blacklisted_user_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $application = $this->makeApplication($accreditation, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is blacklisted');

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'requested']);
    }

    public function test_approve_blacklisted_domain_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $banned = User::factory()->create(['email' => 'user@blocked.org']);
        $application = $this->makeApplication($accreditation, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'blocked.org']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is blacklisted');
    }

    public function test_approve_with_full_quota_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 1]);

        $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);
        $requested = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$requested->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Quota erschöpft');

        $this->assertDatabaseHas('applications', ['id' => $requested->id, 'status' => 'requested']);
    }

    public function test_deny_requested_application_requires_reason(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'denied'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A reason is required when denying an application.');

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'requested']);
    }

    public function test_deny_requested_application_with_reason(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'denied', 'reason' => 'Unterlagen fehlen'])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied')
            ->assertJsonPath('data.reason', 'Unterlagen fehlen');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'denied',
            'reason' => 'Unterlagen fehlen',
        ]);
    }

    public function test_revoke_approved_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'denied', 'reason' => 'Widerruf'])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied')
            ->assertJsonPath('data.reason', 'Widerruf');
    }

    public function test_reapprove_denied_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'denied', 'reason' => 'Zu spät']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reason', null);
    }

    public function test_approve_an_already_approved_application_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_deny_an_already_denied_application_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'denied', 'reason' => 'x']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'denied', 'reason' => 'nochmal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_invalid_status_value_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'blacklisted'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_priority_can_be_set_and_cleared(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['priority' => true])
            ->assertOk()
            ->assertJsonPath('data.priority', true)
            ->assertJsonPath('data.status', 'requested');

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'priority' => true]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['priority' => false])
            ->assertOk()
            ->assertJsonPath('data.priority', false);

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'priority' => false]);
    }

    public function test_priority_and_status_in_one_request(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved', 'priority' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.priority', true);

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'approved', 'priority' => true]);
    }

    public function test_team_admin_can_approve_own_team_application(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $own = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamA->id]);
        $application = $this->makeApplication($own, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_blacklist_is_not_retroactive(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $approved = $this->makeApplication($accreditation, $banned, ['status' => 'approved']);

        // The blacklist is created AFTER the approval — the existing approved
        // row must stay approved (no retroactive effect).
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $this->assertDatabaseHas('applications', ['id' => $approved->id, 'status' => 'approved']);

        // New allocations are blocked: a fresh application of the same user
        // cannot be approved any more.
        $accreditationB = $this->createAccreditation(['quota' => 5]);
        $newApplication = $this->makeApplication($accreditationB, $banned);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$newApplication->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is blacklisted');

        // ... and a bulk allocation denies the blacklisted user with the
        // documented reason instead of approving him.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditationB->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 0)
            ->assertJsonPath('data.denied', 1);

        $this->assertDatabaseHas('applications', [
            'id' => $newApplication->id,
            'status' => 'denied',
            'reason' => 'Blacklist',
        ]);
    }

    /* ---------------------------------------------------------------------
     | Admin media — list + delivery
     | ------------------------------------------------------------------- */

    public function test_admin_media_list_returns_applicant_media(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $applicant = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $application = $this->makeApplication($accreditation, $applicant);

        $portrait = $this->storeMedia($applicant, 'portrait');
        $pressId = $this->storeMedia($applicant, 'press_id');
        $attachment = $this->storeMedia($applicant, 'attachment');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications/'.$application->id.'/media')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            // Ordered by type ASC: attachment, portrait, press_id.
            ->assertJsonPath('data.0.id', $attachment->id)
            ->assertJsonPath('data.0.type', 'attachment')
            ->assertJsonPath('data.0.url', route('api.admin.user-media.show', ['media' => $attachment->id]))
            ->assertJsonPath('data.1.id', $portrait->id)
            ->assertJsonPath('data.1.type', 'portrait')
            ->assertJsonPath('data.2.id', $pressId->id)
            ->assertJsonPath('data.2.type', 'press_id');
    }

    public function test_admin_media_list_is_scoped_to_the_application_user(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $applicant = User::factory()->create();
        $other = User::factory()->create();
        $application = $this->makeApplication($accreditation, $applicant);

        $applicantMedia = $this->storeMedia($applicant, 'portrait');
        $this->storeMedia($other, 'portrait');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications/'.$application->id.'/media')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $applicantMedia->id);
    }

    public function test_admin_media_list_foreign_application_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreignAccreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);
        $foreignApplication = $this->makeApplication($foreignAccreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications/'.$foreignApplication->id.'/media')
            ->assertStatus(404);
    }

    public function test_owner_without_admin_role_cannot_access_admin_media_list(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $applicant = User::factory()->create();
        $application = $this->makeApplication($accreditation, $applicant);
        $this->storeMedia($applicant, 'portrait');

        $this->actingAsApi($applicant)
            ->getJson('/api/admin/applications/'.$application->id.'/media')
            ->assertStatus(403);
    }

    public function test_admin_can_deliver_media_inline(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $applicant = User::factory()->create();
        $this->makeApplication($accreditation, $applicant);
        $portrait = $this->storeMedia($applicant, 'portrait');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/user-media/'.$portrait->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeaderContains('Content-Disposition', 'inline');
    }

    public function test_admin_delivery_of_media_without_application_in_mandant_is_404(): void
    {
        // The media row exists, but its owner has no application in the
        // current mandant → not reachable via the admin delivery route.
        $foreignUser = User::factory()->create();
        $media = $this->storeMedia($foreignUser, 'portrait');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/user-media/'.$media->id)
            ->assertStatus(404);
    }

    public function test_owner_without_admin_role_cannot_use_admin_delivery(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $applicant = User::factory()->create();
        $this->makeApplication($accreditation, $applicant);
        $portrait = $this->storeMedia($applicant, 'portrait');

        $this->actingAsApi($applicant)
            ->getJson('/api/admin/user-media/'.$portrait->id)
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------------
     | Sub-applications — list + single actions
     | ------------------------------------------------------------------- */

    public function test_sub_applications_index_is_mandant_scoped_with_filters(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 5);
        $seat = $this->createSub($accreditation, 'seat', 5);

        $parkApp = $this->createSubApplication($park, User::factory()->create());
        $seatApp = $this->createSubApplication($seat, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sub_accreditation.id', $seat->id)
            ->assertJsonPath('data.1.sub_accreditation.type', 'park')
            ->assertJsonPath('data.1.sub_accreditation.quota', 5)
            ->assertJsonPath('data.1.accreditation.id', $accreditation->id)
            ->assertJsonPath('data.1.accreditation.category.name', 'Presse');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications?sub_accreditation_id='.$park->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $parkApp->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications?status=requested')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications?status=approved')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications?status=banana')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame($seatApp->id, $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications')->json('data.0.id'));
    }

    public function test_sub_applications_index_foreign_sub_accreditation_filter_is_422(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $subB = $this->createSub($accreditationB, 'park', 5);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/sub-applications?sub_accreditation_id='.$subB->id)
            ->assertStatus(422);
    }

    public function test_team_admin_sees_only_own_teams_sub_applications(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $own = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamA->id]);
        $foreign = $this->createAccreditation(['quota' => 20, 'team_id' => $this->teamB->id]);

        $ownSub = $this->createSub($own, 'park', 5);
        $foreignSub = $this->createSub($foreign, 'park', 5);

        $ownApp = $this->createSubApplication($ownSub, User::factory()->create());
        $this->createSubApplication($foreignSub, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/sub-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownApp->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/sub-applications?sub_accreditation_id='.$foreignSub->id)
            ->assertStatus(403);
    }

    public function test_sub_approve_requested_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 5);
        $subApplication = $this->createSubApplication($park, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.sub_accreditation.available', 4);

        $this->assertDatabaseHas('sub_applications', ['id' => $subApplication->id, 'status' => 'approved']);
    }

    public function test_sub_approve_blacklisted_user_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 5);
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $subApplication = $this->createSubApplication($park, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is blacklisted');

        $this->assertDatabaseHas('sub_applications', ['id' => $subApplication->id, 'status' => 'requested']);
    }

    public function test_sub_approve_with_full_quota_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 1);

        $this->createSubApplication($park, User::factory()->create(), ['status' => 'approved']);
        $requested = $this->createSubApplication($park, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$requested->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Quota erschöpft');
    }

    public function test_sub_deny_requires_reason(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 5);
        $subApplication = $this->createSubApplication($park, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'denied'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A reason is required when denying a sub-application.');
    }

    public function test_sub_deny_with_reason_and_revoke(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 5);
        $subApplication = $this->createSubApplication($park, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'denied', 'reason' => 'Keine Parkfläche'])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied')
            ->assertJsonPath('data.reason', 'Keine Parkfläche');

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reason', null);
    }

    public function test_sub_priority_can_be_set(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $park = $this->createSub($accreditation, 'park', 5);
        $subApplication = $this->createSubApplication($park, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['priority' => true])
            ->assertOk()
            ->assertJsonPath('data.priority', true)
            ->assertJsonPath('data.status', 'requested');

        $this->assertDatabaseHas('sub_applications', ['id' => $subApplication->id, 'priority' => true]);
    }

    public function test_sub_application_of_foreign_mandant_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditationB = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 20]);
        $subB = $this->createSub($accreditationB, 'park', 5);
        $subApplication = $this->createSubApplication($subB, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/sub-applications/'.$subApplication->id, ['status' => 'approved'])
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | P3d fixes — English apply errors + inactive main accreditation
     | ------------------------------------------------------------------- */

    public function test_sub_apply_without_approved_main_uses_english_message(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 20]);
        $sub = $this->createSub($accreditation, 'park', 5);
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Approve the main accreditation first.');
    }

    public function test_sub_apply_on_inactive_main_accreditation_is_404(): void
    {
        $inactiveAccreditation = $this->createAccreditation(['quota' => 20, 'active' => false]);
        $sub = $this->createSub($inactiveAccreditation, 'park', 5, ['active' => true]);
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->postJson('/api/sub-accreditations/'.$sub->id.'/apply')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private static int $mediaCount = 0;

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

    private function makeApplication(Accreditation $accreditation, User $user, array $attributes = []): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    private function createSub(Accreditation $accreditation, string $type, int $quota, array $attributes = []): SubAccreditation
    {
        return $accreditation->subAccreditations()->create([
            'type' => $type,
            'quota' => $quota,
            ...$attributes,
        ]);
    }

    private function createSubApplication(SubAccreditation $sub, User $user, array $attributes = []): SubApplication
    {
        $application = Application::query()
            ->where('accreditation_id', $sub->accreditation_id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->orderBy('id')
            ->first();

        if ($application === null) {
            $application = Application::create([
                'accreditation_id' => $sub->accreditation_id,
                'user_id' => $user->id,
                'status' => 'approved',
                'priority' => false,
            ]);
        }

        return SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    private function storeMedia(User $user, string $type): UserMedia
    {
        $media = UserMedia::create([
            'user_id' => $user->id,
            'type' => $type,
            'path' => "user-media/verband-a/{$user->id}/{$type}/file-{$type}-".self::$mediaCount.'.png',
            'mime' => 'image/png',
            'size' => 123,
            'original_name' => $type.'.png',
        ]);

        Storage::disk('private')->put($media->path, 'fake-image');

        self::$mediaCount++;

        return $media;
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

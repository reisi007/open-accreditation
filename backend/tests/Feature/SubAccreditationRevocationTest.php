<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\User;
use App\Services\AllocationService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * WP-3-c (R-D5, review 2026-09-26): a sub-accreditation may only ever sit on
 * top of an **approved** main application (D9).
 *
 * Before this finding that rule was enforced in exactly one place — the apply
 * endpoint. `approveSubApplication` / `denySubApplication` never looked at the
 * parent, and `denyApplication` revoked an `approved` main row without touching
 * its sub rows. The scenario: a user is approved on accreditation X and holds an
 * approved `park` sub-accreditation; the admin revokes X; the sub row stayed
 * `approved` and `GET /api/sub-applications/{id}/wallet` kept handing out a
 * valid `.pkpass` for a Parkkarte whose accreditation no longer exists.
 *
 * The fix has two layers, and this class covers both:
 *
 * 1. **The cascade** — `AllocationService::denyApplication()` denies every
 *    `approved` sub-row of the revoked main application, with its own reason
 *    (`AllocationService::REASON_PARENT_REVOKED`), in the same transaction as
 *    the parent status write. Data level: the invalid row disappears.
 * 2. **The wallet guard** — `WalletController` refuses to issue a sub-pass
 *    unless the main application is approved, and answers **410 Gone**
 *    otherwise. Defence in depth for every state the cascade cannot reach
 *    (rows predating it, direct DB writes, out-of-band re-approvals).
 *
 * Why 410 and not 404: the sub-row exists, belongs to the caller and is listed
 * in `GET /api/sub-applications`, so a 404 would be a lie — and it would leak
 * nothing the pre-existing 422 branch does not already tell. 410 is the honest
 * status for a resource that existed and is gone; the recorded choice lives in
 * `features/accreditation/01-allocation-engine.md`.
 */
class SubAccreditationRevocationTest extends TestCase
{
    use RefreshDatabase;

    private AllocationService $allocation;

    private Mandant $mandant;

    private static int $categorySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->allocation = app(AllocationService::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | 1. The cascade
     | ------------------------------------------------------------------- */

    public function test_revoking_an_approved_main_application_denies_its_approved_sub_applications(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);
        $seat = $this->sub($accreditation, 'seat', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);
        $parkRow = $this->approvedSubApplication($park, $application, $me);
        $seatRow = $this->approvedSubApplication($seat, $application, $me);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, [
                'status' => 'denied',
                'reason' => 'Widerruf',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied')
            ->assertJsonPath('data.reason', 'Widerruf');

        // The sub rows lose their foundation and carry the engine's OWN reason —
        // the applicant of the Parkkarte must read why *their* row fell.
        $this->assertDatabaseHas('sub_applications', [
            'id' => $parkRow->id,
            'status' => 'denied',
            'reason' => AllocationService::REASON_PARENT_REVOKED,
        ]);
        $this->assertDatabaseHas('sub_applications', [
            'id' => $seatRow->id,
            'status' => 'denied',
            'reason' => AllocationService::REASON_PARENT_REVOKED,
        ]);
    }

    public function test_the_cascade_keeps_the_pending_and_final_sub_rows_untouched(): void
    {
        $accreditation = $this->accreditation();

        // One sub-accreditation per state — the unique
        // `(sub_accreditation_id, application_id)` constraint allows only one
        // sub row per main application per sub-accreditation.
        $park = $this->sub($accreditation, 'park', 5);
        $seat = $this->sub($accreditation, 'seat', 5);
        $vip = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);

        $approved = $this->approvedSubApplication($park, $application, $me);
        $requested = SubApplication::create([
            'sub_accreditation_id' => $seat->id,
            'application_id' => $application->id,
            'user_id' => $me->id,
            'status' => 'requested',
            'priority' => false,
        ]);
        $alreadyDenied = SubApplication::create([
            'sub_accreditation_id' => $vip->id,
            'application_id' => $application->id,
            'user_id' => $me->id,
            'status' => 'denied',
            'reason' => 'Keine Parkfläche',
            'priority' => false,
        ]);

        $this->allocation->denyApplication($application, 'Widerruf');

        $this->assertDatabaseHas('sub_applications', [
            'id' => $approved->id,
            'status' => 'denied',
            'reason' => AllocationService::REASON_PARENT_REVOKED,
        ]);

        // A pending applicant keeps his row: denying it is an admin decision,
        // and the admin can re-approve the main application first. A `denied`
        // row is final and must keep its own reason.
        $this->assertDatabaseHas('sub_applications', [
            'id' => $requested->id,
            'status' => 'requested',
            'reason' => null,
        ]);
        $this->assertDatabaseHas('sub_applications', [
            'id' => $alreadyDenied->id,
            'status' => 'denied',
            'reason' => 'Keine Parkfläche',
        ]);
    }

    public function test_the_cascade_only_touches_sub_applications_of_the_revoked_row(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        // Two main applications on the same accreditation (one per user, the
        // `(accreditation_id, user_id)` unique constraint allows no more).
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $revoked = $this->approvedApplication($accreditation, $mine);
        $untouched = $this->approvedApplication($accreditation, $theirs);

        $revokedRow = $this->approvedSubApplication($park, $revoked, $mine);
        $untouchedRow = $this->approvedSubApplication($park, $untouched, $theirs);

        $this->allocation->denyApplication($revoked, 'Widerruf');

        $this->assertDatabaseHas('sub_applications', [
            'id' => $revokedRow->id,
            'status' => 'denied',
            'reason' => AllocationService::REASON_PARENT_REVOKED,
        ]);
        $this->assertDatabaseHas('sub_applications', [
            'id' => $untouchedRow->id,
            'status' => 'approved',
        ]);
    }

    public function test_denying_a_requested_main_application_cascades_nothing_because_it_had_no_valid_sub(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $me->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        // A sub row can only exist on an approved main application, so a
        // `requested` main row has no valid sub row to revoke. The cascade is
        // still executed (and stays a no-op) rather than skipped, so the write
        // path is identical for both transitions.
        $this->allocation->denyApplication($application, 'Unterlagen fehlen');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'denied',
            'reason' => 'Unterlagen fehlen',
        ]);
        $this->assertSame(0, SubApplication::query()->where('sub_accreditation_id', $park->id)->count());
    }

    public function test_the_cascade_is_atomic_with_the_parent_status_write(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);
        $this->approvedSubApplication($park, $application, $me);

        // Fail the cascade write. The parent status must survive untouched —
        // otherwise the applicant would hold a denied main accreditation whose
        // Parkkarte is still approved (exactly the inconsistency R-D5 removes).
        $injected = false;

        DB::listen(static function (QueryExecuted $query) use (&$injected): void {
            if (str_starts_with($query->sql, 'update "sub_applications"')
                && in_array(AllocationService::REASON_PARENT_REVOKED, $query->bindings, true)) {
                $injected = true;

                throw new RuntimeException('cascade exploded');
            }
        });

        $thrown = null;

        try {
            $this->allocation->denyApplication($application, 'Widerruf');
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'the injected cascade failure never surfaced');
        $this->assertTrue($injected, 'precondition: the cascade write really ran');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'approved',
            'reason' => null,
        ]);
        $this->assertSame(1, SubApplication::query()->where('status', 'approved')->count());
    }

    /* ---------------------------------------------------------------------
     | 2. The wallet guard (410 Gone)
     | ------------------------------------------------------------------- */

    public function test_the_wallet_refuses_a_sub_pass_after_the_main_accreditation_was_revoked(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);
        $row = $this->approvedSubApplication($park, $application, $me);

        // The happy path first, so the 410 below can only be caused by the revoke.
        $this->actingAsApi($me)
            ->get('/api/sub-applications/'.$row->id.'/wallet')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.pkpass');

        $this->allocation->denyApplication($application, 'Widerruf');

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications/'.$row->id.'/wallet')
            ->assertStatus(410)
            ->assertJsonPath('message', 'The main accreditation was withdrawn, this wallet pass is no longer valid.');
    }

    public function test_the_wallet_refuses_a_sub_pass_whose_main_row_is_not_approved_even_if_the_sub_row_is(): void
    {
        // The cascade makes this state unreachable through the admin API; it is
        // still reachable through a direct DB write, a row written before the
        // cascade existed, or an out-of-band re-approval. The wallet path must
        // refuse it on its own.
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);
        $row = $this->approvedSubApplication($park, $application, $me);

        Application::query()->whereKey($application->id)->update([
            'status' => 'denied',
            'reason' => 'direkter DB-Write',
        ]);

        $this->assertDatabaseHas('sub_applications', ['id' => $row->id, 'status' => 'approved']);

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications/'.$row->id.'/wallet')
            ->assertStatus(410)
            ->assertJsonPath('message', 'The main accreditation was withdrawn, this wallet pass is no longer valid.');
    }

    public function test_the_wallet_refuses_a_sub_pass_whose_main_row_is_still_requested(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);
        $row = $this->approvedSubApplication($park, $application, $me);

        Application::query()->whereKey($application->id)->update(['status' => 'requested']);

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications/'.$row->id.'/wallet')
            ->assertStatus(410);
    }

    public function test_the_wallet_still_answers_422_when_the_main_row_is_approved_but_the_sub_row_is_not(): void
    {
        // The pre-existing branch must not change: a pending or denied
        // sub-application on a healthy main accreditation is a plain 422, not
        // a "gone".
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);

        $row = SubApplication::create([
            'sub_accreditation_id' => $park->id,
            'application_id' => $application->id,
            'user_id' => $me->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        $this->actingAsApi($me)
            ->getJson('/api/sub-applications/'.$row->id.'/wallet')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only approved sub-applications can be downloaded as a wallet pass.');
    }

    public function test_the_wallet_still_serves_an_approved_sub_pass_on_an_approved_main_application(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);
        $row = $this->approvedSubApplication($park, $application, $me);

        $response = $this->actingAsApi($me)->get('/api/sub-applications/'.$row->id.'/wallet');

        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.pkpass');

        $files = $this->unzip($response->getContent());
        $pass = json_decode((string) $files['pass.json'], true);

        $this->assertSame('park-'.$row->id, $pass['serialNumber']);
    }

    public function test_the_main_wallet_path_is_unaffected_by_the_sub_guard(): void
    {
        $accreditation = $this->accreditation();
        $me = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $me);

        $this->actingAsApi($me)
            ->get('/api/applications/'.$application->id.'/wallet')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.pkpass');
    }

    public function test_a_foreign_sub_pass_stays_404_and_never_410(): void
    {
        // Ownership/mandant scope is still checked first: the 410 must not
        // become an existence oracle for somebody else's row.
        $accreditation = $this->accreditation();
        $park = $this->sub($accreditation, 'park', 5);

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $application = $this->approvedApplication($accreditation, $owner);
        $row = $this->approvedSubApplication($park, $application, $owner);

        $this->actingAsApi($stranger)
            ->getJson('/api/sub-applications/'.$row->id.'/wallet')
            ->assertStatus(404);

        $this->allocation->denyApplication($application, 'Widerruf');

        $this->actingAsApi($stranger)
            ->getJson('/api/sub-applications/'.$row->id.'/wallet')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function accreditation(array $attributes = []): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 20,
            ...$attributes,
        ]);
    }

    private function sub(Accreditation $accreditation, string $type, int $quota): SubAccreditation
    {
        return $accreditation->subAccreditations()->create([
            'type' => $type,
            'quota' => $quota,
        ]);
    }

    private function approvedApplication(Accreditation $accreditation, User $user): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    private function approvedSubApplication(SubAccreditation $sub, Application $application, User $user): SubApplication
    {
        return SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => null,
        ]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pkpass');
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($tmp);

        return $files;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ApplicationDeniedMail;
use App\Mail\SubApplicationApprovedMail;
use App\Mail\SubApplicationDeniedMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Blacklist;
use App\Models\Category;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\Team;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\MandantMailerService;
use App\Services\SubAllocationService;
use App\Services\WalletPassService;
use App\Support\MandantContext;
use App\Support\QueuedMailPayload;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\PlainTestMailable;
use Tests\Support\SubAllocationProbeConnector;
use Tests\Support\TransactionLevelRecordingQueue;
use Tests\TestCase;
use ZipArchive;

/**
 * P6 TODO 5 (Nutzerentscheid 2026-10-03): a sub-approval (Park-/Sitzkarte, D9)
 * gets its OWN mailable over the SAME queue — `MandantMailerService` →
 * `SendMandantMail` → idempotency claim, retry cap, `failed_jobs.mandant_id`.
 * The promise "no mail should be lost" therefore covers sub-applications too.
 *
 * This class replaces the tripwire `SubAccreditationTest` carried for the
 * documented gap (WP-3-d): it asserted, on purpose, that a sub-status change
 * sends NOTHING, and was written to fail the day that stopped being true. That
 * day is this one, so it is deleted there and the behaviour is pinned HERE
 * instead — per path, per type, with the pass attachment.
 *
 * Covered:
 *  - every write path notifies: single approve, single deny, revoke of an
 *    approved row, bulk `approveAllEligible`, manual `approveSelection` and the
 *    automatic `runAutoSubAllocations`;
 *  - idempotency: a repeated run sends nothing (the dispatch mirrors the
 *    idempotent status write), and a path that decides nothing sends nothing;
 *  - the `status = approved` filter: a `requested` row whose main application
 *    is not approved is not a candidate and is not mailed;
 *  - the mail itself: recipient (`sub_application.user`), type-dependent
 *    wording (Parkkarte/Sitzkarte), the denial reason verbatim;
 *  - P6 proper: the sub `.pkpass` + Google payload ride along with the approval
 *    (valid ZIP, correct manifest, no signature without certificates, one
 *    name/MIME contract with the download endpoint), the denial mail carries no
 *    pass, and a build failure is skipped instead of taking the mail down;
 *  - the mandant: resolved through the SUB-accreditation, and stamped onto
 *    `failed_jobs` by the real provider, mandant-isolated in the DLQ surface;
 *  - the one silent path: the revoke cascade notifies the main application and
 *    nobody twice;
 *  - the transaction: a commit leaves a delivery order, a rollback leaves
 *    neither status nor order.
 */
class SubAllocationMailTest extends TestCase
{
    use RefreshDatabase;

    private SubAllocationService $subAllocation;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private static int $categorySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Position 45: the engine dispatches a `SendMandantMail` job, which
        // under the suite's `sync` connection runs inline. Without the fake
        // every decision path would dial the real `smtp` mailer, and the
        // (correctly propagating) TransportException would abort the test.
        Mail::fake();

        $this->seed(RoleSeeder::class);

        $this->subAllocation = app(SubAllocationService::class);

        $this->mandantA = $this->mandant('verband-a', 'Verband A');
        $this->mandantB = $this->mandant('verband-b', 'Verband B');

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MandantContext::reset();

        config()->set([
            'wallet.apple.cert' => null,
            'wallet.apple.key' => null,
            'wallet.apple.key_password' => null,
            'wallet.apple.wwdr' => null,
            'wallet.google.issuer_id' => null,
            'wallet.google.service_account_email' => null,
            'wallet.google.service_account_key' => null,
        ]);

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | 1. Every write path notifies
     | ------------------------------------------------------------------- */

    public function test_approving_one_sub_application_sends_the_sub_approval_mail_to_the_applicant(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'park@example.test', 'name' => 'Jane Doe']);
        $row = $this->subRequest($sub, $applicant);

        $this->subAllocation->approveSubApplication($row);

        $this->assertDatabaseHas('sub_applications', ['id' => $row->id, 'status' => 'approved']);

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
        Mail::assertNotSent(SubApplicationDeniedMail::class);

        $mail = Mail::sent(SubApplicationApprovedMail::class)->sole();

        $this->assertSame('park@example.test', $mail->to[0]['address']);
        $this->assertSame('Deine Parkkarte wurde freigegeben', $mail->envelope()->subject);
        $this->assertSame($row->id, $mail->subApplication->id);
    }

    public function test_denying_one_sub_application_sends_the_sub_denial_mail_with_the_reason(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'park@example.test']);
        $row = $this->subRequest($sub, $applicant);

        $this->subAllocation->denySubApplication($row, 'Keine Parkfläche');

        Mail::assertSent(SubApplicationDeniedMail::class, 1);
        Mail::assertNotSent(SubApplicationApprovedMail::class);

        $mail = Mail::sent(SubApplicationDeniedMail::class)->sole();

        $this->assertSame('park@example.test', $mail->to[0]['address']);
        $this->assertSame('Keine Parkfläche', $mail->reason);
        $this->assertSame('Deine Parkkarte wurde abgelehnt', $mail->envelope()->subject);
    }

    /**
     * The revoke case: `denySubApplication` on an `approved` row is the one
     * denial path a park-card holder has to hear about, because they already
     * carry a pass.
     */
    public function test_revoking_an_approved_sub_application_sends_the_denial_mail(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'park@example.test']);
        $row = $this->subRequest($sub, $applicant);

        $this->subAllocation->approveSubApplication($row);
        $this->subAllocation->denySubApplication($row, 'Falsche Parkzone');

        $this->assertDatabaseHas('sub_applications', ['id' => $row->id, 'status' => 'denied', 'reason' => 'Falsche Parkzone']);

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
        Mail::assertSent(SubApplicationDeniedMail::class, 1);
    }

    public function test_the_bulk_run_notifies_every_approved_and_every_denied_row(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 1]);

        foreach ($this->applicants($this->mandantA, 3) as $applicant) {
            $this->subRequest($sub, $applicant);
        }

        $result = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(1, $result->approved);
        $this->assertSame(2, $result->denied);

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
        Mail::assertSent(SubApplicationDeniedMail::class, 2);

        // The surplus denial carries the engine's own reason, verbatim.
        foreach (Mail::sent(SubApplicationDeniedMail::class) as $mail) {
            $this->assertSame('Quota erschöpft', $mail->reason);
        }
    }

    public function test_the_blacklist_denial_carries_the_blacklist_reason(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $banned = $this->member($this->mandantA, ['email' => 'banned@example.test']);

        $this->subRequest($sub, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.test']);

        $result = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(0, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertSame(1, $result->skipped_blacklist);

        $mail = Mail::sent(SubApplicationDeniedMail::class)->sole();
        $this->assertSame('Blacklist', $mail->reason);
    }

    public function test_the_manual_selection_notifies_exactly_the_rows_it_approved(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 10]);

        $approved = [];

        foreach ($this->applicants($this->mandantA, 3) as $applicant) {
            $approved[] = $this->subRequest($sub, $applicant)->id;
        }

        $this->subAllocation->approveSelection($sub, 2);

        Mail::assertSent(SubApplicationApprovedMail::class, 2);
        Mail::assertNotSent(SubApplicationDeniedMail::class);

        $notified = collect(Mail::sent(SubApplicationApprovedMail::class))
            ->map(fn (SubApplicationApprovedMail $mail): int => $mail->subApplication->id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$approved[0], $approved[1]], $notified);
    }

    /**
     * The automatic path is NOT a silent variant of the manual one. It reaches
     * the engine through `approveAllEligible()`, so this pins that the hourly
     * run notifies exactly like the admin click does.
     */
    public function test_the_automatic_trigger_notifies_like_the_manual_run(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), [
            'quota' => 1,
            'auto_approve' => true,
            'deadline_end' => '2026-08-20',
        ]);

        foreach ($this->applicants($this->mandantA, 3) as $applicant) {
            $this->subRequest($sub, $applicant);
        }

        Carbon::setTestNow('2026-08-20 23:59:59');
        $results = $this->subAllocation->runAutoSubAllocations();
        Carbon::setTestNow();

        $this->assertSame(['approved' => 1, 'denied' => 2], $results[$sub->id]);

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
        Mail::assertSent(SubApplicationDeniedMail::class, 2);
    }

    public function test_a_seat_sub_accreditation_is_called_sitzkarte_in_the_mail(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5, 'type' => 'seat']);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'seat@example.test']));

        $this->subAllocation->approveSubApplication($row);

        $this->assertSame(
            'Deine Sitzkarte wurde freigegeben',
            Mail::sent(SubApplicationApprovedMail::class)->sole()->envelope()->subject,
        );
    }

    /* ---------------------------------------------------------------------
     | 2. Idempotency: only what changed is notified
     | ------------------------------------------------------------------- */

    public function test_a_repeated_run_sends_nothing(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 2]);

        foreach ($this->applicants($this->mandantA, 3) as $applicant) {
            $this->subRequest($sub, $applicant);
        }

        $this->subAllocation->approveAllEligible($sub);

        $approved = Mail::sent(SubApplicationApprovedMail::class)->count();
        $denied = Mail::sent(SubApplicationDeniedMail::class)->count();

        $this->assertSame(2, $approved);
        $this->assertSame(1, $denied);

        // Second run: nothing changes, nothing is notified — the dispatch
        // re-reads the rows with the same `status` filter the idempotent status
        // write used.
        $second = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(0, $second->approved);
        $this->assertSame(0, $second->denied);

        Mail::assertSent(SubApplicationApprovedMail::class, $approved);
        Mail::assertSent(SubApplicationDeniedMail::class, $denied);
    }

    /**
     * A run that decides NOTHING must notify nothing — including the case where
     * a candidate exists but is skipped: a blacklisted applicant stays
     * `requested` in selection mode, which is not a decision.
     */
    public function test_a_path_that_decides_nothing_sends_nothing(): void
    {
        // An empty sub-accreditation: nothing to decide, nothing to say.
        $empty = $this->subAccreditation($this->accreditation(), ['quota' => 5]);

        $this->subAllocation->approveAllEligible($empty);
        $this->subAllocation->approveSelection($empty, 3);

        // `limit <= 0` is a documented no-op.
        $this->subAllocation->approveSelection($empty, 0);

        Mail::assertNothingSent();

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'banned@example.test']));

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.test']);

        $result = $this->subAllocation->approveSelection($sub, 1);

        $this->assertSame(0, $result->approved);
        $this->assertSame(1, $result->skipped_blacklist);

        Mail::assertNothingSent();
    }

    /**
     * The D9 candidate filter reaches the notification too: a `requested`
     * sub-application whose main application is not approved is never approved,
     * therefore never mailed — and it stays `requested`, so a later approval of
     * the main row does notify it.
     */
    public function test_a_row_whose_main_application_is_not_approved_is_not_a_candidate_and_is_not_mailed(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'waiting@example.test']);

        $main = Application::create([
            'accreditation_id' => $sub->accreditation_id,
            'user_id' => $applicant->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        $row = SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $main->id,
            'user_id' => $applicant->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        $this->subAllocation->approveAllEligible($sub);

        $this->assertDatabaseHas('sub_applications', ['id' => $row->id, 'status' => 'requested']);
        Mail::assertNothingSent();

        // The admin approves the main row; the sub row becomes a candidate and
        // is notified.
        $main->update(['status' => 'approved']);

        $this->subAllocation->approveAllEligible($sub);

        $this->assertDatabaseHas('sub_applications', ['id' => $row->id, 'status' => 'approved']);
        Mail::assertSent(SubApplicationApprovedMail::class, 1);
    }

    /* ---------------------------------------------------------------------
     | 3. The mail renders — recipient, wording, pass attachment
     | ------------------------------------------------------------------- */

    public function test_the_approval_mail_renders_the_accreditation_context_of_the_main_accreditation(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale']);
        $accreditation = $this->accreditation(['event_id' => $event->id, 'team_id' => $this->team()->id]);
        $sub = $this->subAccreditation($accreditation, ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['name' => 'Jane Doe']));

        $this->subAllocation->approveSubApplication($row);

        $rendered = (string) Mail::sent(SubApplicationApprovedMail::class)->sole()->render();

        $this->assertStringContainsString('Jane Doe', $rendered);
        $this->assertStringContainsString('Presse', $rendered);
        $this->assertStringContainsString('Finale', $rendered);
        $this->assertStringContainsString('Parkkarte', $rendered);
        // The verify link of the MAIN application — the same URL the QR inside
        // the attached pass encodes (a sub row carries no token of its own).
        $this->assertStringContainsString('/verify/', $rendered);
    }

    public function test_the_denial_mail_renders_the_reason_and_no_pass(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['name' => 'Jane Doe']));

        $this->subAllocation->denySubApplication($row, 'Keine Parkfläche');

        $mail = Mail::sent(SubApplicationDeniedMail::class)->sole();

        $this->assertStringContainsString('Keine Parkfläche', (string) $mail->render());

        // A pass belongs to an approval only: the denial mailable does not even
        // expose `attachments()`, so there is no code path that could attach one.
        $this->assertFalse(
            method_exists($mail, 'attachments'),
            'a denial mail must never carry a wallet pass',
        );
        $this->assertNotSame('approved', $mail->subApplication->status);
    }

    /**
     * P6 TODO 5, the point of the whole thing: a sub-approval carries the sub
     * pass. Names, MIME types and the `park`/`seat` type are the same contract
     * `GET /api/sub-applications/{id}/wallet` streams — and the file is a valid
     * ZIP whose manifest hashes match, unsigned because no certificates are
     * configured.
     *
     * ENVIRONMENT-GATED (the Apple half): `WalletPassService::icon()` rasterises
     * the two pass icons with GD. On a host whose `php8.5-gd` extension is
     * missing (documented in `Agents.headless.md` §6 — measured 2026-10-04:
     * `php -m | grep gd` is empty) the icon cannot be built, the Apple pass
     * build throws, and the documented fail-safe skips that ONE attachment. The
     * Google half below needs no GD and therefore runs everywhere. The same gap
     * turns five PRE-EXISTING tests of `WalletMailAttachmentTest` red on such a
     * host, so a red here is not a statement about this feature.
     */
    public function test_the_approval_mail_attaches_a_valid_unsigned_sub_pkpass(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped(
                'the Apple pass needs the gd extension for its icons (php -m | grep gd is empty on this host). '
                .'The Google attachment is pinned by the sibling test; CI installs gd and runs this one.',
            );
        }

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['name' => 'Jane Doe']));

        $this->subAllocation->approveSubApplication($row);

        $named = $this->attachmentsByName(Mail::sent(SubApplicationApprovedMail::class)->sole());

        // One contract with `GET /api/sub-applications/{id}/wallet`: the same
        // names, the same MIME types, the sub type and not the main one.
        $this->assertSame([
            'park-'.$row->id.'.pkpass',
            'park-'.$row->id.'.json',
        ], array_keys($named));

        $this->assertSame(WalletPassService::APPLE_CONTENT_TYPE, $named['park-'.$row->id.'.pkpass']['mime']);

        $files = $this->unzip($named['park-'.$row->id.'.pkpass']['data']);

        foreach (['pass.json', 'icon.png', 'icon@2x.png', 'manifest.json'] as $entry) {
            $this->assertArrayHasKey($entry, $files);
        }

        foreach (['icon.png', 'icon@2x.png'] as $icon) {
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $files[$icon]);
        }

        // Without certificates the bundle stays unsigned — the degraded pass is
        // still a usable one and IS attached.
        $this->assertArrayNotHasKey('signature', $files);

        $manifest = json_decode((string) $files['manifest.json'], true);
        $this->assertIsArray($manifest);

        foreach ($manifest as $name => $hash) {
            $this->assertArrayHasKey($name, $files);
            $this->assertSame(hash('sha256', $files[$name]), $hash, "manifest hash mismatch for {$name}");
        }

        $pass = json_decode((string) $files['pass.json'], true);
        $this->assertSame('park-'.$row->id, $pass['serialNumber']);
        $this->assertSame('Jane Doe', $pass['eventTicket']['primaryFields'][0]['value']);
    }

    /**
     * The GD-free half of the attachment contract, and therefore the one that
     * runs on every host: the Google payload rides along under the sub name the
     * download endpoint uses, with the sub serial.
     */
    public function test_the_approval_mail_attaches_the_google_payload_under_the_sub_name(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['name' => 'Jane Doe']));

        $this->subAllocation->approveSubApplication($row);

        $named = $this->attachmentsByName(Mail::sent(SubApplicationApprovedMail::class)->sole());

        $google = $named['park-'.$row->id.'.json'] ?? null;

        $this->assertNotNull($google, 'the Google payload must attach with the name the sub download endpoint streams');
        $this->assertSame(WalletPassService::GOOGLE_CONTENT_TYPE, $google['mime']);

        $object = json_decode($google['data'], true);

        $this->assertIsArray($object);
        $this->assertSame('park-'.$row->id, $object['passId']);
        $this->assertSame('Jane Doe', $object['ticketHolderName']);
        $this->assertSame('QR_CODE', $object['barcode']['type']);
    }

    public function test_a_seat_sub_accreditation_attaches_a_seat_google_payload(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5, 'type' => 'seat']);
        $row = $this->subRequest($sub, $this->member($this->mandantA));

        $this->subAllocation->approveSubApplication($row);

        $named = $this->attachmentsByName(Mail::sent(SubApplicationApprovedMail::class)->sole());

        $this->assertArrayHasKey('seat-'.$row->id.'.json', $named);

        $this->assertSame('seat-'.$row->id, json_decode($named['seat-'.$row->id.'.json']['data'], true)['passId']);
    }

    /**
     * MUTATION: a pass builder that throws must not take the notification with
     * it — `MandantMailerService::deliver()` lets transport errors through, so
     * the delivery budget would be burned for a convenience file.
     */
    public function test_a_pass_build_failure_is_logged_and_the_mail_still_carries_the_other_format(): void
    {
        // Three existing-but-invalid PEM files make the Apple signature path
        // throw — the one way to force a genuine build failure.
        $dir = sys_get_temp_dir().'/sub-mail-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        foreach (['cert', 'key', 'wwdr'] as $name) {
            file_put_contents($dir.'/'.$name.'.pem', "not a $name\n");
        }

        config()->set([
            'wallet.apple.cert' => $dir.'/cert.pem',
            'wallet.apple.key' => $dir.'/key.pem',
            'wallet.apple.wwdr' => $dir.'/wwdr.pem',
        ]);

        Log::spy();

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA));

        try {
            $this->subAllocation->approveSubApplication($row);

            $named = $this->attachmentsByName(Mail::sent(SubApplicationApprovedMail::class)->sole());
        } finally {
            array_map('unlink', glob($dir.'/*.pem') ?: []);
            @rmdir($dir);
        }

        Mail::assertSent(SubApplicationApprovedMail::class, 1);

        $this->assertArrayNotHasKey('park-'.$row->id.'.pkpass', $named);
        $this->assertArrayHasKey('park-'.$row->id.'.json', $named);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Wallet pass could not be attached to the mail'
                && $context['sub_application_id'] === $row->id
                && $context['attachment'] === 'park-'.$row->id.'.pkpass')
            ->once();
    }

    /* ---------------------------------------------------------------------
     | 4. The mandant: sub-accreditation's mandant, and the dead letter
     | ------------------------------------------------------------------- */

    public function test_the_mail_is_queued_for_the_mandant_of_the_sub_accreditation(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $foreign = $this->mandantB->accreditations()->create([
            'category_id' => $this->category($this->mandantB)->id,
            'scope' => 'season',
            'quota' => 20,
        ]);
        $sub = $this->subAccreditation($foreign, ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantB, ['email' => 'b@example.test']));

        $this->subAllocation->approveSubApplication($row);

        $this->assertDatabaseCount('jobs', 1);

        $job = QueuedMailPayload::mailJob((string) DB::table('jobs')->value('payload'));

        $this->assertNotNull($job);
        $this->assertSame(SubApplicationApprovedMail::class, $job->mailableClass);
        $this->assertSame($this->mandantB->id, $job->mandantId, 'the sub-accreditation\'s mandant owns the delivery — not the mandant of the request context');
        $this->assertSame('b@example.test', $job->recipient);
    }

    /**
     * WHICH relation `mandantFor()` walks — a question the test above cannot
     * answer. A sub-application names TWO accreditations, and for every row the
     * product writes they are one and the same (`apply` derives both from a
     * single accreditation), so the two chains collapse into the same value.
     * MEASURED: replacing `subAccreditation.accreditation.mandant` with
     * `application.accreditation.mandant` in `mandantFor()` left every other
     * test of this class green.
     *
     * The row below is therefore out of band ON PURPOSE, and it is the shape
     * that tells the two apart: the sub-accreditation — and with it the quota
     * just spent, the blacklist scope and the row lock — belongs to mandant B,
     * while the main application it hangs on belongs to mandant A. The
     * notification reports B's decision, so the delivery order must carry B;
     * with the relation swapped it carries A and this test fails.
     */
    public function test_the_mandant_of_the_sub_accreditation_wins_over_the_one_of_the_main_application(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $foreign = $this->mandantB->accreditations()->create([
            'category_id' => $this->category($this->mandantB)->id,
            'scope' => 'season',
            'quota' => 20,
        ]);
        $sub = $this->subAccreditation($foreign, ['quota' => 5]);

        $applicant = $this->member($this->mandantA, ['email' => 'out-of-band@example.test']);
        $main = Application::create([
            'accreditation_id' => $this->accreditation()->id,
            'user_id' => $applicant->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        $row = SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $main->id,
            'user_id' => $applicant->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        // The premise, asserted rather than assumed: without two different
        // mandants at the two ends the row below would prove nothing.
        $this->assertSame($this->mandantA->id, (int) $main->accreditation->mandant_id, 'precondition: the main application is mandant A\'s');
        $this->assertSame($this->mandantB->id, (int) $sub->accreditation->mandant_id, 'precondition: the sub-accreditation is mandant B\'s');

        $this->subAllocation->approveSubApplication($row);

        $this->assertDatabaseCount('jobs', 1);

        $job = QueuedMailPayload::mailJob((string) DB::table('jobs')->value('payload'));

        $this->assertNotNull($job);
        $this->assertSame(SubApplicationApprovedMail::class, $job->mailableClass);
        $this->assertSame(
            $this->mandantB->id,
            $job->mandantId,
            'the notification reports the sub-quota decision, so it must be delivered by the mandant that owns the sub-accreditation',
        );
        $this->assertSame('out-of-band@example.test', $job->recipient);
    }

    /**
     * The dead-letter surface, end to end through the REAL worker and the REAL
     * provider: a sub mail that used up its attempts lands in `failed_jobs`
     * carrying the mandant of its sub-accreditation, and a `mandant_admin`
     * sees only his own.
     *
     * Reaching the dead letter in ONE worker run needs a small piece of
     * staging: `SendMandantMail` carries its own `$tries = 5`, which outranks
     * the CLI (`MailDeadLetterTest` measures that precedence), so
     * `--tries=1` would merely RELEASE the job. `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()`
     * fails a job whose recorded `attempts` already exceed its cap — that is the
     * state a job sits in after its last attempt, and it is what lets this test
     * stay a single worker invocation instead of five plus a backoff dance.
     * Nothing here fabricates the payload: it is the row the ENGINE wrote.
     */
    public function test_a_undeliverable_sub_mail_dead_letters_with_its_mandant_id_and_stays_isolated(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $subA = $this->subAccreditation($this->accreditation(), ['quota' => 1]);
        $rowA = $this->subRequest($subA, $this->member($this->mandantA, ['email' => 'a@example.test']));

        $categoryB = $this->category($this->mandantB);
        $accreditationB = $this->mandantB->accreditations()->create([
            'category_id' => $categoryB->id,
            'scope' => 'season',
            'quota' => 20,
        ]);
        $subB = $this->subAccreditation($accreditationB, ['quota' => 1]);
        $rowB = $this->subRequest($subB, $this->member($this->mandantB, ['email' => 'b@example.test']));

        $this->subAllocation->approveSubApplication($rowA);
        $this->subAllocation->approveSubApplication($rowB);

        $this->assertDatabaseCount('jobs', 2);

        // The cap of `SendMandantMail`, read off the payload the engine wrote
        // instead of hardcoded — otherwise a change to `$tries` would silently
        // turn this into "the job runs once and is released" (green for the
        // wrong reason).
        $cap = QueuedMailPayload::mailJob((string) DB::table('jobs')->value('payload'))?->tries;
        $this->assertSame(5, $cap);

        DB::table('jobs')->update(['attempts' => $cap + 1]);

        // One `--once` run per queued job.
        foreach (range(1, 2) as $attempt) {
            Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 2);

        $letters = DB::table('failed_jobs')->orderBy('id')->get();

        $this->assertSame($this->mandantA->id, (int) $letters[0]->mandant_id);
        $this->assertSame($this->mandantB->id, (int) $letters[1]->mandant_id);

        $this->assertSame('a@example.test', QueuedMailPayload::mailJob((string) $letters[0]->payload)?->recipient);
        $this->assertSame('b@example.test', QueuedMailPayload::mailJob((string) $letters[1]->payload)?->recipient);

        // The DLQ surface stays mandant-scoped: A's admin never sees B's.
        $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/failed-mails')
            ->assertOk();

        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($this->mandantA->id, $data[0]['mandant_id']);
        $this->assertSame('a@example.test', $data[0]['recipient']);
        $this->assertSame(SubApplicationApprovedMail::class, $data[0]['mailable']);
    }

    /* ---------------------------------------------------------------------
     | 5. Status and delivery order are one transaction
     | ------------------------------------------------------------------- */

    /**
     * WHERE the dispatch is issued — the call site itself, measured as a state
     * rather than claimed in a comment.
     *
     * The rollback test below cannot see it: `after_commit` makes "dispatched
     * inside the transaction" and "dispatched after the commit" leave the SAME
     * observable facts under the only failure a test can stage (a rollback), so
     * a suite that pinned nothing else would be green for both — MEASURED
     * 2026-10-04: with the dispatch moved behind `DB::transaction()`, exactly
     * ONE test of this class goes red (this one) and every other stays exactly
     * as it is (23 of 24 unchanged: 22 green, 1 skipped). The difference is the
     * crash window between commit and `jobs` insert, which no in-process test
     * can produce.
     *
     * So it is measured directly, with a queue connector that records the
     * transaction level **at the moment the job is pushed**:
     *
     *  - the engine's own transaction is still open (level > baseline), and
     *  - a dispatch made by THIS test body, i.e. from a known position outside
     *    any engine transaction, records exactly the baseline — the
     *    counter-direction, so a probe that always reported "deep" cannot pass.
     *
     * The probe connection carries no `after_commit`, so `SyncQueue::push()`
     * runs at dispatch time; with `after_commit` the push itself is deferred to
     * the commit and the level would read 0 for both shapes — which is exactly
     * why the measurement needs this connector and not the shipped one.
     *
     * MUTATION: move `dispatchApprovedMails()` / `dispatchDeniedMails()` out of
     * the `DB::transaction()` closure (the shape `AllocationService` uses) and
     * the engine's recorded level drops to the baseline ⇒ this test fails.
     */
    public function test_the_notification_is_dispatched_while_the_allocating_transaction_is_still_open(): void
    {
        $probe = new TransactionLevelRecordingQueue;

        Queue::extend('sub-allocation-probe', fn () => new SubAllocationProbeConnector($probe));
        config([
            'queue.default' => 'sub-allocation-probe',
            'queue.connections.sub-allocation-probe' => ['driver' => 'sub-allocation-probe'],
        ]);

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 1]);

        foreach ($this->applicants($this->mandantA, 2) as $applicant) {
            $this->subRequest($sub, $applicant);
        }

        $baseline = DB::connection()->transactionLevel();

        $this->subAllocation->approveAllEligible($sub);

        // The counter-direction: a dispatch from the test body, with no engine
        // transaction around it, records exactly the baseline.
        app(MandantMailerService::class)->send($this->mandantA, new PlainTestMailable);

        $levels = $probe->levels;

        // Two engine pushes (one approval, one quota denial) plus the dispatch
        // this test body makes itself.
        $this->assertCount(3, $levels, 'the bulk run pushes one order per decided row');

        $engine = [$levels[0][0], $levels[1][0]];
        $outside = $levels[2][0];

        foreach ($engine as $level) {
            $this->assertGreaterThan(
                $baseline,
                $level,
                'the notification must be dispatched while the allocating transaction is still open — that is what makes status and delivery order one commit',
            );
        }

        $this->assertSame(
            $baseline,
            $outside,
            'the probe must read the level where it is called: a dispatch from outside records the baseline',
        );

        // Single-row paths hold the same line.
        $probe->levels = [];

        $single = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $this->subAllocation->approveSubApplication($this->subRequest($single, $this->member($this->mandantA)));

        $this->assertCount(1, $probe->levels);
        $this->assertGreaterThan($baseline, $probe->levels[0][0]);

        $probe->levels = [];

        $revoke = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $this->subAllocation->denySubApplication($this->subRequest($revoke, $this->member($this->mandantA)), 'Keine Parkfläche');

        $this->assertCount(1, $probe->levels);
        $this->assertGreaterThan($baseline, $probe->levels[0][0]);

        $probe->levels = [];

        $selection = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $this->subRequest($selection, $this->member($this->mandantA));
        $this->subAllocation->approveSelection($selection, 1);

        $this->assertCount(1, $probe->levels);
        $this->assertGreaterThan($baseline, $probe->levels[0][0]);
    }

    /**
     * THE observable half of "in derselben Transaktion": a committed allocation
     * always leaves a delivery order behind, and it names the sub mail, its
     * recipient and the mandant that owns it.
     */
    public function test_a_committed_approval_leaves_a_delivery_order_on_the_queue(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 1]);

        foreach ($this->applicants($this->mandantA, 2) as $applicant) {
            $this->subRequest($sub, $applicant);
        }

        $result = $this->subAllocation->approveAllEligible($sub);

        $this->assertSame(1, $result->approved);
        $this->assertSame(1, $result->denied);
        $this->assertDatabaseCount('jobs', 2);

        $classes = collect(DB::table('jobs')->pluck('payload'))
            ->map(fn (string $payload): ?string => QueuedMailPayload::mailJob($payload)?->mailableClass)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            SubApplicationApprovedMail::class,
            SubApplicationDeniedMail::class,
        ], $classes);

        $this->assertSame(
            [$this->mandantA->id],
            collect(DB::table('jobs')->pluck('payload'))
                ->map(fn (string $payload): ?int => QueuedMailPayload::mailJob($payload)?->mandantId)
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * And the other half: a rolled-back allocation leaves NEITHER a status
     * change NOR a delivery order — the order belongs to the same commit
     * (`config/queue.php` → `after_commit => true`), so there is no state in
     * which one happened and the other did not.
     *
     * The CALL SITE is pinned by the probe test above, not here — with the
     * dispatch behind `DB::transaction()` this test would still pass, because
     * the caller's own transaction encloses both.
     */
    public function test_a_rolled_back_allocation_leaves_no_status_change_and_no_delivery_order(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 1]);

        foreach ($this->applicants($this->mandantA, 2) as $applicant) {
            $this->subRequest($sub, $applicant);
        }

        $thrown = null;

        try {
            DB::transaction(function () use ($sub): void {
                $this->subAllocation->approveAllEligible($sub);

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the forced rollback never surfaced');

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(2, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'requested')->count());
        $this->assertSame(0, SubApplication::query()->where('sub_accreditation_id', $sub->id)->where('status', 'approved')->count());
    }

    /* ---------------------------------------------------------------------
     | 6. The one silent path — the revoke cascade
     | ------------------------------------------------------------------- */

    /**
     * The single documented exclusion of P6: `AllocationService`'s
     * `cascadeRevokedSubApplications()` writes sub rows and sends NOTHING.
     *
     * Both halves of that decision existed only in prose — the `features/` note
     * and two docblocks. What this pins is the pair of claims together, because
     * either alone is cheap:
     *
     *  - **no second, Parkkarte-shaped mail** for the cascaded row, and
     *  - **the same person still hears about the revocation**, through the MAIN
     *    letter — the justification the silence rests on, which only holds
     *    because `sub_applications.user_id` is denormalised from the main
     *    application. Hence the asserted premise below.
     *
     * MUTATION: routing the cascade through `SubAllocationService::denySubApplication()`
     * (the follow-up that was deliberately not built) sends the sub denial, and
     * the count assertion fails (`actual size 1 matches expected size 0`).
     * Removing the cascade instead leaves the row `approved` and fails the
     * `assertDatabaseHas` above.
     */
    public function test_the_revoke_cascade_notifies_only_the_main_application(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'park@example.test']);
        $row = $this->subRequest($sub, $applicant);

        // The park card the cascade will pull back: it must be an APPROVED row,
        // since only those cascade.
        $this->subAllocation->approveSubApplication($row);

        // A fresh fake, so every count below is about the REVOKE alone and not
        // about the approval that preceded it.
        Mail::fake();

        $main = Application::query()->findOrFail($row->application_id);

        // The premise of the whole decision: both letters would reach one
        // address.
        $this->assertSame($applicant->id, (int) $row->user_id);
        $this->assertSame((int) $row->user_id, (int) $main->user_id, 'precondition: user_id is denormalised from the main application');

        app(AllocationService::class)->denyApplication($main, 'Widerruf');

        // The cascade really ran — otherwise "no sub mail" would be vacuous.
        $this->assertDatabaseHas('sub_applications', [
            'id' => $row->id,
            'status' => 'denied',
            'reason' => AllocationService::REASON_PARENT_REVOKED,
        ]);

        // NOTE the assertion shape: `Mail::assertNotSent($class, 'prose')` does
        // NOT take a message — its second argument is a RECIPIENT ADDRESS
        // (`MailFake::assertNotSent` builds a `hasTo($address)` callback over
        // `Arr::wrap($callback)`), so a sentence there silently asserts "nobody
        // was mailed to that sentence" and passes whatever the engine did.
        // MEASURED on this very test: with the cascade notifying it reported 1
        // sent sub denial and stayed green.
        $this->assertCount(
            0,
            Mail::sent(SubApplicationDeniedMail::class),
            'the cascade is the one sub-status write that stays silent — a second letter about the same decision is a duplicate',
        );

        Mail::assertNotSent(SubApplicationApprovedMail::class);

        Mail::assertSent(ApplicationDeniedMail::class, 1);

        $denial = Mail::sent(ApplicationDeniedMail::class)->sole();

        $this->assertSame($applicant->email, $denial->to[0]['address'], 'the main letter must reach the applicant of the sub row, not somebody else');
        $this->assertSame('Widerruf', $denial->reason);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * `Mail::fake()` records the mailable without ever invoking
     * `attachments()`; calling it here is what builds (and mirrors) the
     * payloads — exactly what a real send does.
     *
     * @return array<string, array{name: string, mime: string, data: string}>
     */
    private function attachmentsByName(SubApplicationApprovedMail $mail): array
    {
        $mail->attachments();

        $named = [];

        foreach ($mail->walletAttachments as $attachment) {
            $named[$attachment['name']] = $attachment;
        }

        return $named;
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sub-pkpass');
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($tmp);

        return $files;
    }

    private function mandant(string $slug, string $name): Mandant
    {
        $mandant = Mandant::factory()->create(['slug' => $slug, 'name' => $name]);
        $mandant->domains()->create(['hostname' => $slug.'.test']);

        return $mandant;
    }

    private function category(Mandant $mandant): Category
    {
        return $mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);
    }

    private function team(): Team
    {
        return $this->mandantA->teams()->create([
            'name' => 'Team A',
            'slug' => 'team-'.(++self::$categorySeq),
        ]);
    }

    private function accreditation(array $attributes = []): Accreditation
    {
        return $this->mandantA->accreditations()->create([
            'category_id' => $this->category($this->mandantA)->id,
            'scope' => 'season',
            'quota' => 20,
            ...$attributes,
        ]);
    }

    private function subAccreditation(Accreditation $accreditation, array $attributes = []): SubAccreditation
    {
        return $accreditation->subAccreditations()->create([
            'type' => 'park',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    /**
     * One `requested` sub-application with an APPROVED main application in front
     * of it — the only shape D9 allows.
     */
    private function subRequest(SubAccreditation $sub, User $user, array $attributes = []): SubApplication
    {
        $main = Application::create([
            'accreditation_id' => $sub->accreditation_id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        return SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $main->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    /**
     * @return list<User>
     */
    private function applicants(Mandant $mandant, int $count): array
    {
        return array_map(
            fn (): User => $this->member($mandant, ['email' => 'applicant-'.bin2hex(random_bytes(4)).'@example.test']),
            range(1, $count),
        );
    }

    private function member(Mandant $mandant, array $attributes = []): User
    {
        $user = User::factory()->forMandant($mandant)->create($attributes);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::USER->value)->firstOrFail()->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }

    private function mandantAdmin(Mandant $mandant): User
    {
        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::MANDANT_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }
}

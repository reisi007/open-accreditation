<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationDeniedMail;
use App\Mail\SubApplicationApprovedMail;
use App\Mail\SubApplicationDeniedMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Category;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use App\Support\QueuedMailPayload;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P6 follow-up: `POST /api/admin/sub-applications/{id}/resend` — the
 * Park-/Sitzkarte counterpart of the pass resend, so an admin can put a lost or
 * undelivered sub notification back on the wire without changing any decision.
 *
 * It reuses the engine's delivery path (`MandantMailerService::send()` →
 * `SendMandantMail` → idempotency claim, retry cap, `failed_jobs.mandant_id`),
 * the engine's mailable classes and the engine's relation graph, and it resolves
 * the mandant through the SUB-accreditation like the allocation does. What this
 * class therefore pins is the endpoint's own contract:
 *
 *  - which mail a status produces (approved → sub approval incl. the pass,
 *    denied → sub denial with the PERSISTED reason verbatim), and that the
 *    MAIN application's mail is never dragged along;
 *  - the 422s: a `requested` row has no mailable status, a `denied` row without
 *    a reason cannot be mailed — and neither queues anything;
 *  - the isolation, identical to `update`: foreign mandant → 404, a
 *    `team_admin` on a foreign team → 403, and his OWN team → 200 (the positive
 *    half, or "deny everything" would pass the 403 test);
 *  - that the dispatch is a real delivery ORDER on the `database` queue with the
 *    mandant stamped on it — the field the dead-letter provider reads, so
 *    `failed_jobs.mandant_id` is mandant-isolated for a resent sub mail too;
 *  - and that a resend is a delivery action only: status, reason and priority
 *    are left exactly as they were, and the `qr_token` of the main application
 *    is neither minted anew nor invalidated.
 *
 * MUTATION, measured on this class alone (2026-10-04), so the pins below are
 * not decorative:
 *
 *  - deleting BOTH `send()` calls → 7 of 13 tests red (the two happy paths,
 *    "does not drag along", the team_admin's own team, both queue-order
 *    tests, AND the qr_token test — that one is mail-sensitive through its own
 *    `Mail::assertSent(…sub approval…)` premise at `:434-437`, which is what
 *    keeps "untouched" from passing vacuously when nothing was dispatched).
 *    The two 422 tests stay green, correctly: they assert nothing was
 *    sent.
 *  - deleting the TEAM half of `assertSubApplicationAccessible()` and keeping the
 *    mandant half → exactly ONE test red: the foreign-team 403.
 *  - deleting the accessibility check entirely → the SAME single test red. The
 *    404 test stays green in both mutations, because
 *    `SubApplication::resolveRouteBindingQuery()` already narrows by mandant:
 *    the explicit check is defence in depth for the mandant, and the ONLY thing
 *    standing between a `team_admin` and another team's row.
 */
class AdminSubApplicationResendTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The endpoint only dispatches (Position 45), and the suite's queue
        // connection is `sync`, so the job would run the delivery inline. The
        // fake is what makes the dispatch observable without a relay.
        Mail::fake();

        $this->seed(RoleSeeder::class);

        $this->mandantA = $this->mandant('verband-a', 'Verband A');
        $this->mandantB = $this->mandant('verband-b', 'Verband B');

        $this->teamA = $this->team($this->mandantA, 'Team A');
        $this->teamB = $this->team($this->mandantA, 'Team B');

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
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
     | Happy path per status
     | ------------------------------------------------------------------- */

    /**
     * MUTATION: remove the `send()` call on the `approved` branch → red here.
     */
    public function test_resend_of_an_approved_sub_application_sends_the_sub_approval_mail_again(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'park@example.test', 'name' => 'Jane Doe']);
        $row = $this->subRequest($sub, $applicant, ['status' => 'approved']);

        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row))
            ->assertOk()
            ->assertJsonPath('message', 'E-Mail wurde erneut in die Warteschlange gestellt.');

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
        Mail::assertNotSent(SubApplicationDeniedMail::class);

        $mail = Mail::sent(SubApplicationApprovedMail::class)->sole();

        $this->assertTrue($mail->hasTo($applicant->email), 'the recipient is the applicant of the sub-application');
        $this->assertSame($row->id, $mail->subApplication->id);

        // The pass rides along: the resent mail must be the mail the approval
        // would have produced, not a stripped variant of it. The Google payload
        // is the half that builds everywhere — the Apple half needs GD for its
        // icons and is skipped by the documented fail-safe on hosts without it
        // (the same gap that gates
        // `SubAllocationMailTest::test_the_approval_mail_attaches_a_valid_unsigned_sub_pkpass`).
        $named = $this->attachmentsByName($mail);
        $this->assertArrayHasKey('park-'.$row->id.'.json', $named);
        $this->assertSame('application/json', $named['park-'.$row->id.'.json']['mime']);

        // A resend is a delivery action, not a second decision.
        $this->assertSame('approved', $row->fresh()->status);
    }

    /**
     * The main application's mail must NOT ride along — a sub decision is
     * notified as a Parkkarte/Sitzkarte mail only. An implementation that
     * delegated to the main resend (or re-dispatched the engine's parent
     * notification) would fail here.
     */
    public function test_resend_does_not_drag_along_the_main_applications_mail(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);

        $approved = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'a@example.test']), ['status' => 'approved']);
        $denied = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'b@example.test']), ['status' => 'denied', 'reason' => 'Quota erschöpft']);

        $this->actingAsApi($this->superAdmin())->postJson($this->endpoint($approved))->assertOk();
        $this->actingAsApi($this->superAdmin())->postJson($this->endpoint($denied))->assertOk();

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
        Mail::assertSent(SubApplicationDeniedMail::class, 1);
        Mail::assertNotSent(ApplicationApprovedMail::class);
        Mail::assertNotSent(ApplicationDeniedMail::class);
    }

    /**
     * MUTATION: remove the `send()` call on the `denied` branch → red here; pass
     * `$reason` from the request body (or a fixed string) instead of the row →
     * also red here.
     */
    public function test_resend_of_a_denied_sub_application_repeats_the_denial_with_the_persisted_reason(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'seat@example.test']);
        $row = $this->subRequest($sub, $applicant, ['status' => 'denied', 'reason' => 'Sitzkarte ist ausverkauft']);

        // A reason in the request body must be ignored — the mail reports the
        // decision that is on the row, not one an admin can retype here.
        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row), ['reason' => 'irrelevant'])
            ->assertOk()
            ->assertJsonPath('message', 'E-Mail wurde erneut in die Warteschlange gestellt.');

        Mail::assertSent(SubApplicationDeniedMail::class, 1);
        Mail::assertNotSent(SubApplicationApprovedMail::class);

        $mail = Mail::sent(SubApplicationDeniedMail::class)->sole();

        $this->assertTrue($mail->hasTo($applicant->email));
        $this->assertSame('Sitzkarte ist ausverkauft', $mail->reason);

        $this->assertSame('denied', $row->fresh()->status);
        $this->assertSame('Sitzkarte ist ausverkauft', $row->fresh()->reason);
    }

    /* ---------------------------------------------------------------------
     | The 422s — and that they dispatch nothing
     | ------------------------------------------------------------------- */

    public function test_resend_of_a_requested_sub_application_is_422_and_sends_nothing(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']));

        $this->assertSame('requested', $row->status, 'precondition: the row has no mailable status yet');

        // The 422 body moved from an English literal to `mails.sub_no_mailable_status`
        // (`lang/de/mails.php`, 2026-10-05). `TestCase::speakGermanByDefault()` makes
        // the suite a German client, so this is the DE catalog; the EN wording is
        // pinned per locale in `ServerMessageLocaleTest`, which is the class that
        // owns the negotiation.
        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row))
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.sub_no_mailable_status'));

        Mail::assertNothingSent();
    }

    public function test_resend_of_a_denied_sub_application_without_a_reason_is_422_and_sends_nothing(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']), [
            'status' => 'denied',
            'reason' => null,
        ]);

        // Only a direct row write can produce this shape — every writer of a
        // denied row persists a reason (`denySubApplication()` refuses an empty
        // one, the bulk plan writes Quota/Blacklist).
        $this->assertNull($row->reason, 'precondition: the denied row carries no reason');

        // Localized like its sibling above — see the note there.
        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row))
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.sub_no_mailable_reason'));

        Mail::assertNothingSent();
    }

    /**
     * The 422 must not leave a delivery order behind on a REAL queue — "no mail
     * is silently swallowed" cuts both ways: nothing queued is as wrong as a
     * mail claimed but not sent.
     */
    public function test_a_refused_resend_leaves_no_delivery_order_on_the_queue(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $requested = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'a@example.test']));
        $reasonless = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'b@example.test']), [
            'status' => 'denied',
            'reason' => null,
        ]);

        $this->actingAsApi($this->superAdmin())->postJson($this->endpoint($requested))->assertStatus(422);
        $this->actingAsApi($this->superAdmin())->postJson($this->endpoint($reasonless))->assertStatus(422);

        $this->assertDatabaseCount('jobs', 0);
    }

    /* ---------------------------------------------------------------------
     | Isolation — the same gates as `update`
     | ------------------------------------------------------------------- */

    public function test_resend_of_a_sub_application_of_another_mandant_is_404(): void
    {
        $foreign = $this->accreditation($this->mandantB);
        $sub = $this->subAccreditation($foreign, ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantB, ['email' => 'b@example.test']), ['status' => 'approved']);

        // The premise, asserted rather than assumed: the 404 below has to come
        // from the mandant scope, not from a missing request context.
        $this->assertSame($this->mandantA->id, MandantContext::currentId(), 'precondition: the request runs in mandant A');
        $this->assertSame($this->mandantB->id, (int) $row->subAccreditation->accreditation->mandant_id, 'precondition: the row is mandant B\'s');

        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row))
            ->assertStatus(404);

        Mail::assertNothingSent();
    }

    public function test_a_team_admin_may_not_resend_a_sub_application_of_another_team(): void
    {
        $teamAdmin = $this->userWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $foreign = $this->accreditation($this->mandantA, ['team_id' => $this->teamB->id]);
        $sub = $this->subAccreditation($foreign, ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']), ['status' => 'approved']);

        $this->actingAsApi($teamAdmin)
            ->postJson($this->endpoint($row))
            ->assertStatus(403);

        Mail::assertNothingSent();
    }

    /**
     * The positive half of the team gate. Without it "403 for everyone who has a
     * team scope" would satisfy the test above.
     */
    public function test_a_team_admin_may_resend_a_sub_application_of_his_own_team(): void
    {
        $teamAdmin = $this->userWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $own = $this->accreditation($this->mandantA, ['team_id' => $this->teamA->id]);
        $sub = $this->subAccreditation($own, ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']), ['status' => 'approved']);

        $this->actingAsApi($teamAdmin)
            ->postJson($this->endpoint($row))
            ->assertOk();

        Mail::assertSent(SubApplicationApprovedMail::class, 1);
    }

    public function test_resend_requires_authentication(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']), ['status' => 'approved']);

        $this->postJson($this->endpoint($row))->assertStatus(401);

        Mail::assertNothingSent();
    }

    /* ---------------------------------------------------------------------
     | The delivery order itself (the DLQ surface)
     | ------------------------------------------------------------------- */

    /**
     * The dispatch is an ORDER on the `database` queue, and it carries the
     * mandant — the very field `MandantAwareFailedJobProvider` stamps onto
     * `failed_jobs`, which is what makes the DLQ list mandant-isolated. So this
     * is the assertion that a dead-lettered RESENT sub mail is still attributable
     * to one mandant; `SubAllocationMailTest` walks the same row through a real
     * worker for the allocation path.
     *
     * Over HTTP the sub-accreditation's mandant and the request context's are the
     * same by construction (the accessibility check requires exactly that), so
     * WHICH relation resolves it is pinned out of band in
     * `SubAllocationMailTest::test_the_mandant_of_the_sub_accreditation_wins_over_the_one_of_the_main_application`.
     */
    public function test_resend_leaves_a_delivery_order_on_the_queue_with_its_mandant(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $applicant = $this->member($this->mandantA, ['email' => 'park@example.test']);
        $row = $this->subRequest($sub, $applicant, ['status' => 'approved']);

        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row))
            ->assertOk();

        $this->assertDatabaseCount('jobs', 1);

        $job = QueuedMailPayload::mailJob((string) DB::table('jobs')->value('payload'));

        $this->assertNotNull($job);
        $this->assertSame(SubApplicationApprovedMail::class, $job->mailableClass);
        $this->assertSame($this->mandantA->id, $job->mandantId);
        $this->assertSame($applicant->email, $job->recipient);
    }

    /**
     * A resend is a second delivery, not a duplicate that the idempotency claim
     * would refuse: two resends of the same row leave TWO orders, and each is a
     * FRESH delivery identity (the `deliveryId` is what
     * `SendMandantMail`'s claim keys on, and what `FailedMailController`'s
     * requeue deliberately re-stamps).
     */
    public function test_every_resend_leaves_its_own_delivery_order(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']), ['status' => 'approved']);

        $this->actingAsApi($this->superAdmin())->postJson($this->endpoint($row))->assertOk();
        $this->actingAsApi($this->superAdmin())->postJson($this->endpoint($row))->assertOk();

        $this->assertDatabaseCount('jobs', 2);

        $deliveryIds = DB::table('jobs')
            ->pluck('payload')
            ->map(fn (string $payload): ?string => QueuedMailPayload::mailJob($payload)?->deliveryId)
            ->all();

        $this->assertCount(2, $deliveryIds);
        $this->assertNotNull($deliveryIds[0]);
        $this->assertNotNull($deliveryIds[1]);
        $this->assertNotSame(
            $deliveryIds[0],
            $deliveryIds[1],
            'a resend must be a fresh delivery, or the idempotency claim would refuse the second one',
        );
    }

    /**
     * The `qr_token` of the MAIN application is the credential behind the
     * approval mail's verify link and behind the attached pass. A resend may
     * neither mint a new one (that would invalidate every pass already handed
     * out) nor depend on repairing it (the sub approval path does not repair it
     * either, and the mail degrades to an empty link rather than to an error).
     */
    public function test_resend_leaves_the_main_applications_qr_token_untouched(): void
    {
        $sub = $this->subAccreditation($this->accreditation(), ['quota' => 5]);
        $row = $this->subRequest($sub, $this->member($this->mandantA, ['email' => 'park@example.test']), ['status' => 'approved']);

        $this->assertNull($row->application->qr_token, 'precondition: this row was approved without the engine, so no token exists');

        $this->actingAsApi($this->superAdmin())
            ->postJson($this->endpoint($row))
            ->assertOk();

        // The premise of "untouched": something WAS dispatched. Without this the
        // test would also stay green when the dispatch disappears entirely,
        // which would prove nothing about the token.
        Mail::assertSent(SubApplicationApprovedMail::class, 1);

        $this->assertNull(
            $row->application->fresh()->qr_token,
            'the token belongs to AllocationService/QrTokenService::make(); a resend must not write another aggregate\'s credential',
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function endpoint(SubApplication $row): string
    {
        return '/api/admin/sub-applications/'.$row->id.'/resend';
    }

    /**
     * @return array<string, string>
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
            'slug' => 'presse-'.(++self::$seq),
        ]);
    }

    private function team(Mandant $mandant, string $name): Team
    {
        return $mandant->teams()->create([
            'name' => $name,
            'slug' => 'team-'.(++self::$seq),
        ]);
    }

    private function accreditation(?Mandant $mandant = null, array $attributes = []): Accreditation
    {
        return ($mandant ?? $this->mandantA)->accreditations()->create([
            'category_id' => $this->category($mandant ?? $this->mandantA)->id,
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
     * One sub-application with an APPROVED main application in front of it — the
     * only shape D9 allows.
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

    private function superAdmin(): User
    {
        return $this->userWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function userWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null): User
    {
        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'mandant_id' => $mandantId,
            'team_id' => $teamId,
        ]);

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationDeniedMail;
use App\Mail\DeadlineReminderMail;
use App\Mail\PassMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Blacklist;
use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Services\AllocationService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P5 e-mail workflow — the mandant-aware dispatch of the applicant
 * notifications (approval, denial, deadline reminder, pass resend).
 *
 * Every dispatch runs through `MandantMailerService`: the test mandants carry
 * no `smtp_config`, so all sends take the default-mailer fallback, which is
 * captured by `Mail::fake()`.
 *
 * ## The Mailpit probe that used to live here is gone (2026-10-01)
 *
 * `test_real_smtp_delivery_reaches_mailpit_when_available()` opened a socket
 * to `127.0.0.1:1025`, delivered through `Mail::mailer('smtp')` and searched
 * the Mailpit HTTP API for the message. It carried **two** skips (no SMTP port,
 * no HTTP API) and asserted exactly one thing — that a container was running.
 *
 * `phpunit.xml` now pins `MAIL_MAILER=array`, so the mailer the suite uses is
 * an `ArrayTransport` and the probe exercised a mailer no test path takes any
 * more. Under that pin it could only ever skip; it could never fail. A test
 * that cannot fail is not a gate, so it was removed together with its skips —
 * and the suite's dependence on a running mail catcher is closed at the config
 * level instead (`AuthController`'s `Mail::to(...)->send()` goes through the
 * default mailer, which under `array` cannot open a socket at all).
 */
class MailTest extends TestCase
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
     | Mailable content — approve / deny / pass / reminder
     | ------------------------------------------------------------------- */

    public function test_approved_mail_is_sent_to_the_applicant_with_verify_link(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);

        $this->allocation->approveApplication($application);

        $token = $application->fresh()->qr_token;
        $this->assertNotNull($token);

        Mail::assertSent(ApplicationApprovedMail::class, function (ApplicationApprovedMail $mail) use ($user, $token) {
            return $mail->hasTo($user->email)
                && $mail->assertHasSubject('Dein Antrag wurde freigegeben')
                && str_contains($mail->verifyUrl, '/verify/'.$token)
                && $mail->assertSeeInHtml('Jane Doe')
                && $mail->assertSeeInHtml('Presse');
        });
    }

    public function test_approved_mail_uses_the_mandant_domain_for_the_verify_link(): void
    {
        Mail::fake();

        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);
        MandantContext::set($bundesliga);

        $category = $bundesliga->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $bundesliga->accreditations()->create(['category_id' => $category->id, 'scope' => 'season', 'quota' => 5]);
        $user = User::factory()->create();
        $application = $this->request($accreditation, $user);

        $this->allocation->approveApplication($application);

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        Mail::assertSent(ApplicationApprovedMail::class, function (ApplicationApprovedMail $mail) use ($scheme) {
            return str_starts_with($mail->verifyUrl, $scheme.'://bundesliga.test/verify/');
        });
    }

    public function test_denied_mail_is_sent_to_the_applicant_with_reason(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'John Smith']);
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);

        $this->allocation->denyApplication($application, 'Unterlagen fehlen');

        Mail::assertSent(ApplicationDeniedMail::class, function (ApplicationDeniedMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->assertHasSubject('Dein Antrag wurde abgelehnt')
                && $mail->reason === 'Unterlagen fehlen'
                && $mail->assertSeeInHtml('Unterlagen fehlen')
                && $mail->assertSeeInHtml('John Smith');
        });
    }

    public function test_pass_mail_renders_verify_link_and_context(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);
        $this->allocation->approveApplication($application);
        $token = $application->fresh()->qr_token;

        Mail::fake();

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertOk();

        Mail::assertSent(PassMail::class, function (PassMail $mail) use ($user, $token) {
            return $mail->hasTo($user->email)
                && $mail->assertHasSubject('Dein Akkreditierungs-Ausweis')
                && str_contains($mail->verifyUrl, '/verify/'.$token)
                && $mail->assertSeeInHtml('Jane Doe')
                && $mail->assertSeeInHtml('Presse');
        });
    }

    /* ---------------------------------------------------------------------
     | Dispatch points — approve / deny / bulk (idempotent)
     | ------------------------------------------------------------------- */

    public function test_approve_application_dispatches_approved_mail_only_on_status_change(): void
    {
        Mail::fake();

        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $this->allocation->approveApplication($application);

        Mail::assertSent(ApplicationApprovedMail::class, 1);

        // A second approve on the already-approved row is an invalid
        // transition (422) — no second mail.
        try {
            $this->allocation->approveApplication($application->fresh());
        } catch (ValidationException) {
            // expected
        }

        Mail::assertSent(ApplicationApprovedMail::class, 1);
    }

    public function test_deny_application_dispatches_denied_mail_only_on_status_change(): void
    {
        Mail::fake();

        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $this->allocation->denyApplication($application, 'Unterlagen fehlen');

        Mail::assertSent(ApplicationDeniedMail::class, 1);

        try {
            $this->allocation->denyApplication($application->fresh(), 'nochmal');
        } catch (ValidationException) {
            // expected
        }

        Mail::assertSent(ApplicationDeniedMail::class, 1);
    }

    public function test_bulk_allocation_dispatches_approved_and_denied_mails(): void
    {
        Mail::fake();

        $accreditation = $this->createAccreditation(['quota' => 2]);
        for ($i = 0; $i < 3; $i++) {
            $this->request($accreditation, User::factory()->create());
        }

        $result = $this->allocation->approveAllEligible($accreditation);

        $this->assertSame(2, $result->approved);
        $this->assertSame(1, $result->denied);

        Mail::assertSent(ApplicationApprovedMail::class, 2);
        Mail::assertSent(ApplicationDeniedMail::class, function (ApplicationDeniedMail $mail) {
            return $mail->reason === 'Quota erschöpft';
        });

        foreach (Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->with('user:id,email')->get() as $approvedApp) {
            Mail::assertSent(ApplicationApprovedMail::class, function (ApplicationApprovedMail $mail) use ($approvedApp) {
                return $mail->hasTo($approvedApp->user->email);
            });
        }
    }

    public function test_bulk_allocation_dispatches_denied_mail_with_blacklist_reason(): void
    {
        Mail::fake();

        $accreditation = $this->createAccreditation(['quota' => 5]);
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $this->request($accreditation, $banned);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'banned@example.com']);

        $this->allocation->approveAllEligible($accreditation);

        Mail::assertSent(ApplicationDeniedMail::class, function (ApplicationDeniedMail $mail) use ($banned) {
            return $mail->hasTo($banned->email) && $mail->reason === 'Blacklist';
        });
    }

    public function test_bulk_allocation_does_not_redispatch_on_repeat_run(): void
    {
        Mail::fake();

        $accreditation = $this->createAccreditation(['quota' => 2]);

        foreach (User::factory()->count(5)->create() as $user) {
            $this->request($accreditation, $user);
        }

        $this->allocation->approveAllEligible($accreditation);
        $this->assertSame(0, $this->allocation->approveAllEligible($accreditation)->approved);

        Mail::assertSent(ApplicationApprovedMail::class, 2);
        Mail::assertSent(ApplicationDeniedMail::class, 3);
    }

    /* ---------------------------------------------------------------------
     | reminders:send — timing window, status, idempotency
     | ------------------------------------------------------------------- */

    public function test_reminders_send_for_deadline_within_three_days(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        $accreditation = $this->createAccreditation(['quota' => 5, 'deadline_end' => '2026-08-17']);
        $applicant = User::factory()->create();
        $this->request($accreditation, $applicant);
        // Non-requested rows are never reminded.
        $this->request($accreditation, User::factory()->create(), ['status' => 'approved']);

        Mail::fake();
        $this->artisan('reminders:send')->assertSuccessful();

        Mail::assertSent(DeadlineReminderMail::class, 1);
        Mail::assertSent(DeadlineReminderMail::class, function (DeadlineReminderMail $mail) use ($applicant) {
            return $mail->hasTo($applicant->email)
                && $mail->assertHasSubject('Frist läuft bald ab')
                && $mail->deadlineLabel === '17.08.2026'
                && $mail->assertSeeInHtml('17.08.2026')
                && $mail->assertSeeInHtml('Presse');
        });
        Mail::assertNotSent(ApplicationApprovedMail::class);
    }

    public function test_reminders_skip_deadline_in_four_days(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        $accreditation = $this->createAccreditation(['quota' => 5, 'deadline_end' => '2026-08-18']);
        $this->request($accreditation, User::factory()->create());

        Mail::fake();
        $this->artisan('reminders:send')->assertSuccessful();

        Mail::assertNotSent(DeadlineReminderMail::class);
    }

    public function test_reminders_include_the_deadline_day_itself(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        $accreditation = $this->createAccreditation(['quota' => 5, 'deadline_end' => '2026-08-14']);
        $this->request($accreditation, User::factory()->create());

        Mail::fake();
        $this->artisan('reminders:send')->assertSuccessful();

        Mail::assertSent(DeadlineReminderMail::class, 1);
    }

    public function test_reminders_only_mail_requested_applications(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        $accreditation = $this->createAccreditation(['quota' => 5, 'deadline_end' => '2026-08-17']);

        foreach (['requested', 'approved', 'denied', 'blacklisted'] as $i => $status) {
            $this->request($accreditation, User::factory()->create(), ['status' => $status]);
        }

        Mail::fake();
        $this->artisan('reminders:send')->assertSuccessful();

        Mail::assertSent(DeadlineReminderMail::class, 1);
    }

    public function test_reminders_are_idempotent_across_runs(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        $accreditation = $this->createAccreditation(['quota' => 5, 'deadline_end' => '2026-08-17']);
        $this->request($accreditation, User::factory()->create());

        Mail::fake();
        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();

        // The application+deadline pair is deduped (cache), so the second run
        // sends nothing — one reminder per application per day.
        Mail::assertSent(DeadlineReminderMail::class, 1);
    }

    public function test_reminders_do_not_mail_without_deadline(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');

        $accreditation = $this->createAccreditation(['quota' => 5]);
        $this->request($accreditation, User::factory()->create());

        Mail::fake();
        $this->artisan('reminders:send')->assertSuccessful();

        Mail::assertNotSent(DeadlineReminderMail::class);
    }

    /* ---------------------------------------------------------------------
     | Admin resend endpoint
     | ------------------------------------------------------------------- */

    public function test_resend_requires_authentication(): void
    {
        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $this->postJson('/api/admin/applications/'.$application->id.'/resend')->assertStatus(401);
    }

    public function test_resend_approved_sends_pass_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);
        $this->allocation->approveApplication($application);
        $token = $application->fresh()->qr_token;

        Mail::fake();

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertOk()
            ->assertJsonPath('message', 'E-Mail wurde erneut in die Warteschlange gestellt.');

        Mail::assertSent(PassMail::class, function (PassMail $mail) use ($user, $token) {
            return $mail->hasTo($user->email) && str_contains($mail->verifyUrl, $token);
        });
        Mail::assertNotSent(ApplicationApprovedMail::class);
    }

    public function test_resend_denied_sends_denial_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);
        $this->allocation->denyApplication($application, 'Unterlagen fehlen');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertOk()
            ->assertJsonPath('message', 'E-Mail wurde erneut in die Warteschlange gestellt.');

        Mail::assertSent(ApplicationDeniedMail::class, function (ApplicationDeniedMail $mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->reason === 'Unterlagen fehlen';
        });
    }

    public function test_resend_denied_without_reason_is_422(): void
    {
        Mail::fake();

        $application = Application::create([
            'accreditation_id' => $this->createAccreditation(['quota' => 5])->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'denied',
            'reason' => null,
            'priority' => false,
        ]);

        // The 422 bodies moved from English literals to `mails.*`
        // (`lang/de/mails.php`, 2026-10-05). `TestCase::speakGermanByDefault()`
        // makes the suite a German client, so this reads the DE catalog; the EN
        // wording and the negotiation itself are pinned in `ServerMessageLocaleTest`.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.no_mailable_reason'));

        Mail::assertNotSent(ApplicationDeniedMail::class);
    }

    public function test_resend_requested_is_422(): void
    {
        Mail::fake();

        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.no_mailable_status'));

        Mail::assertNotSent(PassMail::class);
        Mail::assertNotSent(ApplicationDeniedMail::class);
    }

    public function test_resend_blacklisted_is_422(): void
    {
        Mail::fake();

        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create(), ['status' => 'blacklisted']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.no_mailable_status'));
    }

    public function test_resend_team_admin_foreign_team_is_403(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $foreign = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamB->id]);
        $application = $this->request($foreign, User::factory()->create());

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertStatus(403);
    }

    public function test_resend_team_admin_own_team_is_ok(): void
    {
        Mail::fake();

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $own = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamA->id]);
        $application = $this->request($own, User::factory()->create());
        $this->allocation->approveApplication($application);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertOk();

        Mail::assertSent(PassMail::class, 1);
    }

    public function test_resend_foreign_mandant_application_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreignAccreditation = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);
        $foreignApplication = $this->request($foreignAccreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$foreignApplication->id.'/resend')
            ->assertStatus(404);
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

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
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

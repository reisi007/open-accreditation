<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\FailedMailController;
use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use App\Support\QueuedMailPayload;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\Support\PlainTestMailable;
use Tests\Support\UncappedThrowingJob;
use Tests\TestCase;

/**
 * Position 45 (2026-10-02): the dead-letter queue for undelivered mandant mails.
 *
 * Covers the three claims that make "no mail should be lost" true rather than
 * aspirational: a capped retry ENDS in `failed_jobs` with its `mandant_id`; the
 * DLQ surface is mandant-ISOLATED (a `mandant_admin` never sees a foreign dead
 * letter); and the manual requeue really moves the mail back onto the queue.
 */
class MailDeadLetterTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);
        $this->mandantB->domains()->create(['hostname' => 'verband-b.test']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /**
     * End-to-end: a delivery that cannot succeed is retried up to the cap and
     * then dead-lettered, with the owning mandant written by the provider.
     */
    public function test_a_capped_retry_dead_letters_the_mail_with_its_mandant_id(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $mandant = Mandant::factory()->create([
            'smtp_config' => ['host' => '127.0.0.1', 'port' => 1],
        ]);
        MandantContext::set($mandant);

        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('victim@example.test'));
        $job->tries = 1;
        $job->onConnection('database');
        dispatch($job);

        $this->assertDatabaseCount('jobs', 1);

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', ['mandant_id' => $mandant->id]);
    }

    /**
     * The delivery cap lives in the PAYLOAD, not in the worker invocation.
     *
     * `Illuminate\Queue\Worker::markJobAsFailedIfWillExceedMaxAttempts()`
     * (`vendor/…/Queue/Worker.php:703` and `:731`) resolves the cap as
     * `$job->maxTries() ?? $maxTries` — the JOB's own `$tries` wins over the
     * CLI value in both call sites. This test pins that precedence as a STATE:
     * a `SendMandantMail` against a dead relay, run by `queue:work --tries=1`,
     * is RELEASED — the `jobs` row survives at `attempts = 1` and nothing is
     * written to `failed_jobs`. That is what makes the supervisor's
     * `--tries=5` a floor for capless jobs and NOT a cap for this one, and it
     * is the reason the number in `deployment/backend-supervisor.sh`,
     * `scripts/dev-worker.sh` and `backend/AGENTS.md` cannot silently become
     * the mail job's real retry budget.
     *
     * The paired probe at the bottom of this class is what keeps the first half
     * from being tautological.
     */
    public function test_the_cli_tries_does_not_lower_the_job_own_cap(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $mandant = Mandant::factory()->create([
            'smtp_config' => ['host' => '127.0.0.1', 'port' => 1],
        ]);
        MandantContext::set($mandant);

        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('victim@example.test'));
        $job->onConnection('database');

        // (1) The cap is DECLARED on the job class — not assigned at dispatch
        // time by a caller. An undeclared property is exactly the regression
        // that would hand the decision to the CLI.
        $this->assertTrue(
            (new ReflectionProperty($job, 'tries'))->isPublic(),
            'SendMandantMail must carry its own public $tries, otherwise the worker CLI decides the mail retry budget',
        );
        $this->assertSame(
            5,
            $job->tries,
            'the deployment comments (supervisor script, dev-worker.sh, AGENTS.md) quote 5; a different cap makes all three wrong',
        );

        // (2) The delivery cannot succeed: 127.0.0.1:1 refuses the SMTP
        // connection, `MandantMailerService::deliver()` throws, the job rethrows.
        dispatch($job);
        $this->assertDatabaseCount('jobs', 1);

        // (3) The worker is told exactly ONE try — one below the job's cap.
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1]);

        $this->assertSame(
            1,
            DB::table('jobs')->count(),
            'the job own $tries outranks the CLI: after one attempt the job must still be queued',
        );
        $this->assertSame(
            1,
            (int) DB::table('jobs')->value('attempts'),
            'the worker did run the job exactly once before releasing it',
        );
        $this->assertSame(
            0,
            DB::table('failed_jobs')->count(),
            'a job with its own cap of 5 must NOT be dead-lettered by --tries=1',
        );
    }

    /**
     * The COUNTER-PROBE for the test above: the very same worker invocation,
     * with a job that has NO cap of its own, DOES dead-letter on attempt one.
     *
     * Without this half the first test could pass for the wrong reason — e.g.
     * if `--tries` were not honoured at all, or the failure were swallowed
     * before the worker ever decided anything. Here the CLI number demonstrably
     * reaches a capless job; there it demonstrably does not reach
     * `SendMandantMail`. The difference between the two runs is the payload.
     */
    public function test_the_same_worker_tries_does_dead_letter_a_job_without_its_own_cap(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $job = new UncappedThrowingJob;
        $job->onConnection('database');

        dispatch($job);
        $this->assertDatabaseCount('jobs', 1);

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1]);

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_mandant_admin_sees_only_the_dead_letters_of_his_own_mandant(): void
    {
        $own = $this->insertFailedMail($this->mandantA, 'own@example.test');
        $this->insertFailedMail($this->mandantB, 'foreign@example.test');

        $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/failed-mails')
            ->assertOk();

        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($own, $data[0]['id']);
        $this->assertSame($this->mandantA->id, $data[0]['mandant_id']);
        $this->assertSame('own@example.test', $data[0]['recipient']);
        $this->assertSame(PlainTestMailable::class, $data[0]['mailable']);
    }

    public function test_a_super_admin_sees_the_dead_letters_of_every_mandant(): void
    {
        $this->insertFailedMail($this->mandantA, 'a@example.test');
        $this->insertFailedMail($this->mandantB, 'b@example.test');

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/failed-mails')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_mandant_admin_requeues_his_own_dead_letter(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $id = $this->insertFailedMail($this->mandantA, 'own@example.test');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->postJson('/api/admin/failed-mails/'.$id.'/requeue')
            ->assertOk();

        $this->assertDatabaseMissing('failed_jobs', ['id' => $id]);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_mandant_admin_cannot_requeue_a_foreign_dead_letter(): void
    {
        $foreign = $this->insertFailedMail($this->mandantB, 'foreign@example.test');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->postJson('/api/admin/failed-mails/'.$foreign.'/requeue')
            ->assertStatus(404);

        $this->assertDatabaseHas('failed_jobs', ['id' => $foreign]);
    }

    public function test_a_team_admin_is_denied_at_the_route_gate(): void
    {
        $this->insertFailedMail($this->mandantA, 'own@example.test');

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/failed-mails')
            ->assertStatus(403);
    }

    /**
     * THE silent-loss case, end to end through the real worker.
     *
     * A worker is killed between claiming the delivery and completing the send
     * (SIGKILL on a hanging relay at `--timeout=60`, OOM, a container restart
     * mid-delivery). The job row survives; the claim survives; **no mail went
     * out**. The run that comes after `retry_after` finds the claim.
     *
     * MUTATION: while `handle()` returned normally on a claim hit, the worker
     * deleted the job and the outcome was measured as `jobs=0`, `failed_jobs=0`,
     * `Mail::assertNothingSent()` — the mail simply gone. This test is the
     * opposite of that: nothing is sent, and the mail is VISIBLE.
     */
    public function test_a_claim_left_by_a_dead_worker_surfaces_the_mail_instead_of_dropping_it(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        MandantContext::set($mandant);

        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('victim@example.test'));
        $job->tries = 1;
        $job->onConnection('database');
        dispatch($job);

        $this->assertDatabaseCount('jobs', 1);

        // The state a SIGKILLed worker leaves behind.
        $this->assertTrue(Cache::add('mail-delivery:'.$job->deliveryId, true, SendMandantMail::CLAIM_TTL_SECONDS));

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        Mail::assertNothingSent();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', ['mandant_id' => $mandant->id]);
        $this->assertSame(
            'victim@example.test',
            QueuedMailPayload::mailJob((string) DB::table('failed_jobs')->value('payload'))?->recipient,
            'the dead letter must still name the recipient it refused to deliver',
        );
        $this->assertStringContainsString(
            'already claimed',
            (string) DB::table('failed_jobs')->value('exception'),
            'the dead letter must say WHY it was refused, not just that it failed',
        );
    }

    /**
     * The recovery path, end to end.
     *
     * Only the claim-throw route can leave a LIVE claim on a dead letter (the
     * delivery-exception route releases it before rethrowing). So a requeue that
     * pushed the payload verbatim would push a job that is guaranteed to be
     * refused again — the human would have to wait for `CLAIM_TTL_SECONDS` to
     * expire, which is arithmetic, not design.
     *
     * MUTATION: drop the `deliveryId` re-stamp in `prepareForRequeue()` and the
     * requeued job carries the same claim key, refuses, and ends in
     * `failed_jobs` again — `Mail::assertSent` fails.
     */
    public function test_a_manual_requeue_delivers_a_claimed_mail_instead_of_waiting_for_the_claim_to_expire(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        MandantContext::set($mandant);

        $original = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('victim@example.test'));
        $id = $this->insertFailedMail($mandant, 'victim@example.test', $original);

        // The dead letter sits on a live claim.
        Cache::add('mail-delivery:'.$original->deliveryId, true, SendMandantMail::CLAIM_TTL_SECONDS);

        $this->actingAsApi($this->mandantAdmin($mandant))
            ->postJson('/api/admin/failed-mails/'.$id.'/requeue')
            ->assertOk();

        $requeued = QueuedMailPayload::mailJob((string) DB::table('jobs')->value('payload'));

        $this->assertNotNull($requeued);
        $this->assertNotSame(
            $original->deliveryId,
            $requeued->deliveryId,
            'a requeue is a fresh dispatch: it must carry a fresh claim key, otherwise the guard refuses the very delivery the human ordered',
        );

        // The mail itself survived the restricted unserialize/re-serialize round
        // trip untouched — only the identity changed.
        $this->assertSame($mandant->id, $requeued->mandantId);
        $this->assertSame(PlainTestMailable::class, $requeued->mailableClass);
        $this->assertSame('victim@example.test', $requeued->recipient);
        $this->assertSame($original->mailablePayload, $requeued->mailablePayload);

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        Mail::assertSent(PlainTestMailable::class, 1);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    /**
     * Nutzerentscheid 4: dead letters are kept UNLIMITED. The only way out is a
     * human; no scheduler entry may prune them (Nutzerentscheid 4 explicitly
     * rules out `queue:prune-failed`).
     */
    public function test_the_scheduler_never_prunes_the_dead_letter_queue(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(static fn ($event): string => (string) ($event->command ?? ''))
            ->implode(' ');

        $this->assertStringNotContainsString('queue:prune-failed', $commands);
        $this->assertStringNotContainsString('queue:forget', $commands);
    }

    /* ---------------------------------------------------------------------
     | Pagination (2026-10-06)
     | ------------------------------------------------------------------- */

    /**
     * The window the response reports for itself, and the default/ceiling
     * behind it.
     *
     * The two numbers are PINS, not documentation: `PER_PAGE_DEFAULT` is what an
     * unparameterized request gets and `PER_PAGE_MAX` is the last accepted
     * `per_page`. Both are quoted in the UI and in `features/mail-delivery.md`,
     * and a test is the only place a quoted number cannot rot quietly.
     */
    public function test_the_list_reports_its_own_window_and_the_default_page_size_is_fifty(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertFailedMail($this->mandantA, sprintf('a%02d@example.test', $i));
        }

        $this->assertSame(50, FailedMailController::PER_PAGE_DEFAULT);
        $this->assertSame(200, FailedMailController::PER_PAGE_MAX);

        $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/failed-mails')
            ->assertOk();

        $response->assertJsonPath('meta.page', 1);
        $response->assertJsonPath('meta.per_page', 50);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 1);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_pages_are_disjoint_and_cover_the_queue_without_gaps_or_repeats(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertFailedMail($this->mandantA, sprintf('a%02d@example.test', $i));
        }

        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
                ->getJson('/api/admin/failed-mails?per_page=2&page='.$page)
                ->assertOk();

            $response->assertJsonPath('meta.total', 5);
            $response->assertJsonPath('meta.last_page', 3);

            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        }

        $this->assertCount(5, $ids, 'every dead letter appears on exactly one page');
        $this->assertSame($ids, array_values(array_unique($ids)), 'no dead letter appears on two pages');

        // Newest first: `failed_at DESC, id DESC`, so the LAST insert leads.
        $expected = $this->failedMailIds($this->mandantA);
        $expected = array_reverse($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * THE test this whole change exists for: a page is short only at the END.
     *
     * The trap is a `failed_jobs` row that carries a `mandant_id` but is NOT a
     * mandant mail — a non-mail job that happens to know a mandant, or a row
     * written by hand. `whereNotNull('mandant_id')` cannot tell it apart from a
     * dead letter; `isMailJob()` can. A naive `->paginate()` therefore lets the
     * phantom eat a slot: the admin asks for 10 and gets 8, with nothing
     * anywhere saying a row was dropped.
     *
     * MEASURED against that naive shape on the same fixture class (20 mail
     * dead letters + 1 phantom, `per_page=10`): `total` = 21 for 20 mail jobs,
     * and every page boundary behind the phantom is shifted by one.
     *
     * MUTATION: replace `collectPage()` with `->forPage($page, $perPage)->get()`
     * — the phantoms sit inside the windows and the "must be FULL" assertions
     * below fail.
     */
    public function test_a_non_mail_dead_letter_shortens_no_page_and_appears_on_none(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->insertFailedMail($this->mandantA, sprintf('a%02d@example.test', $i));
        }

        // Two phantoms, one inside page 1's window and one inside page 2's.
        $this->insertPhantomDeadLetter($this->mandantA);
        $this->insertFailedMail($this->mandantA, 'a05@example.test');
        $this->insertFailedMail($this->mandantA, 'a06@example.test');
        $this->insertPhantomDeadLetter($this->mandantA);
        $this->insertFailedMail($this->mandantA, 'a07@example.test');
        $this->insertFailedMail($this->mandantA, 'a08@example.test');

        $recipients = [];

        foreach ([1, 2, 3, 4] as $page) {
            $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
                ->getJson('/api/admin/failed-mails?per_page=2&page='.$page)
                ->assertOk();

            $recipients = array_merge($recipients, array_column($response->json('data'), 'recipient'));

            if ($page < 3) {
                $this->assertCount(
                    2,
                    $response->json('data'),
                    "page {$page} must be FULL: a phantom row must not eat a slot",
                );
            }
        }

        $this->assertSame([
            'a08@example.test', 'a07@example.test', 'a06@example.test', 'a05@example.test',
            'a04@example.test', 'a03@example.test', 'a02@example.test', 'a01@example.test',
        ], $recipients, 'the eight real dead letters, newest first, exactly once each');

        // The phantoms ARE counted by `total` — the documented upper bound of the
        // SQL scope — and that is acceptable only because they never widen `data`.
        $meta = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/failed-mails?per_page=2&page=1')
            ->assertOk()
            ->json('meta');

        $this->assertSame(10, $meta['total'], 'the SQL scope count includes the phantoms');
        $this->assertSame(5, $meta['last_page']);
    }

    /**
     * The upper bound above is only acceptable because `data` never widens with
     * it. This pins that from the OTHER side: the phantom is counted, and not one
     * phantom recipient is ever served.
     *
     * MUTATION: drop the `isMailJob()` check in `collectPage()` — the phantom's
     * `recipient` appears and this fails.
     */
    public function test_the_php_filter_is_the_authority_and_not_the_sql_scope(): void
    {
        $this->insertFailedMail($this->mandantA, 'real@example.test');
        $this->insertPhantomDeadLetter($this->mandantA, 'phantom@example.test');
        $this->insertFailedMail($this->mandantA, 'also-real@example.test');

        $recipients = [];
        for ($page = 1; $page <= 2; $page++) {
            $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
                ->getJson('/api/admin/failed-mails?per_page=2&page='.$page)
                ->assertOk();
            $recipients = array_merge($recipients, array_column($response->json('data'), 'recipient'));
        }

        $this->assertNotContains('phantom@example.test', $recipients);
        $this->assertSame(['also-real@example.test', 'real@example.test'], $recipients);
    }

    /**
     * Pagination must not open the tenant boundary.
     *
     * A `mandant_admin` sees his mandant's dead letters and nothing else — on
     * EVERY page, not only the first. `total` is scoped the same way, or the
     * page counter would leak the SIZE of the other tenant's queue, which is a
     * cross-tenant read even though no row is shown.
     *
     * MUTATION: drop the `where('mandant_id', …)` from `index()` — the foreign
     * recipients appear and `meta.total` doubles.
     */
    public function test_pagination_keeps_the_mandant_scope_on_every_page(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertFailedMail($this->mandantA, sprintf('own%02d@example.test', $i));
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->insertFailedMail($this->mandantB, sprintf('foreign%02d@example.test', $i));
        }

        $admin = $this->mandantAdmin($this->mandantA);

        $seen = [];
        for ($page = 1; $page <= 2; $page++) {
            $response = $this->actingAsApi($admin)
                ->getJson('/api/admin/failed-mails?per_page=2&page='.$page)
                ->assertOk();

            $response->assertJsonPath('meta.total', 3);
            $response->assertJsonPath('meta.last_page', 2);

            foreach ($response->json('data') as $row) {
                $this->assertSame($this->mandantA->id, $row['mandant_id']);
                $seen[] = $row['recipient'];
            }
        }

        sort($seen);
        $this->assertSame(['own01@example.test', 'own02@example.test', 'own03@example.test'], $seen);

        // The super_admin still sees both, and the same numbers still add up.
        $superMeta = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/failed-mails?per_page=2&page=1')
            ->assertOk()
            ->json('meta');

        $this->assertSame(6, $superMeta['total']);
        $this->assertSame(3, $superMeta['last_page']);
    }

    /**
     * `per_page=0`, `abc`, `9999` — a client mistake is a 422, never a 500.
     *
     * The DLQ is the surface an operator opens WHILE mail is already failing, so
     * an endpoint answering 500 on a mistyped page size turns a typing slip into
     * "the dead-letter queue is broken".
     *
     * MEASURED statuses, one request each: `0` → 422, `-1` → 422, `abc` → 422,
     * `1.5` → 422, `9999` → 422, `201` → 422 (one above the ceiling), `5` → 200,
     * `200` → 200. Nothing in that list is a 500.
     *
     * ## Why the two "empty" values are 200 and not 422
     *
     * `per_page=` and `per_page=%20` are Laravel's own notion of ABSENT, not of
     * a bad value, and both fall back to the default: `ConvertEmptyStringsToNull`
     * turns `''` into `null` (which `nullable` admits), and
     * `Illuminate\Validation\Validator::present()` — the gate every rule passes
     * through — returns false for a whitespace-only string, so the `integer` rule
     * is never even consulted. Both are the framework's convention across this
     * whole API, so the page says so rather than diverging from it here; the
     * assertion below pins that it stays a 200 with the DEFAULT window and never
     * becomes a silent 0-row page.
     *
     * MUTATION: `['nullable', 'integer']` without the bounds — `0` and `9999`
     * stop being 422.
     */
    public function test_an_unusable_page_size_is_a_422_and_never_a_500(): void
    {
        $this->insertFailedMail($this->mandantA, 'own@example.test');

        $admin = $this->mandantAdmin($this->mandantA);

        foreach (['0', '-1', 'abc', '1.5', '9999', '201', 'null'] as $bad) {
            $this->actingAsApi($admin)
                ->getJson('/api/admin/failed-mails?per_page='.urlencode($bad))
                ->assertStatus(422);
        }

        foreach (['0', 'abc'] as $bad) {
            $this->actingAsApi($admin)
                ->getJson('/api/admin/failed-mails?page='.urlencode($bad))
                ->assertStatus(422);
        }

        // Both ends of the accepted range really are accepted.
        $this->actingAsApi($admin)
            ->getJson('/api/admin/failed-mails?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->actingAsApi($admin)
            ->getJson('/api/admin/failed-mails?per_page=200')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);

        // An "absent" spelling of the parameter falls back to the default
        // window — never to a 0-row page, which is the failure that would read
        // as "the queue is empty".
        foreach (['', '%20'] as $blank) {
            $this->actingAsApi($admin)
                ->getJson('/api/admin/failed-mails?per_page='.$blank)
                ->assertOk()
                ->assertJsonPath('meta.per_page', 50)
                ->assertJsonCount(1, 'data');
        }
    }

    /**
     * A page BEYOND the end is an empty page — not a 404, not a silent rewind.
     *
     * An admin who kept "next" pressed while a colleague requeued the tail must
     * land on the empty state of a page that exists: the queue is reachable, it
     * simply has nothing left on that window.
     */
    public function test_a_page_past_the_end_is_empty_and_still_reports_the_real_total(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertFailedMail($this->mandantA, sprintf('a%02d@example.test', $i));
        }

        $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/failed-mails?per_page=2&page=99')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * The phantom this pagination has to survive: a `failed_jobs` row that
     * carries a `mandant_id` but is NOT a mail job, so `whereNotNull` cannot
     * exclude it and only `isMailJob()` can.
     *
     * Deliberately built the way the provider never builds one — a hand-written
     * row, or a future non-mail job that happens to know a mandant. That is the
     * whole point: the provider itself stamps `mandant_id` only for mail jobs, so
     * this state is reachable only from OUTSIDE it, and a SQL-only discriminator
     * would still be wrong the day it is.
     */
    private function insertPhantomDeadLetter(Mandant $mandant, string $recipient = 'phantom@example.test'): int
    {
        $uuid = (string) Str::uuid();
        $other = new UncappedThrowingJob;

        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'mandant_id' => $mandant->id,
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => UncappedThrowingJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 1,
                'data' => [
                    'commandName' => UncappedThrowingJob::class,
                    'command' => serialize($other),
                ],
            ]),
            'exception' => 'RuntimeException: not a mail — but it carries a mandant',
            'failed_at' => now(),
        ]);
    }

    /**
     * The ids of `mandant`'s REAL dead letters, oldest first.
     *
     * Read back from the table rather than collected while inserting, so the
     * expected order cannot inherit a bug from the fixture itself.
     *
     * @return array<int, int>
     */
    private function failedMailIds(Mandant $mandant): array
    {
        return DB::table('failed_jobs')
            ->where('mandant_id', $mandant->id)
            ->where('payload', 'like', '%SendMandantMail%')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * A real dead letter: a `SendMandantMail` payload stored in `failed_jobs`,
     * exactly what the provider writes.
     */
    private function insertFailedMail(Mandant $mandant, string $recipient, ?SendMandantMail $job = null): int
    {
        $job ??= new SendMandantMail($mandant->id, (new PlainTestMailable)->to($recipient));
        $uuid = (string) Str::uuid();

        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'mandant_id' => $mandant->id,
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => SendMandantMail::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 5,
                'data' => [
                    'commandName' => SendMandantMail::class,
                    'command' => serialize($job),
                ],
            ]),
            'exception' => 'TransportException: connection refused',
            'failed_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function mandantAdmin(Mandant $mandant): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $mandant->id);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null): User
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

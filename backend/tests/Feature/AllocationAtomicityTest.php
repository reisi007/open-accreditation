<?php

namespace Tests\Feature;

use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationDeniedMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\User;
use App\Services\AllocationRules;
use App\Services\AllocationService;
use App\Services\SubAllocationService;
use App\Support\MandantContext;
use Closure;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\LockProbeGrammar;
use Tests\TestCase;
use Throwable;

/**
 * WP-3-a / WP-3-b (R-D4, review 2026-09-26): the allocation engines must be
 * **atomic** — the quota read and the status writes may not be interleaved with
 * a competing writer, and a partial failure may not be observable.
 *
 * ## Why the tests are split into two families
 *
 * The suite runs on SQLite `:memory:`, and **SQLite silently drops the row
 * lock**: `SQLiteGrammar::compileLock()` returns `''`, so
 * `lockForUpdate()` degrades to a plain `SELECT`. A naive "the quota can never
 * be exceeded under a race" functional test would therefore pass **with and
 * without** the lock and prove nothing. Worse, SQLite has no inter-process
 * concurrency at all in this setup (one in-memory DB per process), so a real
 * two-connection race cannot be staged here at all.
 *
 * This class therefore proves the fix in the three layers that *are* provable
 * on SQLite, and says out loud what remains for Postgres:
 *
 * 1. **Shape** — the engines really request a row lock on the quota row, and
 *    the quota read plus every status write really run inside one
 *    transaction, while the mail dispatch runs outside it.
 *    (`test_*_locks_*`, `test_the_quota_read_and_every_status_write_*`)
 * 2. **Engine** — the very lock clause that SQLite throws away is a real
 *    `for update` on Postgres, which is the production engine.
 *    (`test_sqlite_drops_the_row_lock_that_postgres_enforces`)
 * 3. **Atomicity** — a failure in the deny half rolls the approve half back
 *    and mails nothing. This is genuine all-or-nothing behaviour, provable
 *    on any engine. (`test_a_bulk_run_rolls_back_*`)
 *
 * What **cannot** be proven here and belongs to the Postgres gate of the
 * Go-Live plan ("Postgres-Portabilitäts-Gate",
 * `AGENTS.todo.md` §Go-Live-Plan Phase A): two *separate* connections racing
 * `POST /api/admin/accreditations/{id}/allocate {"mode":"all"}` against
 * `PUT /api/admin/applications/{id}` for `quota = 2` and applicants
 * `[A, B, C]`, asserting that the second writer blocks on the lock and ends up
 * with a result consistent with the first one's commit. The scenario is spelled
 * out verbatim in `test_the_quota_contract_of_two_racing_writers` so it can be
 * lifted into that integration test unchanged.
 *
 * A second gap is NOT deferrable to a future integration test, because it is a
 * property of the SCHEMA, not of the concurrency:
 * `test_a_missing_mandant_does_not_abort_the_auto_allocation_loop` needs a
 * dangling `accreditations.mandant_id`, and the only way to produce one is to
 * defer the FK check to a commit that never comes. SQLite offers that per
 * transaction (`PRAGMA defer_foreign_keys`); Postgres defers a check only for
 * constraints declared `DEFERRABLE`, and this schema declares none — no migration
 * calls `deferrable()`, and `pg_constraint.condeferrable` is false for all six
 * FKs of `accreditations`/`applications`. Staging the state on Postgres would
 * therefore mean dropping a production constraint inside the test, so that one
 * test skips itself off SQLite. It is the ONLY test of the notification guard
 * (a missing mandant must never abort the loop), i.e. the
 * corruption it feeds the engine is pinned by the SQLite run of this single
 * test — stated here so the coverage gap stays visible instead of being read as
 * "covered on both engines".
 */
class AllocationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real mandant domain, so the HTTP paths of this class resolve the
     * mandant the same way production does (host → `MandantContext`).
     */
    private const HOST = 'verband-a.test';

    private AllocationService $allocation;

    private SubAllocationService $subAllocation;

    private Mandant $mandant;

    private static int $categorySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Position 45 (2026-10-02): allocation now DISPATCHES the mail job; on
        // the suite's `sync` connection it executes inline and a dead relay is
        // no longer swallowed, so it would abort every decision-path test.
        // Individual tests re-fake it where they assert the notification.
        Mail::fake();

        $this->allocation = app(AllocationService::class);
        $this->subAllocation = app(SubAllocationService::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => self::HOST]);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | 1. The engine: SQLite drops the lock, Postgres enforces it
     | ------------------------------------------------------------------- */

    public function test_sqlite_drops_the_row_lock_that_postgres_enforces(): void
    {
        // The builder the engines build: `Accreditation::query()->lockForUpdate()
        // ->findOrFail($id)`. Compiling it with the two grammars isolates the
        // one difference that matters for the atomicity guarantee.
        $builder = DB::table('accreditations')->where('id', 7)->lockForUpdate();

        $builder->grammar = new SQLiteGrammar($this->connection());
        $sqliteSql = $builder->toSql();

        $builder->grammar = new PostgresGrammar($this->connection());
        $postgresSql = $builder->toSql();

        // Production engine (Postgres, dev + prod): a real row lock.
        $this->assertStringContainsString('for update', $postgresSql);
        $this->assertStringContainsString('from "accreditations"', $postgresSql);

        // Test engine (SQLite :memory:): the clause is gone. This is the
        // documented reason why no functional race test in this suite can
        // prove the mutual exclusion.
        $this->assertStringNotContainsString('for update', $sqliteSql);
        $this->assertStringContainsString('from "accreditations"', $sqliteSql);
    }

    /* ---------------------------------------------------------------------
     | 2. Shape: the lock is requested on the quota row
     | ------------------------------------------------------------------- */

    public function test_a_bulk_allocation_locks_its_accreditation_row_for_update(): void
    {
        $accreditation = $this->accreditation(['quota' => 2]);
        $this->applicants($accreditation, 3);

        $locks = $this->recordLocks(fn () => $this->allocation->approveAllEligible($accreditation));

        $this->assertSame(
            [['table' => 'accreditations', 'value' => true, 'compiled' => '']],
            $locks,
            'the bulk run must request exactly one SELECT … FOR UPDATE on the accreditation that owns the quota',
        );
    }

    public function test_a_manual_selection_locks_its_accreditation_row_for_update(): void
    {
        $accreditation = $this->accreditation(['quota' => 5]);
        $this->applicants($accreditation, 3);

        $locks = $this->recordLocks(fn () => $this->allocation->approveSelection($accreditation, 2));

        $this->assertSame([['table' => 'accreditations', 'value' => true, 'compiled' => '']], $locks);
    }

    public function test_a_single_approve_and_a_revoke_lock_the_same_accreditation_row(): void
    {
        $accreditation = $this->accreditation(['quota' => 5]);
        $this->applicants($accreditation, 2);

        $approveLocks = $this->recordLocks(fn () => $this->allocation->approveApplication(Application::findOrFail(
            $this->firstApplicationId($accreditation)
        )));

        $revokeLocks = $this->recordLocks(fn () => $this->allocation->denyApplication(
            Application::findOrFail($this->firstApplicationId($accreditation)),
            'Widerruf',
        ));

        // Same row, same lock on both single-action paths — that shared lock is
        // what serialises them against each other and against the bulk runs.
        $this->assertSame([['table' => 'accreditations', 'value' => true, 'compiled' => '']], $approveLocks);
        $this->assertSame([['table' => 'accreditations', 'value' => true, 'compiled' => '']], $revokeLocks);
    }

    public function test_the_sub_engine_locks_its_sub_accreditation_row_for_update(): void
    {
        $expected = [['table' => 'sub_accreditations', 'value' => true, 'compiled' => '']];

        // One sub-accreditation per engine entry point: each run consumes the
        // `requested` pool, so they cannot share a subject.
        $bulk = $this->subAccreditation(['quota' => 1]);
        $this->subApplicants($bulk, 3);

        $selection = $this->subAccreditation(['quota' => 1]);
        $this->subApplicants($selection, 3);

        $single = $this->subAccreditation(['quota' => 1]);
        $this->subApplicants($single, 1);

        $revoke = $this->subAccreditation(['quota' => 1]);
        $this->subApplicants($revoke, 1);

        $this->assertSame(
            $expected,
            $this->recordLocks(fn () => $this->subAllocation->approveAllEligible($bulk)),
        );
        $this->assertSame(
            $expected,
            $this->recordLocks(fn () => $this->subAllocation->approveSelection($selection, 1)),
        );
        $this->assertSame(
            $expected,
            $this->recordLocks(fn () => $this->subAllocation->approveSubApplication(
                $this->requestedSubApplication($single)
            )),
        );
        $this->assertSame(
            $expected,
            $this->recordLocks(fn () => $this->subAllocation->denySubApplication(
                $this->requestedSubApplication($revoke),
                'Keine Parkfläche',
            )),
        );
    }

    /* ---------------------------------------------------------------------
     | 3. Shape: quota read + status writes share one transaction
     | ------------------------------------------------------------------- */

    public function test_the_quota_read_and_every_status_write_of_a_bulk_run_share_one_transaction(): void
    {
        Mail::fake();

        $accreditation = $this->accreditation(['quota' => 2]);
        $this->applicants($accreditation, 3);

        $trace = $this->recordTrace(fn () => $this->allocation->approveAllEligible($accreditation));

        $quotaReads = $this->matching($trace, $this->isQuotaRead(...));
        $writes = $this->matching($trace, $this->isStatusWrite(...));
        $notifications = $this->matching($trace, $this->isNotificationRead(...));

        // 1 approve write + 1 quota-surplus deny write; the blacklist branch is
        // empty here, so `markDenied()` is a no-op and issues no statement.
        $this->assertCount(1, $quotaReads, 'the approved count must be read exactly once per run');
        $this->assertCount(2, $writes);

        foreach ([...$quotaReads, ...$writes] as $step) {
            $this->assertGreaterThan(
                0,
                $step['depth'],
                'quota read and status write must run inside the engine transaction: '.$step['sql'],
            );
        }

        // The plan must be computed from the quota read and written in the same
        // transaction — never "read, commit, then write".
        $this->assertLessThan($writes[0]['index'], $quotaReads[0]['index']);

        // P5: notifications are derived from committed state, i.e. AFTER the
        // commit. A mail failure can therefore never undo a decision.
        $this->assertNotSame([], $notifications, 'the run must still notify its applicants');
        foreach ($notifications as $step) {
            $this->assertSame(
                0,
                $step['depth'],
                'the notification query must run outside the engine transaction: '.$step['sql'],
            );
        }
        $this->assertGreaterThan(end($writes)['index'], $notifications[0]['index']);

        Mail::assertSent(ApplicationApprovedMail::class, 2);
        Mail::assertSent(ApplicationDeniedMail::class, 1);
    }

    public function test_a_single_approve_reads_the_quota_and_writes_inside_one_transaction(): void
    {
        Mail::fake();

        $accreditation = $this->accreditation(['quota' => 5]);
        $this->applicants($accreditation, 2);

        $trace = $this->recordTrace(fn () => $this->allocation->approveApplication(
            Application::query()
                ->where('accreditation_id', $accreditation->id)
                ->where('status', 'requested')
                ->orderBy('id')
                ->firstOrFail()
        ));

        $quotaReads = $this->matching($trace, $this->isQuotaRead(...));
        $writes = $this->matching($trace, $this->isStatusWrite(...));

        $this->assertCount(1, $quotaReads);
        $this->assertGreaterThan(0, $quotaReads[0]['depth']);

        // The first status write is the approval itself; the QR-token refresh
        // is an idempotent no-op for a fresh row, but when it does write it
        // belongs to the same transaction.
        $this->assertNotSame([], $writes);
        foreach ($writes as $step) {
            $this->assertGreaterThan(0, $step['depth'], 'status write outside the transaction: '.$step['sql']);
        }

        Mail::assertSent(ApplicationApprovedMail::class, 1);
    }

    public function test_the_sub_engine_reads_its_quota_and_writes_inside_one_transaction(): void
    {
        $sub = $this->subAccreditation(['quota' => 1]);
        $this->subApplicants($sub, 3);

        $trace = $this->recordTrace(fn () => $this->subAllocation->approveAllEligible($sub));

        $quotaReads = $this->matching($trace, fn (array $step): bool => $this->isQuotaRead($step, 'sub_accreditation_id'));
        $writes = $this->matching($trace, $this->isSubStatusWrite(...));

        $this->assertCount(1, $quotaReads);
        $this->assertGreaterThan(0, $quotaReads[0]['depth']);

        // 1 approve + 1 quota-surplus deny, both in the transaction.
        $this->assertCount(2, $writes);
        foreach ($writes as $step) {
            $this->assertGreaterThan(0, $step['depth'], 'status write outside the transaction: '.$step['sql']);
        }
    }

    /* ---------------------------------------------------------------------
     | 4. Atomicity: a failing half rolls the other half back (WP-3-b)
     | ------------------------------------------------------------------- */

    public function test_a_bulk_run_rolls_back_its_approvals_when_the_deny_phase_fails(): void
    {
        Mail::fake();

        $accreditation = $this->accreditation(['quota' => 2]);
        $this->applicants($accreditation, 3);

        // Fail the *second* half of the write sequence. The statement itself
        // still runs — the listener fires right after it, which is the only way
        // to stage a deterministic mid-sequence failure without corrupting the
        // schema.
        $this->failOnQuery(
            fn (QueryExecuted $query): bool => $this->isStatusWrite($this->step($query))
                && in_array(AllocationRules::REASON_QUOTA, $query->bindings, true),
            'deny phase exploded',
        );

        $thrown = null;

        try {
            $this->allocation->approveAllEligible($accreditation);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'the injected deny-phase failure never surfaced');
        $this->assertSame('deny phase exploded', $thrown->getMessage());

        // The approve half committed nothing. Before WP-3-b these two rows were
        // autocommitted, the surplus applicant stayed `requested` forever (the
        // auto trigger skips manual accreditations), and nobody was ever told.
        $this->assertSame(0, $this->statusCount($accreditation, 'approved'));
        $this->assertSame(0, $this->statusCount($accreditation, 'denied'));
        $this->assertSame(3, $this->statusCount($accreditation, 'requested'));

        Mail::assertNothingSent();
    }

    public function test_a_bulk_run_rolls_back_the_deny_phase_when_the_token_issuance_fails(): void
    {
        Mail::fake();

        $accreditation = $this->accreditation(['quota' => 2]);
        $this->applicants($accreditation, 2);

        // The QR token issuance sits *between* the two halves of the plan. If
        // it fails, the surplus denial must not survive it either.
        $this->failOnQuery(
            fn (QueryExecuted $query): bool => $this->isTokenWrite($this->step($query)),
            'token issuance exploded',
        );

        $thrown = null;

        try {
            $this->allocation->approveAllEligible($accreditation);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'the injected token failure never surfaced');

        $this->assertSame(0, $this->statusCount($accreditation, 'approved'));
        $this->assertSame(2, $this->statusCount($accreditation, 'requested'));
        $this->assertNull(
            Application::query()->where('accreditation_id', $accreditation->id)->whereNotNull('qr_token')->first(),
            'the token of a rolled-back approval must not survive either',
        );

        Mail::assertNothingSent();
    }

    public function test_the_sub_engine_rolls_back_its_approvals_when_the_deny_phase_fails(): void
    {
        $sub = $this->subAccreditation(['quota' => 1]);
        $this->subApplicants($sub, 3);

        $this->failOnQuery(
            fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'update "sub_applications"')
                && in_array(AllocationRules::REASON_QUOTA, $query->bindings, true),
            'sub deny phase exploded',
        );

        $thrown = null;

        try {
            $this->subAllocation->approveAllEligible($sub);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'the injected sub deny-phase failure never surfaced');

        $this->assertSame(0, $this->subStatusCount($sub, 'approved'));
        $this->assertSame(0, $this->subStatusCount($sub, 'denied'));
        $this->assertSame(3, $this->subStatusCount($sub, 'requested'));
    }

    /* ---------------------------------------------------------------------
     | 5. The quota is read from the database, not from the caller's model
     | ------------------------------------------------------------------- */

    public function test_the_quota_is_read_from_the_database_not_from_the_callers_stale_model(): void
    {
        $accreditation = $this->accreditation(['quota' => 1]);
        $stale = Accreditation::query()->findOrFail($accreditation->id);
        $this->applicants($accreditation, 3);

        // Another admin raises the quota. `$stale` still carries `quota = 1`,
        // exactly like a model that a controller bound before the change.
        $accreditation->update(['quota' => 3]);

        $result = $this->allocation->approveAllEligible($stale);

        $this->assertSame(3, $result->approved);
        $this->assertSame(3, $this->statusCount($accreditation, 'approved'));
    }

    /**
     * A guard, not a fix-detector: the single-approve path always refreshed the
     * accreditation relation anyway (`loadMissing()`), so this also passed
     * before R-D4. It is kept because the guarantee it states — "the quota
     * always comes from the database, never from a caller's model" — is now
     * enforced uniformly by `lockedAccreditation()` in all four entry points,
     * and this is the one that would otherwise be the odd one out.
     */
    public function test_a_single_approve_reads_the_quota_from_the_database_not_from_the_stale_model(): void
    {
        $accreditation = $this->accreditation(['quota' => 1]);
        $stale = Accreditation::query()->findOrFail($accreditation->id);
        $this->applicants($accreditation, 2);

        $accreditation->update(['quota' => 2]);

        $this->allocation->approveApplication(Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'requested')
            ->orderBy('id')
            ->firstOrFail());

        // The second applicant could only be approved if the engine saw the
        // raised quota instead of the value carried by its own model.
        $this->assertSame(1, $this->statusCount($accreditation, 'approved'));
        $this->assertLessThanOrEqual(
            $stale->quota + 1,
            $this->statusCount($accreditation, 'approved'),
            'a stale model may never cap the run below the database quota',
        );
    }

    /* ---------------------------------------------------------------------
     | 6. The quota contract of two racing writers
     | ------------------------------------------------------------------- */

    public function test_the_quota_contract_of_two_racing_writers(): void
    {
        // PROVEN HERE (SQLite, sequential): the second writer's quota
        // arithmetic includes the first writer's committed approvals, and the
        // quota is never exceeded afterwards.
        //
        // NOT PROVEN HERE (needs the Postgres gate): the two writers *overlap*.
        // Scenario for the integration test — `quota = 2`, applicants
        // `[A, B, C]`, two separate connections:
        //   T1: POST /api/admin/accreditations/{id}/allocate {"mode":"all"}
        //       (reads approved = 0, plans approve [A,B] + deny_quota [C])
        //   T2: PUT  /api/admin/applications/C  {"status":"approved"}
        // Assert: T2 blocks on the accreditation row lock until T1 commits,
        // then re-reads approved = 2 and answers 422 "Quota erschöpft"; the
        // final state is exactly 2 approved and 1 denied, and the response
        // counters match the database. Without the lock T1's deny write finds
        // 0 rows (`where status = 'requested'`) and the quota is exceeded.
        $accreditation = $this->accreditation(['quota' => 3]);
        $this->applicants($accreditation, 4);

        $stale = Accreditation::query()->findOrFail($accreditation->id);

        $first = $this->allocation->approveSelection($accreditation, 1);
        $second = $this->allocation->approveSelection($stale, 5);

        $this->assertSame(1, $first->approved);
        $this->assertSame(2, $second->approved);
        $this->assertSame(3, $this->statusCount($accreditation, 'approved'));
        $this->assertLessThanOrEqual($accreditation->fresh()->quota, $this->statusCount($accreditation, 'approved'));
    }

    /* ---------------------------------------------------------------------
     | 7. The applicant-side withdraw is a writer too (A2)
     | ------------------------------------------------------------------- */

    /**
     * `DELETE /api/applications/{id}` deletes a `requested` row — the exact
     * rows the engine approves/denies. It read the status once, outside any
     * transaction, and then issued an unconditional `DELETE`, so a decision
     * that landed in between silently removed an application the applicant had
     * already been told about (and the admin had already queued a badge for).
     *
     * Staged with a `DB::listen` hook that flips the row to `denied` right
     * after the controller's first read — the interleaving a second admin's
     * request produces, made deterministic.
     *
     * FAILS WITHOUT THE FIX: 204 + the row gone.
     */
    public function test_a_withdraw_that_loses_the_race_against_a_decision_answers_409(): void
    {
        $this->seed(RoleSeeder::class);

        $applicant = $this->memberOfMandant();
        $accreditation = $this->accreditation(['quota' => 5]);
        $application = $this->applicantRow($accreditation, $applicant, 'requested');

        $this->decideOnFirstApplicationRead($application, 'denied', 'Unterlagen fehlen');

        $this->actingAsApi($applicant)
            ->deleteJson('http://'.self::HOST.'/api/applications/'.$application->id)
            ->assertStatus(409);

        // The row survives with the COMPETING decision — the withdraw must not
        // have eaten an application that was decided in the meantime.
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'denied',
            'reason' => 'Unterlagen fehlen',
        ]);
    }

    /**
     * The unchanged happy path next to the race: nothing decided in between
     * ⇒ the withdraw still deletes the row and answers 204. Without it the
     * 409 above could be "everything is a conflict now".
     */
    public function test_a_withdraw_without_a_competing_decision_still_deletes_the_row(): void
    {
        $this->seed(RoleSeeder::class);

        $applicant = $this->memberOfMandant();
        $accreditation = $this->accreditation(['quota' => 5]);
        $application = $this->applicantRow($accreditation, $applicant, 'requested');

        $this->actingAsApi($applicant)
            ->deleteJson('http://'.self::HOST.'/api/applications/'.$application->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
    }

    /* ---------------------------------------------------------------------
     | 8. The single approve/deny write re-states its precondition (A4)
     | ------------------------------------------------------------------- */

    /**
     * `approveApplication()` validated the transition against the CALLER's
     * model — read before the accreditation row lock — and then wrote with an
     * unguarded `update()`. An admin clicking "approve" on a screen rendered
     * before somebody else's decision therefore overwrote that decision
     * instead of learning that it happened.
     *
     * Staged with a `denied` row (the approve path re-approves those, so only
     * an out-of-band write can take the row out of `{requested, denied}` behind
     * the caller's back).
     *
     * FAILS WITHOUT THE FIX: no exception, the row is re-approved and a QR
     * token is issued for a decision the admin never made.
     */
    public function test_a_single_approve_whose_row_left_the_allowed_states_answers_409(): void
    {
        $accreditation = $this->accreditation(['quota' => 5]);
        $stale = $this->applicantRow($accreditation, User::factory()->create(), 'denied', 'Quota erschöpft');

        // The competing approval lands after `$stale` was read.
        Application::query()->whereKey($stale->id)->update(['status' => 'approved', 'reason' => null]);

        $thrown = null;

        try {
            $this->allocation->approveApplication($stale);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ConflictHttpException::class, $thrown, 'the lost race was silently accepted');
        $this->assertSame(409, $thrown->getStatusCode(), 'a lost race is a CONFLICT, not a validation error');
        $this->assertSame(AllocationService::REASON_CONCURRENT_CHANGE, $thrown->getMessage());

        $this->assertSame(0, $this->statusCount($accreditation, 'approved') - 1);
        $this->assertNull(
            Application::query()->whereKey($stale->id)->value('qr_token'),
            'no QR token may be issued for a decision this call did not make',
        );
    }

    /**
     * The mirror image on the deny/revoke path: the competing decision's
     * REASON must survive — the applicant has to be able to read why his
     * application actually fell.
     *
     * FAILS WITHOUT THE FIX: no exception and the reason is overwritten with
     * the stale one.
     */
    public function test_a_single_revoke_whose_row_left_the_allowed_states_answers_409(): void
    {
        $accreditation = $this->accreditation(['quota' => 5]);
        $stale = $this->applicantRow($accreditation, User::factory()->create(), 'approved');

        Application::query()->whereKey($stale->id)->update([
            'status' => 'denied',
            'reason' => 'Konkurrenzentscheid',
        ]);

        $thrown = null;

        try {
            $this->allocation->denyApplication($stale, 'Widerruf');
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ConflictHttpException::class, $thrown, 'the lost race was silently accepted');
        $this->assertSame(409, $thrown->getStatusCode());

        $this->assertDatabaseHas('applications', [
            'id' => $stale->id,
            'status' => 'denied',
            'reason' => 'Konkurrenzentscheid',
        ]);
    }

    /**
     * The positive control for the guarded write: a re-approval of a `denied`
     * row (and a revoke of an `approved` one) still works — the guard narrows
     * on the states each path already accepted, it does not forbid them.
     */
    public function test_the_guarded_write_keeps_the_legitimate_re_approval_of_a_denied_row(): void
    {
        $accreditation = $this->accreditation(['quota' => 5]);
        $application = $this->applicantRow($accreditation, User::factory()->create(), 'denied', 'Quota erschöpft');

        $this->allocation->approveApplication($application);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'approved',
            'reason' => null,
        ]);
    }

    /* ---------------------------------------------------------------------
     | 9. The post-commit notification must never abort the run (A3)
     | ------------------------------------------------------------------- */

    /**
     * `MandantMailerService::send()` takes a non-nullable `Mandant`, so a
     * missing one is a `TypeError` raised while evaluating the ARGUMENT — i.e.
     * before the mailer's own `try`, which is the "a broken relay must never
     * break the allocation" policy. It therefore escaped and aborted
     * `runAutoAllocations()`, so ONE unreadable row silently starved every
     * remaining accreditation of its nightly run.
     *
     * The inconsistent state is staged with SQLite's `defer_foreign_keys`:
     * `accreditations.mandant_id` is pointed at a mandant that does not exist.
     * The accreditation (and with it the application) survives, so the engine
     * still finds the applicant — it is only the MANDANT that the notification
     * needs and cannot resolve.
     *
     * That staging step is what needs SQLite (see the class docblock for the
     * verified reason: Postgres defers only `DEFERRABLE` constraints, and this
     * schema declares none), so the test pins the guard where it can run — on
     * the engine the suite normally runs on — and skips itself elsewhere
     * instead of pretending to have covered the corruption.
     *
     * FAILS WITHOUT THE FIX: `TypeError` out of `runAutoAllocations()`, the
     * second accreditation is never processed and nobody is notified.
     */
    public function test_a_missing_mandant_does_not_abort_the_auto_allocation_loop(): void
    {
        if ($this->connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped(
                'needs a DEFERRED foreign-key check: the corruption this test feeds the engine is '
                .'staged by deferring the accreditations -> mandants FK to a commit that never comes. '
                .'SQLite defers FK checks per transaction (PRAGMA defer_foreign_keys); Postgres defers '
                .'only constraints declared DEFERRABLE, and this schema declares none, so the dangling '
                .'mandant_id cannot be staged here without dropping a production constraint.',
            );
        }

        Mail::fake();
        Log::spy();

        $broken = $this->autoAccreditation();
        $healthy = $this->autoAccreditation();
        $brokenUser = $this->applicantRow($broken, User::factory()->create(), 'requested');
        $healthyUser = $this->applicantRow($healthy, User::factory()->create(), 'requested');

        $this->orphanTheAccreditationsMandant($broken);

        $results = $this->allocation->runAutoAllocations();

        // The loop ran to the end: the broken accreditation did not starve the
        // next one.
        $this->assertSame([
            $broken->id => ['approved' => 1, 'denied' => 0],
            $healthy->id => ['approved' => 1, 'denied' => 0],
        ], $results);

        $this->assertDatabaseHas('applications', ['id' => $brokenUser->id, 'status' => 'approved']);
        $this->assertDatabaseHas('applications', ['id' => $healthyUser->id, 'status' => 'approved']);

        // Exactly one mail went out: the unreadable row is skipped (audited),
        // the healthy applicant is still told.
        Mail::assertSent(ApplicationApprovedMail::class, 1);
        Log::shouldHaveReceived('warning')->once();
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Flip one application to a decided status the moment the withdraw reads it
     * for its own decision — the deterministic stand-in for a second writer's
     * request landing in between (SQLite has no real concurrency in this
     * setup, see the class docblock).
     *
     * The hook matches the read by its `user_id` filter, i.e. the OWNERSHIP
     * read of `ApplicationController::destroy()` — not the route-model binding
     * that ran a moment earlier and not the in-transaction re-read that runs
     * after. Firing exactly there is what makes the scenario a real race: the
     * controller has just decided "this row is `requested`, I may delete it",
     * and the row changes before the delete lands.
     */
    private function decideOnFirstApplicationRead(Application $application, string $status, string $reason): void
    {
        $fired = false;

        DB::listen(static function (QueryExecuted $query) use (&$fired, $application, $status, $reason): void {
            if ($fired
                || ! str_starts_with($query->sql, 'select * from "applications"')
                || ! str_contains($query->sql, '"user_id" = ?')) {
                return;
            }

            $fired = true;

            Application::query()->whereKey($application->id)->update([
                'status' => $status,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Point one accreditation at a mandant that does not exist. The FK
     * violation is DEFERRED to a commit that never comes (`RefreshDatabase`
     * rolls the outer transaction back), so the accreditation and its
     * applications survive with a dangling `mandant_id` — the exact shape the
     * notification guard has to survive.
     *
     * SQLite-specific by necessity: `defer_foreign_keys` is the only way to
     * create the inconsistency, because
     * `accreditations → applications → mandants` are all `cascadeOnDelete`
     * and a plain delete would take the applications with it. Postgres has no
     * per-transaction equivalent for a constraint that is not `DEFERRABLE`
     * (`SET CONSTRAINTS ALL DEFERRED` is a no-op here — verified against
     * `pg_constraint`), so the caller must guard itself; the test using this
     * helper does.
     */
    private function orphanTheAccreditationsMandant(Accreditation $accreditation): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');

        DB::table('accreditations')
            ->where('id', $accreditation->id)
            ->update(['mandant_id' => $accreditation->id + 100000]);

        $this->assertNull(
            DB::table('accreditations')->where('id', $accreditation->id)->value('mandant_id')
                ? Mandant::query()->find($accreditation->id + 100000)
                : null,
            'precondition: the mandant of the accreditation does not resolve',
        );
    }

    /**
     * An `active`, `auto_approve` accreditation whose deadline has passed — the
     * only shape `runAutoAllocations()` picks up.
     */
    private function autoAccreditation(): Accreditation
    {
        return $this->accreditation([
            'quota' => 1,
            'auto_approve' => true,
            'deadline_start' => now()->subDays(3)->toDateString(),
            'deadline_end' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * An applicant of the current mandant, with the role row a real account
     * always carries (`EnsureMandantMembership` refuses one without it).
     */
    private function memberOfMandant(): User
    {
        $user = User::factory()->forMandant($this->mandant)->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', 'user')->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }

    private function applicantRow(Accreditation $accreditation, User $user, string $status, ?string $reason = null): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => $status,
            'reason' => $reason,
            'priority' => false,
        ]);
    }

    private function connection(): Connection
    {
        return DB::connection();
    }

    /**
     * The SQL the engines emit, re-classified step by step.
     *
     * `depth` is the transaction nesting level **relative to the level the test
     * started at** — `RefreshDatabase` already runs every test inside one
     * transaction, so an absolute level would prove nothing.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>, index: int, depth: int}>
     */
    private function recordTrace(Closure $callback): array
    {
        $connection = $this->connection();
        $baseline = $connection->transactionLevel();
        $trace = [];

        DB::listen(static function (QueryExecuted $query) use ($connection, $baseline, &$trace): void {
            $trace[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'index' => count($trace),
                'depth' => $connection->transactionLevel() - $baseline,
            ];
        });

        $callback();

        return $trace;
    }

    /**
     * Every row lock the code under test requested, in order.
     *
     * @return list<array{table: string, value: bool|string, compiled: string}>
     */
    private function recordLocks(Closure $callback): array
    {
        $connection = $this->connection();
        $original = $connection->getQueryGrammar();

        $probe = new LockProbeGrammar($connection);
        $probe->setTablePrefix($original->getTablePrefix());
        $connection->setQueryGrammar($probe);

        try {
            $callback();
        } finally {
            $connection->setQueryGrammar($original);
        }

        return $probe->locks;
    }

    /**
     * Make the next matching query throw, without breaking the schema.
     *
     * @param  Closure(QueryExecuted): bool  $matches
     */
    private function failOnQuery(Closure $matches, string $message): void
    {
        DB::listen(static function (QueryExecuted $query) use ($matches, $message): void {
            if ($matches($query)) {
                throw new RuntimeException($message);
            }
        });
    }

    /**
     * Reduce a query event to the shape the classifiers below expect.
     *
     * @return array{sql: string, bindings: array<int, mixed>, index: int, depth: int}
     */
    private function step(QueryExecuted $query): array
    {
        return ['sql' => $query->sql, 'bindings' => $query->bindings, 'index' => 0, 'depth' => 0];
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>, index: int, depth: int}>  $trace
     * @param  Closure(array): bool  $predicate
     * @return list<array{sql: string, bindings: array<int, mixed>, index: int, depth: int}>
     */
    private function matching(array $trace, Closure $predicate): array
    {
        return array_values(array_filter($trace, $predicate));
    }

    /**
     * The quota read of one engine: `select count(*) … where <quota fk> = ? and
     * "status" = ?`.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $step
     */
    private function isQuotaRead(array $step, string $column = 'accreditation_id'): bool
    {
        return str_contains($step['sql'], 'count(*)')
            && str_contains($step['sql'], '"'.$column.'" = ?')
            && str_contains($step['sql'], '"status" = ?');
    }

    /**
     * A bulk status write of the main engine.
     *
     * Matched on `set "status" = ?` rather than on the table alone: the QR
     * token issuance in between (`update "applications" set "qr_token" = ? …`)
     * touches the same table and is *also* part of the transaction, but it is
     * not an allocation decision.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $step
     */
    private function isStatusWrite(array $step): bool
    {
        return str_starts_with($step['sql'], 'update "applications"')
            && str_contains($step['sql'], 'set "status" = ?');
    }

    /**
     * @param  array{sql: string, bindings: array<int, mixed>}  $step
     */
    private function isSubStatusWrite(array $step): bool
    {
        return str_starts_with($step['sql'], 'update "sub_applications"');
    }

    /**
     * The P4 QR token write of a just-approved row.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $step
     */
    private function isTokenWrite(array $step): bool
    {
        return str_starts_with($step['sql'], 'update "applications"')
            && str_contains($step['sql'], 'set "qr_token" = ?');
    }

    /**
     * The re-read that feeds the P5 notification.
     *
     * `where "id" in (…)` AND `status = ?` together are unique to
     * `dispatchApprovedMails()` / `dispatchDeniedMails()`: the candidate query
     * filters on `accreditation_id`, and the token issuance has no status
     * filter.
     *
     * @param  array{sql: string, bindings: array<int, mixed>}  $step
     */
    private function isNotificationRead(array $step): bool
    {
        return str_starts_with($step['sql'], 'select * from "applications"')
            && str_contains($step['sql'], '"id" in (')
            && str_contains($step['sql'], '"status" = ?');
    }

    private function accreditation(array $attributes = []): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function subAccreditation(array $attributes = []): SubAccreditation
    {
        return $this->accreditation(['quota' => 20])
            ->subAccreditations()
            ->create(['type' => 'park', 'quota' => 5, ...$attributes]);
    }

    private function applicants(Accreditation $accreditation, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Carbon::setTestNow('2026-08-01 '.str_pad((string) (9 + $i), 2, '0', STR_PAD_LEFT).':00:00');

            Application::create([
                'accreditation_id' => $accreditation->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'requested',
                'priority' => false,
            ]);
        }

        Carbon::setTestNow();
    }

    private function subApplicants(SubAccreditation $sub, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create();

            $application = Application::create([
                'accreditation_id' => $sub->accreditation_id,
                'user_id' => $user->id,
                'status' => 'approved',
                'priority' => false,
            ]);

            SubApplication::create([
                'sub_accreditation_id' => $sub->id,
                'application_id' => $application->id,
                'user_id' => $user->id,
                'status' => 'requested',
                'priority' => false,
            ]);
        }
    }

    private function firstApplicationId(Accreditation $accreditation): int
    {
        return (int) Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->orderBy('id')
            ->value('id');
    }

    private function requestedSubApplication(SubAccreditation $sub): SubApplication
    {
        return SubApplication::query()
            ->where('sub_accreditation_id', $sub->id)
            ->where('status', 'requested')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function statusCount(Accreditation $accreditation, string $status): int
    {
        return Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', $status)
            ->count();
    }

    private function subStatusCount(SubAccreditation $sub, string $status): int
    {
        return SubApplication::query()
            ->where('sub_accreditation_id', $sub->id)
            ->where('status', $status)
            ->count();
    }
}

<?php

namespace App\Services;

use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationDeniedMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\SubApplication;
use App\Support\VerifyLink;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P3c allocation engine — the authoritative "who gets a quota slot" decision.
 *
 * Deterministic order: (1) VIP (`priority = true`) before everyone else,
 * (2) within the same priority first-come-first-served (`created_at ASC`),
 * (3) tie-break `id ASC`. No randomness, no raw SQL with unbound input.
 *
 * The engine never approves a blacklisted user and never exceeds the quota
 * (max `approved` count). Only applications in status `requested` are
 * candidates and every status change happens through this service (D8). The
 * manual entry points may run at any time — the deadline window is enforced
 * at apply time (P3b), not here; the automatic trigger
 * (`runAutoAllocations`) fires only after `deadline_end` (end of day
 * 23:59:59) has passed. All queries stay portable between Postgres (dev) and
 * SQLite :memory: (tests). The shared rules (ordering, blacklist, deadline
 * math, partitioning) live in `AllocationRules` and are reused verbatim by
 * the P3d sub-allocation engine.
 *
 * ## Atomicity (R-D4, review 2026-09-26)
 *
 * "Quota wird nie überschritten" is only true if the quota read and the
 * status write cannot be interleaved with a competing writer. Read-then-write
 * in autocommit does not give that: a bulk run that planned `approve [A, B]`
 * and `deny_quota [C]` against `quota = 2` could have C approved by a
 * concurrent single-approve between plan and write, and its own deny write
 * (`where status = 'requested'`) would then match **zero** rows — three
 * approvals against a quota of two, plus an applicant who is never told.
 *
 * Every entry point therefore wraps the quota read **and** the status writes
 * in one `DB::transaction` and takes a `lockForUpdate()` row lock on the
 * accreditation row (the row that owns the quota). All competing writers of
 * the same accreditation serialise on it, so the second one re-reads the
 * first one's committed state.
 *
 * The row lock is portable SQL (plain `SELECT … FOR UPDATE`, no advisory
 * locks, which SQLite does not have). Note that **SQLite silently drops the
 * lock clause** — `SQLiteGrammar::compileLock()` returns `''` — so the
 * suite proves the *shape* (lock requested, inside a transaction) and the
 * *atomicity* (rollback), not the mutual exclusion itself. The mutual
 * exclusion is engine-verified by the Postgres gate in the Go-Live plan
 * ("Postgres-Portabilitäts-Gate"), see
 * `features/accreditation/01-allocation-engine.md`.
 *
 * Mails are dispatched **after** the transaction committed: a mail transport
 * failure must never roll back a decision, and a `TransactionRolledBack`
 * would be swallowed by `MandantMailerService` anyway.
 */
final class AllocationService
{
    /**
     * The `reason` written onto sub-applications when the main application
     * they hang on is revoked (R-D5). Deliberately NOT the admin's own reason
     * for the main row: the applicant of the Park-/Sitzkarte needs to read why
     * *their* row fell, and it is always the same cause.
     */
    public const REASON_PARENT_REVOKED = 'Haupt-Akkreditierung entzogen';

    public function __construct(
        private readonly QrTokenService $qrTokenService,
        private readonly MandantMailerService $mandantMailer,
    ) {}

    /**
     * Approve the "first X" eligible requested applications (manual mode).
     * Approves at most `min(limit, quota - approved)` candidates, skipping
     * blacklisted users (they stay `requested` — the admin may lift the
     * blacklist later). `limit <= 0` does nothing. Idempotent: a second run
     * finds no `requested` candidates left.
     *
     * The quota read and the status write run inside one transaction under a
     * row lock on the accreditation (R-D4, see the class docblock).
     */
    public function approveSelection(Accreditation $accreditation, int $limit): AllocationResult
    {
        if ($limit <= 0) {
            return AllocationResult::none();
        }

        $outcome = DB::transaction(function () use ($accreditation, $limit): array {
            // Re-read under the row lock: `$accreditation` may have been loaded
            // before a competing run changed the quota or the approved count.
            $locked = $this->lockedAccreditation($accreditation->getKey());

            $applications = $this->eligibleRequested($locked);
            $blacklist = AllocationRules::blacklistFor((int) $locked->mandant_id);

            $remaining = min($limit, $locked->quota - $this->approvedCount($locked));

            if ($remaining <= 0) {
                return ['approved' => [], 'result' => AllocationResult::none()];
            }

            $plan = AllocationRules::distributeSelection($applications, $remaining, $blacklist);

            AllocationRules::markApproved(Application::class, $plan['approve']);
            $this->issueQrTokens($plan['approve']);

            return [
                'approved' => $plan['approve'],
                'result' => new AllocationResult(count($plan['approve']), 0, $plan['skipped_blacklist']),
            ];
        });

        // P5: after the commit, so a mail failure cannot undo the decision.
        $this->dispatchApprovedMails($outcome['approved']);

        return $outcome['result'];
    }

    /**
     * Approve every eligible requested application (auto + manual "alle
     * freigeben") until the quota is reached. Surplus requested applications
     * become `denied` with reason `Quota erschöpft`; blacklist matches become
     * `denied` with reason `Blacklist`. Idempotent: a second run finds no
     * `requested` candidates.
     *
     * All four writes (approve, QR tokens, quota-deny, blacklist-deny) share
     * **one** transaction under a row lock on the accreditation, so a partial
     * failure can never leave surplus applications stuck in `requested` on a
     * manual accreditation — a state the auto trigger never revisits, so those
     * applicants would wait forever and never be told (R-D4, WP-3-b).
     */
    public function approveAllEligible(Accreditation $accreditation): AllocationResult
    {
        $outcome = DB::transaction(function () use ($accreditation): array {
            $locked = $this->lockedAccreditation($accreditation->getKey());

            $applications = $this->eligibleRequested($locked);
            $blacklist = AllocationRules::blacklistFor((int) $locked->mandant_id);

            $plan = AllocationRules::distributeAll(
                $applications,
                $locked->quota,
                $this->approvedCount($locked),
                $blacklist,
            );

            $denied = array_merge($plan['deny_quota'], $plan['deny_blacklist']);

            AllocationRules::markApproved(Application::class, $plan['approve']);
            $this->issueQrTokens($plan['approve']);
            AllocationRules::markDenied(Application::class, $plan['deny_quota'], AllocationRules::REASON_QUOTA);
            AllocationRules::markDenied(Application::class, $plan['deny_blacklist'], AllocationRules::REASON_BLACKLIST);

            return [
                'approved' => $plan['approve'],
                'denied' => $denied,
                'result' => new AllocationResult(
                    count($plan['approve']),
                    count($denied),
                    count($plan['deny_blacklist']),
                ),
            ];
        });

        // P5: after the commit — the notifications are derived from committed
        // state, and a mail failure must not undo the allocation.
        $this->dispatchApprovedMails($outcome['approved']);
        $this->dispatchDeniedMails($outcome['denied']);

        return $outcome['result'];
    }

    /**
     * Single-application approval (P3e admin action) — the authoritative
     * "who gets a quota slot" decision for one row. The blacklist guard and
     * the quota check run here, never in the controller:
     *
     * - a blacklisted user (email/domain, mandant-scoped) can never be
     *   approved (422),
     * - the approved count must stay below the quota (422 `Quota erschöpft`)
     *   — the single approve respects the quota like the bulk engines do,
     * - only `requested` and `denied` rows may be (re-)approved; `approved`,
     *   `blacklisted` and any other status are invalid transitions (422).
     *
     * Approving clears the deny reason. Not idempotent on purpose — approving
     * an already-approved row is a client error, not a no-op.
     *
     * The quota check and the status write share one transaction under a row
     * lock on the accreditation, so a concurrent bulk run or single approve of
     * the same accreditation cannot slip an approval in between (R-D4).
     *
     * @throws ValidationException
     */
    public function approveApplication(Application $application): Application
    {
        // The full accreditation context is loaded for the P5 notification
        // mail (category/event/team/mandant domain feed the mailable and the
        // verify link); the guard only needs the mandant and the quota.
        $application->loadMissing([
            'accreditation.category',
            'accreditation.event',
            'accreditation.team',
            'accreditation.mandant.domains',
            'user:id,email,name',
        ]);

        $accreditationId = (int) $application->accreditation_id;

        DB::transaction(function () use ($application, $accreditationId): void {
            $locked = $this->lockedAccreditation($accreditationId);

            if (AllocationRules::isBlacklisted(
                $application->user,
                AllocationRules::blacklistFor((int) $locked->mandant_id),
            )) {
                throw ValidationException::withMessages([
                    'status' => 'User is blacklisted',
                ]);
            }

            if (! in_array($application->status, ['requested', 'denied'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only requested or denied applications can be approved.',
                ]);
            }

            if ($this->approvedCount($locked) >= $locked->quota) {
                throw ValidationException::withMessages([
                    'status' => AllocationRules::REASON_QUOTA,
                ]);
            }

            $application->update([
                'status' => 'approved',
                'reason' => null,
            ]);

            // P4: every newly approved application receives its deterministic QR
            // verification token (idempotent — a re-approval after a revoke keeps
            // the same token). Inside the transaction on purpose: the token is part
            // of the approval, it must not survive a rolled-back decision.
            $this->qrTokenService->make($application);
        });

        // P5: notify the applicant — only after the commit, so a failed mail
        // delivery never rolls back the approval (see the service).
        $this->mandantMailer->send(
            $application->accreditation->mandant,
            new ApplicationApprovedMail($application, VerifyLink::for($application)),
        );

        return $application;
    }

    /**
     * Single-application denial (P3e admin action). A non-empty `$reason` is
     * mandatory (422 otherwise). Only `requested` (deny) and `approved`
     * (revoke) rows may be denied; `denied` and `blacklisted` rows are
     * invalid transitions (422).
     *
     * Revoking an `approved` row cascades (R-D5): every **approved**
     * sub-application that sits on top of it becomes `denied` with its own
     * reason (`self::REASON_PARENT_REVOKED`), in the same transaction as the
     * parent status write. D9 only ever allowed a sub-application on an
     * approved main application, so without the cascade a revoked Park-/
     * Sitzkarte stayed `approved` and kept handing out a valid `.pkpass`.
     *
     * @throws ValidationException
     */
    public function denyApplication(Application $application, string $reason): Application
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when denying an application.',
            ]);
        }

        if (! in_array($application->status, ['requested', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only requested or approved applications can be denied.',
            ]);
        }

        // Full accreditation context for the P5 denial mail (reason, category/
        // event/team, mandant).
        $application->loadMissing([
            'accreditation.category',
            'accreditation.event',
            'accreditation.team',
            'accreditation.mandant.domains',
            'user:id,email,name',
        ]);

        $accreditationId = (int) $application->accreditation_id;

        DB::transaction(function () use ($application, $reason, $accreditationId): void {
            // Same lock as every other writer of this accreditation, so a bulk
            // run either sees this revoke or is serialised behind it.
            $this->lockedAccreditation($accreditationId);

            $application->update([
                'status' => 'denied',
                'reason' => $reason,
            ]);

            $this->cascadeRevokedSubApplications($application);
        });

        // P5: notify the applicant — only after the commit.
        $this->mandantMailer->send(
            $application->accreditation->mandant,
            new ApplicationDeniedMail($application, $reason),
        );

        return $application;
    }

    /**
     * R-D5: revoke the sub-applications that lose their foundation.
     *
     * A sub-application is only ever created on an **approved** main
     * application (`SubAccreditationController::apply`, D9). Once that main
     * application is `denied` the sub-row is meaningless, so every
     * `approved` sub-row on it moves to `denied` with the engine's own reason.
     *
     * Deliberately narrow:
     *
     * - only `approved` rows cascade. A `requested` sub-row is a pending
     *   applicant — denying it is an admin decision, and the admin still has
     *   one (he may re-approve the main row first). A `denied` row is final.
     * - the write is **not** routed through `AllocationRules::markDenied()`,
     *   which is the `requested → denied` path of the allocation plan. This is
     *   an `approved → denied` invalidation with a different trigger.
     * - no mail is sent for the cascaded rows. Sub-status changes are not
     *   notified at all (a documented gap, see
     *   `features/accreditation/01-allocation-engine.md`); inventing mails
     *   here would be a half-feature.
     */
    private function cascadeRevokedSubApplications(Application $application): void
    {
        SubApplication::query()
            ->where('application_id', $application->getKey())
            ->where('status', 'approved')
            ->update([
                'status' => 'denied',
                'reason' => self::REASON_PARENT_REVOKED,
            ]);
    }

    /**
     * Set (or clear) the VIP priority of one application (P3e admin action).
     * A direct field update — no status change, no guards.
     */
    public function setPriority(Application $application, bool $priority): Application
    {
        $application->update(['priority' => $priority]);

        return $application;
    }

    /**
     * Automatic trigger: process every active accreditation with
     * `auto_approve = true` whose `deadline_end` (end of day, 23:59:59) has
     * passed. Returns `[accreditation_id => ['approved' => n, 'denied' => m]]`
     * for the processed accreditations only.
     *
     * @return array<int, array{approved: int, denied: int}>
     */
    public function runAutoAllocations(?DateTimeInterface $now = null): array
    {
        $now = $now ?? now();

        $results = [];

        foreach ($this->autoEligibleAccreditations() as $accreditation) {
            if (! AllocationRules::hasDeadlinePassed($accreditation->deadline_end, $now)) {
                continue;
            }

            $result = $this->approveAllEligible($accreditation);

            $results[$accreditation->id] = [
                'approved' => $result->approved,
                'denied' => $result->denied,
            ];
        }

        return $results;
    }

    /**
     * Active, auto-approve accreditations that carry a deadline.
     */
    private function autoEligibleAccreditations(): Collection
    {
        return Accreditation::query()
            ->active()
            ->where('auto_approve', true)
            ->whereNotNull('deadline_end')
            ->orderBy('id')
            ->get();
    }

    /**
     * All `requested` applications of one accreditation in allocation order
     * (VIP first, then FCFS, then id), eager-loaded with their user.
     */
    private function eligibleRequested(Accreditation $accreditation): Collection
    {
        return AllocationRules::orderEligible(
            Application::query()
                ->where('accreditation_id', $accreditation->id)
                ->where('status', 'requested')
                ->with('user:id,email'),
        )->get();
    }

    /**
     * Re-read one accreditation under a row lock — the serialisation point of
     * every write in this service (R-D4).
     *
     * `lockForUpdate()` compiles to `SELECT … FOR UPDATE` on Postgres (the
     * production engine) and is silently dropped by `SQLiteGrammar`
     * (`compileLock()` returns `''`), so the test suite can only observe that
     * the lock is *requested* — never that it is *held*. The mutual exclusion
     * is verified by the Postgres gate of the Go-Live plan; see
     * `features/accreditation/01-allocation-engine.md`.
     *
     * The returned model is the authoritative quota for this run: the caller's
     * instance may predate a quota change by another admin.
     */
    private function lockedAccreditation(int|string $id): Accreditation
    {
        return Accreditation::query()
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function approvedCount(Accreditation $accreditation): int
    {
        return Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->count();
    }

    /**
     * Issue the P4 QR verification token for every application that was just
     * marked approved by a bulk allocation.
     *
     * No `whereNull('qr_token')` filter: every write path must be able to UPGRADE
     * a stored legacy v1 token to the tenant-bound v2 format
     * (`QrTokenService`'s documented invariant, restated in
     * `features/badges-qr.md` and `AdminApplicationResource::verifyToken()`).
     * Filtering on `qr_token IS NULL` here made the bulk path the ONE write path
     * that silently left a v1 token in place, so
     * `AdminApplicationResource::verifyToken()` served a fresh v2 URL while the
     * column still showed v1 — a display/column divergence and a badge that
     * only verified through the legacy (tenant-unbound) branch.
     *
     * This is not write amplification: `QrTokenService::make()` is idempotent —
     * a row that already holds a valid, tenant-bound v2 token returns early
     * after one HMAC verification and performs NO write. Dropping the filter
     * therefore costs one `parse()` per approved row and zero extra queries
     * (see the eager load below).
     *
     * The restricted eager load is mandatory, not an optimisation: the v2 token
     * carries the mandant id of the application's accreditation, and
     * `QrTokenService::mandantIdOf()` reads it from the relation. Without
     * `with()` every single row lazy-loads its accreditation — one extra
     * `select * from accreditations where id = ?` per approved application
     * (measured: 10 approvals ⇒ 10 extra round-trips; 500 badges ⇒ 500). The
     * same call site in `accreditation:backfill-qr-tokens` already loads it.
     *
     * @param  list<int>  $ids
     */
    private function issueQrTokens(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Application::query()
            ->whereIn('id', $ids)
            ->with('accreditation:id,mandant_id')
            ->get()
            ->each(fn (Application $application) => $this->qrTokenService->make($application));
    }

    /**
     * P5: notify every newly approved application of a bulk allocation. The
     * `status = approved` filter mirrors the idempotent status write — only
     * rows that actually changed are mailed, so a repeated allocation run
     * never re-sends.
     *
     * Always called **after** the allocating transaction committed (R-D4 /
     * WP-3-b): the query below therefore reads committed state, and a mail
     * transport failure cannot roll the decision back.
     *
     * @param  list<int>  $ids
     */
    private function dispatchApprovedMails(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Application::query()
            ->whereIn('id', $ids)
            ->where('status', 'approved')
            ->with([
                'user:id,email,name',
                'accreditation.category',
                'accreditation.event',
                'accreditation.team',
                'accreditation.mandant.domains',
            ])
            ->get()
            ->each(function (Application $application): void {
                $this->mandantMailer->send(
                    $application->accreditation->mandant,
                    new ApplicationApprovedMail($application, VerifyLink::for($application)),
                );
            });
    }

    /**
     * P5: notify every newly denied application of a bulk allocation (quota
     * surplus and blacklist denials), same idempotency semantics as
     * `dispatchApprovedMails` — and, like it, strictly after the commit.
     *
     * @param  list<int>  $ids
     */
    private function dispatchDeniedMails(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Application::query()
            ->whereIn('id', $ids)
            ->where('status', 'denied')
            ->with([
                'user:id,email,name',
                'accreditation.category',
                'accreditation.event',
                'accreditation.team',
                'accreditation.mandant.domains',
            ])
            ->get()
            ->each(function (Application $application): void {
                $this->mandantMailer->send(
                    $application->accreditation->mandant,
                    new ApplicationDeniedMail($application, (string) $application->reason),
                );
            });
    }
}

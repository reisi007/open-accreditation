<?php

namespace App\Services;

use App\Mail\SubApplicationApprovedMail;
use App\Mail\SubApplicationDeniedMail;
use App\Models\Mandant;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * P3d allocation engine for sub-accreditations (Park-/Sitzkarten, D9) — the
 * authoritative "who gets a sub-quota slot" decision.
 *
 * The core rules are identical to the P3c main engine and delegated to the
 * shared `AllocationRules` helper: deterministic order (VIP → FCFS → id),
 * quota never exceeded, overbooking → `denied` `Quota erschöpft`, blacklisted
 * users (email/domain, mandant-scoped) never approved (`denied` `Blacklist`
 * in auto mode, kept `requested` in selection mode), idempotent.
 *
 * ## Atomicity (R-D4, review 2026-09-26)
 *
 * Identical to the main engine and for the identical reason: the sub-quota
 * read and the status write must not be interleaved with a competing writer,
 * otherwise the plan is computed against state that is already stale by the
 * time it is written. Every entry point therefore wraps the read **and** the
 * write in one `DB::transaction` under a `lockForUpdate()` row lock on the
 * `sub_accreditations` row that owns the sub-quota. As in the main engine,
 * SQLite drops the lock clause (`SQLiteGrammar::compileLock()` returns `''`),
 * so the suite proves the shape and the rollback, not the mutual exclusion —
 * see `features/accreditation/01-allocation-engine.md`.
 *
 * The D9 main-accreditation dependency is enforced in FOUR places, not one.
 * Here, in this service: the candidate query skips sub-applications whose main
 * application is not `approved`, and `approveSubApplication` answers 422 for
 * one — the cascade below only REACTS to a revoke, it cannot stop a revoke
 * from racing a sub-approval. The three pre-existing ones: at apply time
 * (`SubAccreditationController::apply` — an approved main application is
 * required), on revocation of the main row
 * (`AllocationService::cascadeRevokedSubApplications` — every `approved`
 * sub-row on it is denied), and in the wallet path
 * (`WalletController::ownApprovedSubApplication` — no pass without an
 * approved main application).
 *
 * ## Notification (P6 TODO 5, Nutzerentscheid 2026-10-03)
 *
 * Every status change this engine writes is notified to the applicant, through
 * the same queue as the main engine: `MandantMailerService::send()` only
 * dispatches a `SendMandantMail` job, which owns the idempotency claim, the
 * retry cap and the dead-letter surface (`failed_jobs.mandant_id`). Nothing here
 * dials a relay.
 *
 * **The dispatch is issued INSIDE the allocating transaction**, not after it —
 * deliberately different from `AllocationService`, which dispatches once the
 * commit is behind it. With `config/queue.php` → `after_commit => true` (the
 * `database` connection, i.e. every deployed environment) that makes "the status
 * changed" and "a delivery order exists" ONE commit: a rolled-back allocation
 * leaves no order, a committed one always leaves one. Dispatching after the
 * commit would leave a window — the process can die between the commit and the
 * `jobs` insert, and the notification is then gone with no `failed_jobs` row,
 * no log line and no requeue path. That window is exactly what the Position-45
 * promise ("no mail should be lost") forbids, so the stronger shape wins here.
 *
 * Two consequences are named rather than glossed over:
 *
 *  - Under `QUEUE_CONNECTION=sync` (the PHPUnit suite, the E2E stack) the job
 *    runs INLINE, and `sync` carries no `after_commit` — so there the delivery
 *    happens while the transaction is open and a relay failure propagates out of
 *    the allocation. Production does not run `sync` (`deployment/backend-supervisor.sh`
 *    refuses to start without `QUEUE_CONNECTION=database`); on the suite the
 *    mail is faked.
 *  - The CALL SITE is not something a rollback test can see, and the suite does
 *    not pretend otherwise: MEASURED 2026-10-04, moving both dispatch calls
 *    behind `DB::transaction()` turns exactly ONE test red — the state pin named
 *    below — and leaves every other test of `SubAllocationMailTest` exactly as
 *    it was (23 of 24 unchanged: 22 green, 1 skipped). `after_commit` is the
 *    reason: under the only failure a test can stage, both shapes leave the same
 *    facts. The position is therefore pinned as a STATE
 *    by a queue connection that records the transaction level at push time
 *    (`test_the_notification_is_dispatched_while_the_allocating_transaction_is_still_open`),
 *    together with the counter-direction: a dispatch made from outside records
 *    the baseline. The commit/rollback consequence itself is pinned separately
 *    (`test_a_committed_approval_leaves_a_delivery_order_on_the_queue`,
 *    `test_a_rolled_back_allocation_leaves_no_status_change_and_no_delivery_order`).
 *
 * The notification is derived from the rows the plan actually wrote: the id
 * lists of the plan (`$plan['approve']`, `$plan['deny_quota']`,
 * `$plan['deny_blacklist']`, resp. `[$row->getKey()]` on the two single-row
 * paths) ARE the carrier of the "only what changed" rule, because a repeated
 * run finds no `requested` candidates at all, plans empty lists, and both
 * dispatch methods return before they touch the database.
 *
 * The `status` filter in the dispatch queries does NOT carry that rule — it is
 * a belt. MEASURED 2026-10-04: deleting both filters changed no result in any
 * of the four classes that drive this service — `SubAllocationMailTest`,
 * `SubAccreditationRevocationTest`, `SubAccreditationTest` and
 * `AllocationAtomicityTest` produced the identical pass/fail set before and
 * after — because every call site writes the target status before dispatching,
 * inside the same transaction, so the filter currently excludes nothing. It is
 * kept because it re-reads the CURRENT status from the database instead of
 * trusting the id list, so a future call site that hands over ids the plan did
 * not write cannot mail a row that is not in the state the mail claims.
 *
 * ### Which paths notify, and which one deliberately does not
 *
 * All five write paths of this service notify: `approveSelection`,
 * `approveAllEligible` (which is also the automatic
 * `runAutoSubAllocations` path), `approveSubApplication` and `denySubApplication`
 * (a denial AND a revoke). `setPriority` changes no status and sends nothing,
 * like the main engine's.
 *
 * The one exclusion is `AllocationService::cascadeRevokedSubApplications()`: the
 * `approved → denied` cascade that fires when the MAIN application is revoked.
 * It lives in the main engine, not in this service, and it deliberately stays
 * silent. The applicant of such a row does still learn about the revocation —
 * the same person receives `ApplicationDeniedMail` for the main application,
 * because a sub row's `user_id` is denormalised from it — but not as a second,
 * Parkkarte-shaped mail. The exclusion is named in BOTH directions: this
 * docblock and the cascade's own in `AllocationService`, which is the method
 * that would have to send it.
 *
 * The silence is pinned as a state, not asserted in a comment:
 * `test_the_revoke_cascade_notifies_only_the_main_application`.
 */
final class SubAllocationService
{
    public function __construct(
        private readonly MandantMailerService $mandantMailer,
    ) {}

    /**
     * The 422 message of a sub-approval whose main application is not
     * `approved`. German on purpose: the string reaches the admin UI verbatim,
     * like every other engine message (`AllocationRules::REASON_QUOTA`,
     * `AllocationService::REASON_PARENT_REVOKED`).
     */
    public const REASON_PARENT_NOT_APPROVED = 'Haupt-Akkreditierung ist nicht freigegeben';

    /**
     * Approve the "first X" eligible requested sub-applications (manual
     * mode). Approves at most `min(limit, quota - approved)` candidates,
     * skipping blacklisted users (they stay `requested`). `limit <= 0` does
     * nothing. Idempotent: a second run finds no `requested` candidates.
     *
     * A `requested` sub-application whose main application is not `approved` is
     * not a candidate at all (D9, see `eligibleRequested()`).
     *
     * Quota read and status write share one transaction under a row lock on
     * the sub-accreditation (R-D4). Every approved row is notified inside that
     * transaction (`dispatchApprovedMails`).
     */
    public function approveSelection(SubAccreditation $sub, int $limit): AllocationResult
    {
        if ($limit <= 0) {
            return AllocationResult::none();
        }

        return DB::transaction(function () use ($sub, $limit): AllocationResult {
            // Re-read under the row lock: the caller's instance may predate a
            // quota change or a competing run.
            $locked = $this->lockedSubAccreditation($sub->getKey());

            $applications = $this->eligibleRequested($locked);
            $blacklist = AllocationRules::blacklistFor($this->mandantId($locked));

            $remaining = min($limit, $locked->quota - $this->approvedCount($locked));

            if ($remaining <= 0) {
                return AllocationResult::none();
            }

            $plan = AllocationRules::distributeSelection($applications, $remaining, $blacklist);

            AllocationRules::markApproved(SubApplication::class, $plan['approve']);

            // Inside the transaction on purpose — see the class docblock.
            $this->dispatchApprovedMails($plan['approve']);

            return new AllocationResult(count($plan['approve']), 0, $plan['skipped_blacklist']);
        });
    }

    /**
     * Approve every eligible requested sub-application (auto + manual "alle
     * freigeben") until the quota is reached. Surplus requested
     * sub-applications become `denied` with reason `Quota erschöpft`;
     * blacklist matches become `denied` with reason `Blacklist`. Idempotent.
     *
     * Both halves of the plan (approve + deny) share one transaction under a
     * row lock, so a partial failure cannot leave surplus sub-applications
     * stuck in `requested` (R-D4, WP-3-b).
     *
     * Sub-applications whose main application is not `approved` are not
     * candidates (D9, `eligibleRequested()`) — they stay `requested` and
     * become candidates again if the admin re-approves the main row, exactly
     * like the rows the revoke cascade deliberately leaves alone.
     *
     * Both halves notify, inside the same transaction (P6).
     */
    public function approveAllEligible(SubAccreditation $sub): AllocationResult
    {
        return DB::transaction(function () use ($sub): AllocationResult {
            $locked = $this->lockedSubAccreditation($sub->getKey());

            $applications = $this->eligibleRequested($locked);
            $blacklist = AllocationRules::blacklistFor($this->mandantId($locked));

            $plan = AllocationRules::distributeAll(
                $applications,
                $locked->quota,
                $this->approvedCount($locked),
                $blacklist,
            );

            AllocationRules::markApproved(SubApplication::class, $plan['approve']);
            AllocationRules::markDenied(SubApplication::class, $plan['deny_quota'], AllocationRules::REASON_QUOTA);
            AllocationRules::markDenied(SubApplication::class, $plan['deny_blacklist'], AllocationRules::REASON_BLACKLIST);

            // Inside the transaction on purpose — see the class docblock. Both
            // halves notify: the surplus and the blacklist matches are exactly
            // the rows an applicant would otherwise never hear about again.
            $this->dispatchApprovedMails($plan['approve']);
            $this->dispatchDeniedMails([
                ...$plan['deny_quota'],
                ...$plan['deny_blacklist'],
            ]);

            return new AllocationResult(
                count($plan['approve']),
                count($plan['deny_quota']) + count($plan['deny_blacklist']),
                count($plan['deny_blacklist']),
            );
        });
    }

    /**
     * Single sub-application approval (P3e admin action) — the authoritative
     * "who gets a sub-quota slot" decision for one row. Same guards as the
     * main engine: blacklisted user (email/domain, mandant-scoped via the
     * main accreditation) → 422, sub-quota exhausted → 422 `Quota erschöpft`,
     * a main application that is not `approved` → 422
     * (`self::REASON_PARENT_NOT_APPROVED`), and only `requested`/`denied`
     * rows may be (re-)approved (422 otherwise). Approving clears the deny
     * reason.
     *
     * The sub-quota check and the status write share one transaction under a
     * row lock on the sub-accreditation (R-D4). The applicant is notified inside
     * that transaction (P6).
     *
     * @throws ValidationException
     */
    public function approveSubApplication(SubApplication $subApplication): SubApplication
    {
        $subApplication->loadMissing([
            'user:id,email',
            'application:id,status',
            'subAccreditation:id,quota,accreditation_id',
            'subAccreditation.accreditation:id,mandant_id',
        ]);

        $subAccreditationId = (int) $subApplication->sub_accreditation_id;

        return DB::transaction(function () use ($subApplication, $subAccreditationId): SubApplication {
            $locked = $this->lockedSubAccreditation($subAccreditationId);

            if (AllocationRules::isBlacklisted(
                $subApplication->user,
                AllocationRules::blacklistFor((int) $locked->accreditation->mandant_id),
            )) {
                throw ValidationException::withMessages([
                    'status' => 'User is blacklisted',
                ]);
            }

            if (! in_array($subApplication->status, ['requested', 'denied'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only requested or denied sub-applications can be approved.',
                ]);
            }

            // D9/R-D5: the sub-slot is only valid on an APPROVED main
            // application. The cascade (`cascadeRevokedSubApplications`) only
            // reacts to a revoke — it cannot stop a revoke from landing between
            // the admin's read of this row and this write, which is why the
            // guard is repeated here instead of being left to the cascade.
            if ($subApplication->application?->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => self::REASON_PARENT_NOT_APPROVED,
                ]);
            }

            if ($this->approvedCount($locked) >= $locked->quota) {
                throw ValidationException::withMessages([
                    'status' => AllocationRules::REASON_QUOTA,
                ]);
            }

            $subApplication->update([
                'status' => 'approved',
                'reason' => null,
            ]);

            // Inside the transaction on purpose — see the class docblock.
            $this->dispatchApprovedMails([$subApplication->getKey()]);

            return $subApplication;
        });
    }

    /**
     * Single sub-application denial (P3e admin action). A non-empty `$reason`
     * is mandatory (422 otherwise). Only `requested` (deny) and `approved`
     * (revoke) rows may be denied (422 otherwise).
     *
     * Shares the row lock of the sub-accreditation with every other writer, so
     * a concurrent bulk run is serialised against this revoke instead of
     * planning against a state that is about to change (R-D4).
     *
     * The applicant is notified inside that transaction, with the reason
     * verbatim — for the revoke case as much as for the fresh denial (P6).
     *
     * @throws ValidationException
     */
    public function denySubApplication(SubApplication $subApplication, string $reason): SubApplication
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when denying a sub-application.',
            ]);
        }

        if (! in_array($subApplication->status, ['requested', 'approved'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only requested or approved sub-applications can be denied.',
            ]);
        }

        $subAccreditationId = (int) $subApplication->sub_accreditation_id;

        return DB::transaction(function () use ($subApplication, $reason, $subAccreditationId): SubApplication {
            $this->lockedSubAccreditation($subAccreditationId);

            $subApplication->update([
                'status' => 'denied',
                'reason' => $reason,
            ]);

            // Inside the transaction on purpose — see the class docblock. This
            // path is also the revoke of an `approved` row, which the applicant
            // has to learn about just as much as a fresh denial.
            $this->dispatchDeniedMails([$subApplication->getKey()]);

            return $subApplication;
        });
    }

    /**
     * Set (or clear) the VIP priority of one sub-application (P3e admin
     * action). A direct field update — no status change, no guards.
     */
    public function setPriority(SubApplication $subApplication, bool $priority): SubApplication
    {
        $subApplication->update(['priority' => $priority]);

        return $subApplication;
    }

    /**
     * Automatic trigger: process every active sub-accreditation with
     * `auto_approve = true` whose `deadline_end` (end of day, 23:59:59) has
     * passed. Returns `[sub_accreditation_id => ['approved' => n, 'denied' => m]]`
     * for the processed sub-accreditations only.
     *
     * Notifies through `approveAllEligible()`, so the automatic run is not a
     * silent variant of the manual one (P6).
     *
     * @return array<int, array{approved: int, denied: int}>
     */
    public function runAutoSubAllocations(?DateTimeInterface $now = null): array
    {
        $now = $now ?? now();

        $results = [];

        foreach ($this->autoEligibleSubAccreditations() as $sub) {
            if (! AllocationRules::hasDeadlinePassed($sub->deadline_end, $now)) {
                continue;
            }

            $result = $this->approveAllEligible($sub);

            $results[$sub->id] = [
                'approved' => $result->approved,
                'denied' => $result->denied,
            ];
        }

        return $results;
    }

    /**
     * Active, auto-approve sub-accreditations that carry a deadline.
     */
    private function autoEligibleSubAccreditations(): Collection
    {
        return SubAccreditation::query()
            ->active()
            ->where('auto_approve', true)
            ->whereNotNull('deadline_end')
            ->orderBy('id')
            ->get();
    }

    /**
     * All `requested` sub-applications of one sub-accreditation in allocation
     * order (VIP first, then FCFS, then id), eager-loaded with their user.
     *
     * **D9 candidate filter.** A sub-application is only ever valid on an
     * `approved` main application (`SubAccreditationController::apply`), so a
     * `requested` row whose parent is `requested`/`denied` is NOT a candidate
     * here. Without this filter the two bulk paths would happily hand out a
     * Park-/Sitzkarte slot on top of a revoked main accreditation — the exact
     * state the revoke cascade and the wallet guard both exist to prevent
     * (R-D5). Such rows keep `requested` on purpose: the admin may re-approve
     * the main application first, and the next run then picks them up.
     */
    private function eligibleRequested(SubAccreditation $sub): Collection
    {
        return AllocationRules::orderEligible(
            SubApplication::query()
                ->where('sub_accreditation_id', $sub->id)
                ->where('status', 'requested')
                ->whereHas('application', fn (Builder $query) => $query->where('status', 'approved'))
                ->with('user:id,email'),
        )->get();
    }

    private function approvedCount(SubAccreditation $sub): int
    {
        return SubApplication::query()
            ->where('sub_accreditation_id', $sub->id)
            ->where('status', 'approved')
            ->count();
    }

    /**
     * Re-read one sub-accreditation under a row lock — the serialisation point
     * of every write in this service (R-D4).
     *
     * `lockForUpdate()` compiles to `SELECT … FOR UPDATE` on Postgres (the
     * production engine) and is dropped silently by `SQLiteGrammar`, so the
     * suite can only observe that the lock is *requested* — never that it is
     * *held*. See `features/accreditation/01-allocation-engine.md`.
     *
     * The returned model is the authoritative sub-quota for this run: the
     * caller's instance may predate a quota change by another admin.
     */
    private function lockedSubAccreditation(int|string $id): SubAccreditation
    {
        return SubAccreditation::query()
            ->lockForUpdate()
            ->findOrFail($id);
    }

    /**
     * The mandant the sub-accreditation belongs to (via its main
     * accreditation) — the scope of the blacklist lookup.
     */
    private function mandantId(SubAccreditation $sub): int
    {
        $sub->loadMissing('accreditation:id,mandant_id');

        return (int) $sub->accreditation->mandant_id;
    }

    /* ---------------------------------------------------------------------
     | Notification
     | ------------------------------------------------------------------- */

    /**
     * The relation graph a sub notification needs, loaded once per run.
     *
     * Everything the mailables touch: the applicant, the main application
     * (holder of the `qr_token` the pass barcode encodes), and the main
     * accreditation behind the sub-accreditation (category/event/team and the
     * mandant that owns the sub-quota). All four entry points go through
     * `dispatchApprovedMails()` / `dispatchDeniedMails()`, so this list is what
     * the queued mail is built from;
     * `AbstractSubApplicationMail::prepare()` loads the same graph again on the
     * mailable itself, which is what keeps a hand-built mailable (a test, a
     * future call site) from lazy-loading inside the view.
     *
     * @return list<string>
     */
    private function mailContext(): array
    {
        return [
            'user:id,email,name',
            'application.accreditation.mandant.domains',
            'subAccreditation.accreditation.category',
            'subAccreditation.accreditation.event',
            'subAccreditation.accreditation.team',
            'subAccreditation.accreditation.mandant:id,name',
        ];
    }

    /**
     * P6: notify every sub-application a run just approved.
     *
     * `$ids` is what makes this idempotent — it is the plan's own approve list,
     * and a repeated run plans an empty list and returns above. The
     * `status = approved` filter does not carry that property (MEASURED:
     * deleting it changes no result in any class that drives this service); it
     * is the belt that re-reads the current status instead of trusting the
     * list — see the class docblock.
     *
     * @param  list<int>  $ids
     */
    private function dispatchApprovedMails(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        SubApplication::query()
            ->whereIn('id', $ids)
            ->where('status', 'approved')
            ->with($this->mailContext())
            ->get()
            ->each(function (SubApplication $subApplication): void {
                $mandant = $this->mandantFor($subApplication, 'approved');

                if ($mandant === null) {
                    return;
                }

                $this->mandantMailer->send(
                    $mandant,
                    new SubApplicationApprovedMail($subApplication),
                );
            });
    }

    /**
     * P6: notify every sub-application a run just denied (quota surplus,
     * blacklist, an admin decision or a revoke). The reason is printed verbatim
     * and is never empty on this path: `denySubApplication()` rejects an empty
     * one, the bulk plan writes `REASON_QUOTA` / `REASON_BLACKLIST`.
     *
     * The same split as the approval dispatch: the id list is the carrier of
     * "only what changed", the `status = denied` filter is the belt.
     *
     * @param  list<int>  $ids
     */
    private function dispatchDeniedMails(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        SubApplication::query()
            ->whereIn('id', $ids)
            ->where('status', 'denied')
            ->with($this->mailContext())
            ->get()
            ->each(function (SubApplication $subApplication): void {
                $mandant = $this->mandantFor($subApplication, 'denied');

                if ($mandant === null) {
                    return;
                }

                $this->mandantMailer->send(
                    $mandant,
                    new SubApplicationDeniedMail($subApplication, (string) $subApplication->reason),
                );
            });
    }

    /**
     * The mandant whose SMTP relay delivers this sub-application's
     * notification, or null when the sub-accreditation (or its accreditation, or
     * its mandant) is gone.
     *
     * **Which relation, and why it is the sub one.** The row names TWO
     * accreditations: `sub_accreditation_id` (the thing whose quota was just
     * spent) and `application_id` (the main application the pass hangs on).
     * They coincide for every row the product writes — `apply` derives both
     * from one accreditation — so no test could tell the two apart, and
     * swapping them leaves the whole suite green. The sub-quota decision
     * belongs to the sub-accreditation: its quota, its blacklist scope
     * (`mandantId()`) and this service's row lock all hang off it, so the
     * mandant that decided is the one that has to deliver. A row whose two
     * ends point at different mandants is out-of-band state (direct DB write,
     * a restored dump, a future call site), and there the sub-accreditation's
     * mandant is still the one that owns the decision being notified. Pinned
     * by `test_the_mandant_of_the_sub_accreditation_wins_over_the_one_of_the_main_application`.
     *
     * Mirrors `AllocationService::mandantFor()` down to the reason: the
     * notification is dispatched INSIDE the allocating transaction here, so a
     * `TypeError` from a null mandant would not just abort one mail — it would
     * roll the whole allocation back, and in `runAutoSubAllocations()` it would
     * starve every sub-accreditation after this one in the loop.
     *
     * The referential integrity makes this nearly unreachable (a sub row cascades
     * with its sub-accreditation, that with its accreditation, that with its
     * mandant), which is exactly why it needs a guard rather than a repair: a
     * partially migrated database, a restored dump without FKs, or a future
     * non-cascading FK would otherwise turn into a silently aborted nightly run.
     * Skipping one notification is the correct degradation — the decision itself
     * is committed (or, here, still to be committed independently of the mail),
     * and every OTHER sub-accreditation in the run must still be decided and
     * notified.
     */
    private function mandantFor(SubApplication $subApplication, string $status): ?Mandant
    {
        $mandant = $subApplication->subAccreditation?->accreditation?->mandant;

        if ($mandant !== null) {
            return $mandant;
        }

        Log::warning('SubAllocationService: notification skipped — the sub-accreditation of the application no longer exists.', [
            'sub_application_id' => $subApplication->getKey(),
            'sub_accreditation_id' => $subApplication->sub_accreditation_id,
            'status' => $status,
        ]);

        return null;
    }
}

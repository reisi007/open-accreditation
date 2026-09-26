<?php

namespace App\Services;

use App\Models\SubAccreditation;
use App\Models\SubApplication;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 */
final class SubAllocationService
{
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
     * the sub-accreditation (R-D4).
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
     * row lock on the sub-accreditation (R-D4).
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
}

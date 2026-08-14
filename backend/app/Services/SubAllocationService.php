<?php

namespace App\Services;

use App\Models\SubAccreditation;
use App\Models\SubApplication;
use DateTimeInterface;
use Illuminate\Support\Collection;
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
 * The mandatory main-accreditation dependency (D9) is enforced at apply time
 * (a sub-application may only be created on top of an approved main
 * application), not here — a main `approved` row is the precondition that
 * this engine works on.
 */
final class SubAllocationService
{
    /**
     * Approve the "first X" eligible requested sub-applications (manual
     * mode). Approves at most `min(limit, quota - approved)` candidates,
     * skipping blacklisted users (they stay `requested`). `limit <= 0` does
     * nothing. Idempotent: a second run finds no `requested` candidates.
     */
    public function approveSelection(SubAccreditation $sub, int $limit): AllocationResult
    {
        if ($limit <= 0) {
            return AllocationResult::none();
        }

        $applications = $this->eligibleRequested($sub);
        $blacklist = AllocationRules::blacklistFor($this->mandantId($sub));

        $remaining = min($limit, $sub->quota - $this->approvedCount($sub));

        if ($remaining <= 0) {
            return AllocationResult::none();
        }

        $plan = AllocationRules::distributeSelection($applications, $remaining, $blacklist);

        AllocationRules::markApproved(SubApplication::class, $plan['approve']);

        return new AllocationResult(count($plan['approve']), 0, $plan['skipped_blacklist']);
    }

    /**
     * Approve every eligible requested sub-application (auto + manual "alle
     * freigeben") until the quota is reached. Surplus requested
     * sub-applications become `denied` with reason `Quota erschöpft`;
     * blacklist matches become `denied` with reason `Blacklist`. Idempotent.
     */
    public function approveAllEligible(SubAccreditation $sub): AllocationResult
    {
        $applications = $this->eligibleRequested($sub);
        $blacklist = AllocationRules::blacklistFor($this->mandantId($sub));

        $plan = AllocationRules::distributeAll(
            $applications,
            $sub->quota,
            $this->approvedCount($sub),
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
    }

    /**
     * Single sub-application approval (P3e admin action) — the authoritative
     * "who gets a sub-quota slot" decision for one row. Same guards as the
     * main engine: blacklisted user (email/domain, mandant-scoped via the
     * main accreditation) → 422, sub-quota exhausted → 422 `Quota erschöpft`,
     * and only `requested`/`denied` rows may be (re-)approved (422
     * otherwise). Approving clears the deny reason.
     *
     * @throws ValidationException
     */
    public function approveSubApplication(SubApplication $subApplication): SubApplication
    {
        $subApplication->loadMissing([
            'user:id,email',
            'subAccreditation:id,quota,accreditation_id',
            'subAccreditation.accreditation:id,mandant_id',
        ]);

        if (AllocationRules::isBlacklisted(
            $subApplication->user,
            AllocationRules::blacklistFor((int) $subApplication->subAccreditation->accreditation->mandant_id),
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

        if ($this->approvedCount($subApplication->subAccreditation) >= $subApplication->subAccreditation->quota) {
            throw ValidationException::withMessages([
                'status' => AllocationRules::REASON_QUOTA,
            ]);
        }

        $subApplication->update([
            'status' => 'approved',
            'reason' => null,
        ]);

        return $subApplication;
    }

    /**
     * Single sub-application denial (P3e admin action). A non-empty `$reason`
     * is mandatory (422 otherwise). Only `requested` (deny) and `approved`
     * (revoke) rows may be denied (422 otherwise).
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

        $subApplication->update([
            'status' => 'denied',
            'reason' => $reason,
        ]);

        return $subApplication;
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
     */
    private function eligibleRequested(SubAccreditation $sub): Collection
    {
        return AllocationRules::orderEligible(
            SubApplication::query()
                ->where('sub_accreditation_id', $sub->id)
                ->where('status', 'requested')
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
     * The mandant the sub-accreditation belongs to (via its main
     * accreditation) — the scope of the blacklist lookup.
     */
    private function mandantId(SubAccreditation $sub): int
    {
        $sub->loadMissing('accreditation:id,mandant_id');

        return (int) $sub->accreditation->mandant_id;
    }
}

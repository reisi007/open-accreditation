<?php

namespace App\Services;

use App\Models\Accreditation;
use App\Models\Application;
use DateTimeInterface;
use Illuminate\Support\Collection;
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
 */
final class AllocationService
{
    /**
     * Approve the "first X" eligible requested applications (manual mode).
     * Approves at most `min(limit, quota - approved)` candidates, skipping
     * blacklisted users (they stay `requested` — the admin may lift the
     * blacklist later). `limit <= 0` does nothing. Idempotent: a second run
     * finds no `requested` candidates left.
     */
    public function approveSelection(Accreditation $accreditation, int $limit): AllocationResult
    {
        if ($limit <= 0) {
            return AllocationResult::none();
        }

        $applications = $this->eligibleRequested($accreditation);
        $blacklist = AllocationRules::blacklistFor((int) $accreditation->mandant_id);

        $remaining = min($limit, $accreditation->quota - $this->approvedCount($accreditation));

        if ($remaining <= 0) {
            return AllocationResult::none();
        }

        $plan = AllocationRules::distributeSelection($applications, $remaining, $blacklist);

        AllocationRules::markApproved(Application::class, $plan['approve']);

        return new AllocationResult(count($plan['approve']), 0, $plan['skipped_blacklist']);
    }

    /**
     * Approve every eligible requested application (auto + manual "alle
     * freigeben") until the quota is reached. Surplus requested applications
     * become `denied` with reason `Quota erschöpft`; blacklist matches become
     * `denied` with reason `Blacklist`. Idempotent: a second run finds no
     * `requested` candidates.
     */
    public function approveAllEligible(Accreditation $accreditation): AllocationResult
    {
        $applications = $this->eligibleRequested($accreditation);
        $blacklist = AllocationRules::blacklistFor((int) $accreditation->mandant_id);

        $plan = AllocationRules::distributeAll(
            $applications,
            $accreditation->quota,
            $this->approvedCount($accreditation),
            $blacklist,
        );

        AllocationRules::markApproved(Application::class, $plan['approve']);
        AllocationRules::markDenied(Application::class, $plan['deny_quota'], AllocationRules::REASON_QUOTA);
        AllocationRules::markDenied(Application::class, $plan['deny_blacklist'], AllocationRules::REASON_BLACKLIST);

        return new AllocationResult(
            count($plan['approve']),
            count($plan['deny_quota']) + count($plan['deny_blacklist']),
            count($plan['deny_blacklist']),
        );
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
     * @throws ValidationException
     */
    public function approveApplication(Application $application): Application
    {
        $application->loadMissing(['accreditation:id,mandant_id,quota', 'user:id,email']);

        if (AllocationRules::isBlacklisted(
            $application->user,
            AllocationRules::blacklistFor((int) $application->accreditation->mandant_id),
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

        if ($this->approvedCount($application->accreditation) >= $application->accreditation->quota) {
            throw ValidationException::withMessages([
                'status' => AllocationRules::REASON_QUOTA,
            ]);
        }

        $application->update([
            'status' => 'approved',
            'reason' => null,
        ]);

        return $application;
    }

    /**
     * Single-application denial (P3e admin action). A non-empty `$reason` is
     * mandatory (422 otherwise). Only `requested` (deny) and `approved`
     * (revoke) rows may be denied; `denied` and `blacklisted` rows are
     * invalid transitions (422).
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

        $application->update([
            'status' => 'denied',
            'reason' => $reason,
        ]);

        return $application;
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

    private function approvedCount(Accreditation $accreditation): int
    {
        return Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->count();
    }
}

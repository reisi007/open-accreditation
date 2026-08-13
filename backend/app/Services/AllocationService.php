<?php

namespace App\Services;

use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Blacklist;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
 * SQLite :memory: (tests).
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
        $blacklist = $this->blacklistFor($accreditation);

        $remaining = min($limit, $accreditation->quota - $this->approvedCount($accreditation));

        if ($remaining <= 0) {
            return AllocationResult::none();
        }

        $approve = [];
        $skippedBlacklist = 0;

        foreach ($applications as $application) {
            if (count($approve) >= $remaining) {
                break;
            }

            if ($this->isBlacklisted($application->user, $blacklist)) {
                $skippedBlacklist++;

                continue;
            }

            $approve[] = $application->id;
        }

        if ($approve !== []) {
            $this->markApproved($approve);
        }

        return new AllocationResult(count($approve), 0, $skippedBlacklist);
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
        $blacklist = $this->blacklistFor($accreditation);

        $approvedCount = $this->approvedCount($accreditation);

        $approve = [];
        $denyQuota = [];
        $denyBlacklist = [];

        foreach ($applications as $application) {
            if ($this->isBlacklisted($application->user, $blacklist)) {
                $denyBlacklist[] = $application->id;

                continue;
            }

            if ($approvedCount < $accreditation->quota) {
                $approve[] = $application->id;
                $approvedCount++;
            } else {
                $denyQuota[] = $application->id;
            }
        }

        if ($approve !== []) {
            $this->markApproved($approve);
        }

        if ($denyQuota !== []) {
            $this->markDenied($denyQuota, 'Quota erschöpft');
        }

        if ($denyBlacklist !== []) {
            $this->markDenied($denyBlacklist, 'Blacklist');
        }

        return new AllocationResult(
            count($approve),
            count($denyQuota) + count($denyBlacklist),
            count($denyBlacklist),
        );
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
            if (! $this->hasDeadlinePassed($accreditation, $now)) {
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
        return Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'requested')
            ->with('user:id,email')
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function approvedCount(Accreditation $accreditation): int
    {
        return Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->count();
    }

    /**
     * The mandant-scoped blacklist rows that apply to this accreditation.
     */
    private function blacklistFor(Accreditation $accreditation): Collection
    {
        return Blacklist::query()
            ->where('mandant_id', $accreditation->mandant_id)
            ->get();
    }

    /**
     * A user is blacklisted when the mandant's blacklist contains his exact
     * email or the domain of his email address (e. g. `example.com` for
     * `user@example.com`). Matching is case-insensitive.
     */
    private function isBlacklisted(?User $user, Collection $blacklist): bool
    {
        if ($user === null || $user->email === null || trim($user->email) === '') {
            return false;
        }

        $email = Str::lower(trim($user->email));
        $domain = Str::lower(Str::after($email, '@'));

        foreach ($blacklist as $entry) {
            if ($entry->email !== null && Str::lower(trim($entry->email)) === $email) {
                return true;
            }

            if ($domain !== '' && $entry->domain !== null && Str::lower(trim($entry->domain)) === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `now` has reached the end of the accreditation's deadline day.
     * The deadline is the last full second of the day (23:59:59);
     * `endOfDay()` carries microseconds, so both sides are normalized to
     * whole seconds — at exactly 23:59:59 of the deadline day the run fires.
     */
    private function hasDeadlinePassed(Accreditation $accreditation, DateTimeInterface $now): bool
    {
        if ($accreditation->deadline_end === null) {
            return false;
        }

        $deadlineEnd = $accreditation->deadline_end->copy()->endOfDay()->setMicrosecond(0);

        return $now >= $deadlineEnd;
    }

    /**
     * @param  list<int>  $ids
     */
    private function markApproved(array $ids): void
    {
        Application::query()
            ->whereIn('id', $ids)
            ->where('status', 'requested')
            ->update([
                'status' => 'approved',
                'reason' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function markDenied(array $ids, string $reason): void
    {
        Application::query()
            ->whereIn('id', $ids)
            ->where('status', 'requested')
            ->update([
                'status' => 'denied',
                'reason' => $reason,
                'updated_at' => now(),
            ]);
    }
}

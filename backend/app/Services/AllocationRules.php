<?php

namespace App\Services;

use App\Models\Blacklist;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shared allocation rules used by both the main (P3c) and the sub (P3d)
 * allocation engines. The single source of truth for the parts that are
 * identical across engines:
 *
 * - deterministic candidate ordering (VIP → FCFS → id tie-break),
 * - mandant-scoped blacklist lookup + email/domain matching,
 * - deadline-day boundary math (the deadline ends at 23:59:59 of its day),
 * - candidate partitioning into approve / deny-quota / deny-blacklist plans
 *   and the generic status write-back.
 *
 * Pure/stateless by design: the engines keep their model-specific queries
 * (`Application` vs `SubApplication`) and only delegate the rules here.
 */
final class AllocationRules
{
    public const REASON_QUOTA = 'Quota erschöpft';

    public const REASON_BLACKLIST = 'Blacklist';

    /**
     * Apply the canonical allocation ordering (priority DESC, created_at ASC,
     * id ASC) to an eligible-candidates query. Returns the same builder for
     * chaining.
     */
    public static function orderEligible(Builder $query): Builder
    {
        return $query
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * The mandant-scoped blacklist rows that apply to one mandant.
     */
    public static function blacklistFor(int $mandantId): Collection
    {
        return Blacklist::query()
            ->where('mandant_id', $mandantId)
            ->get();
    }

    /**
     * A user is blacklisted when the mandant's blacklist contains his exact
     * email or the domain of his email address (e. g. `example.com` for
     * `user@example.com`). Matching is case-insensitive.
     */
    public static function isBlacklisted(?User $user, Collection $blacklist): bool
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
     * Whether `now` has reached the end of the deadline day. The deadline is
     * the last full second of the day (23:59:59); `endOfDay()` carries
     * microseconds, so both sides are normalized to whole seconds — at exactly
     * 23:59:59 of the deadline day the auto-trigger fires.
     */
    public static function hasDeadlinePassed(?Carbon $deadlineEnd, DateTimeInterface $now): bool
    {
        if ($deadlineEnd === null) {
            return false;
        }

        $deadlineEnd = $deadlineEnd->copy()->endOfDay()->setMicrosecond(0);

        return $now >= $deadlineEnd;
    }

    /**
     * Partition ordered eligible candidates into an approve-all plan:
     * approve until the quota is reached, deny the quota surplus
     * (`Quota erschöpft`) and deny every blacklist match (`Blacklist`).
     * `$approvedCount` is the number of already-approved rows so an existing
     * fill is respected and the quota is never exceeded.
     *
     * @param  Collection<int, object>  $candidates  ordered candidates exposing `->id` and `->user`
     * @return array{approve: list<int>, deny_quota: list<int>, deny_blacklist: list<int>}
     */
    public static function distributeAll(Collection $candidates, int $quota, int $approvedCount, Collection $blacklist): array
    {
        $approve = [];
        $denyQuota = [];
        $denyBlacklist = [];

        foreach ($candidates as $candidate) {
            if (self::isBlacklisted($candidate->user, $blacklist)) {
                $denyBlacklist[] = $candidate->id;

                continue;
            }

            if ($approvedCount < $quota) {
                $approve[] = $candidate->id;
                $approvedCount++;
            } else {
                $denyQuota[] = $candidate->id;
            }
        }

        return ['approve' => $approve, 'deny_quota' => $denyQuota, 'deny_blacklist' => $denyBlacklist];
    }

    /**
     * Partition ordered eligible candidates into a manual "first X" plan:
     * approve at most `$slots` candidates, skipping blacklist matches (they
     * stay `requested` — the admin may lift the blacklist later). Once the
     * slots are filled the iteration stops, so blacklisted candidates behind
     * the filled slots are not counted as skipped. Mirrors the P3c
     * `approveSelection` loop exactly.
     *
     * @param  Collection<int, object>  $candidates  ordered candidates exposing `->id` and `->user`
     * @return array{approve: list<int>, skipped_blacklist: int}
     */
    public static function distributeSelection(Collection $candidates, int $slots, Collection $blacklist): array
    {
        $approve = [];
        $skippedBlacklist = 0;

        foreach ($candidates as $candidate) {
            if (count($approve) >= $slots) {
                break;
            }

            if (self::isBlacklisted($candidate->user, $blacklist)) {
                $skippedBlacklist++;

                continue;
            }

            $approve[] = $candidate->id;
        }

        return ['approve' => $approve, 'skipped_blacklist' => $skippedBlacklist];
    }

    /**
     * Mark the given candidate rows as approved (reason reset). No-op for an
     * empty id list. Only rows still `requested` are touched (idempotency).
     *
     * @param  class-string  $model
     * @param  list<int>  $ids
     */
    public static function markApproved(string $model, array $ids): void
    {
        self::markStatus($model, $ids, 'approved', null);
    }

    /**
     * Mark the given candidate rows as denied with a reason. No-op for an
     * empty id list. Only rows still `requested` are touched (idempotency).
     *
     * @param  class-string  $model
     * @param  list<int>  $ids
     */
    public static function markDenied(string $model, array $ids, string $reason): void
    {
        self::markStatus($model, $ids, 'denied', $reason);
    }

    /**
     * @param  class-string  $model
     * @param  list<int>  $ids
     */
    private static function markStatus(string $model, array $ids, string $status, ?string $reason): void
    {
        if ($ids === []) {
            return;
        }

        $model::query()
            ->whereIn('id', $ids)
            ->where('status', 'requested')
            ->update([
                'status' => $status,
                'reason' => $reason,
                'updated_at' => now(),
            ]);
    }
}

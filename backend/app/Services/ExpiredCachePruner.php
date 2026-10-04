<?php

namespace App\Services;

use App\Models\CacheRow;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes EXPIRED rows from the `database` cache store's table — the
 * counterweight to a store that only ever forgets a row when something reads
 * it.
 *
 * ## The problem this solves
 *
 * `DatabaseStore::many()` removes an expired row lazily, at the moment something
 * reads that key (`vendor/…/Cache/DatabaseStore.php:156-158` →
 * `forgetManyIfExpired()`, `:427-441`). Two writers in this application never
 * read their key again, so for them the row is never collected:
 *
 * - `App\Jobs\SendMandantMail` writes a claim per delivery
 *   (`Cache::add('mail-delivery:{deliveryId}', true, self::CLAIM_TTL_SECONDS)`)
 *   and, for a mail that went out, nothing ever claims that `deliveryId` again;
 * - the **JWT blacklist** writes one row per invalidated `jti`.
 *
 * Both rows become invisible at their expiration on their own — that is what the
 * TTL buys — but they sit in the table forever. Laravel 13.33.0 ships no
 * `cache:prune` command, and `cache:prune-stale-tags` is Redis-only (measured:
 * `php artisan list`), so there is no framework mechanism to collect them.
 *
 * ## The security boundary, stated as a rule
 *
 * **Only rows with `expiration <= now` may ever be deleted.** The same table
 * carries the JWT blacklist, so a deletion of a row that is still readable means
 * a **logged-out token is accepted again** — a revoked session that comes back to
 * life. Three things keep that failure out of reach:
 *
 * 1. The predicate is not written here. It exists exactly once, in
 *    {@see CacheRow::scopeExpiredAt()} — no inline `expiration` string appears in
 *    this class, so there is no second copy to drift.
 * 2. The moment is read from the same clock the store reads on
 *    (`Carbon::now()->getTimestamp()`, `InteractsWithTime::currentTime()`), so
 *    the reaper cannot disagree with a reader about whether a row is expired.
 * 3. `cache_locks` is deliberately NOT touched — see
 *    {@see CacheRow}'s docblock and `DatabaseLock::acquire()`
 *    (`vendor/…/Cache/DatabaseLock.php:70-98`), which already takes over an
 *    expired lock row itself. Reaping it would additionally risk deleting the
 *    mutex of the task that is running right now.
 *
 * Nothing here calls `Cache::flush()`/`cache:clear`, and no code path anywhere
 * in this repository does — a total wipe of this table invalidates every
 * blacklist entry at once, which is the same catastrophe with a coarser
 * predicate.
 *
 * ## The store gate: no guessing
 *
 * The reaper runs only when the **resolved** default store is a
 * `DatabaseStore`. On any other store there is no table to prune — `array`,
 * `file`, `redis`, `null` keep their own expiry semantics — and guessing a table
 * name for a store that does not have one would mean running a `DELETE` against
 * whatever the connection happens to name. That case is a reported no-op, not a
 * failure and not a silent one: it is written to the application log, because a
 * scheduled command's stdout is discarded (see `RunAllocations`' docblock).
 */
final class ExpiredCachePruner
{
    /**
     * Delete every row that is expired at this instant and report what happened.
     *
     * @return array{
     *     pruned: bool,        // false = the default store is not the `database` store
     *     store: string,       // the resolved store name, for the report
     *     table: string|null,  // the table the delete ran against
     *     deleted: int,        // rows actually removed
     *     moment: int          // the UNIX timestamp the predicate was applied to
     * }
     */
    public function prune(): array
    {
        $startedAt = microtime(true);

        // One reading of the clock, used both for the predicate and for the report
        // — a report that named a different second than the delete used would be
        // evidence of nothing.
        $moment = Carbon::now()->getTimestamp();

        $store = Cache::store();
        $storeName = (string) config('cache.default');

        if (! $store->getStore() instanceof DatabaseStore) {
            Log::info('cache:prune-expired skipped', [
                'store' => $storeName,
                'reason' => 'the default cache store is not the `database` store; there is no cache table to prune',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'pruned' => false,
                'store' => $storeName,
                'table' => null,
                'deleted' => 0,
                'moment' => $moment,
            ];
        }

        $table = (new CacheRow)->getTable();

        try {
            // The one and only delete in this application against the `cache`
            // table, and it is reached through the scope rather than through a
            // hand-written `where`.
            $deleted = (int) CacheRow::query()->expiredAt($moment)->delete();
        } catch (Throwable $e) {
            // Rethrow, so the exit code is non-zero and `ScheduledTaskObserver`
            // sees the failure — the scheduler process knows nothing about the
            // reason otherwise. Mirrors `RunAllocations`.
            Log::error('cache:prune-expired failed', [
                'store' => $storeName,
                'table' => $table,
                'moment' => $moment,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        Log::info('cache:prune-expired finished', [
            'store' => $storeName,
            'table' => $table,
            'moment' => $moment,
            'predicate' => CacheRow::EXPIRATION_COLUMN.' '.CacheRow::EXPIRED_OPERATOR.' '.$moment,
            'deleted' => $deleted,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'pruned' => true,
            'store' => $storeName,
            'table' => $table,
            'deleted' => $deleted,
            'moment' => $moment,
        ];
    }
}

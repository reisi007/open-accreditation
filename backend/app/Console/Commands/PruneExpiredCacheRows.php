<?php

namespace App\Console\Commands;

use App\Models\CacheRow;
use App\Services\ExpiredCachePruner;
use Illuminate\Console\Command;

/**
 * `cache:prune-expired` — the daily reaper for the `database` cache store's
 * table, run from the application scheduler (`routes/console.php`).
 *
 * **Nutzerentscheid 2026-10-04:** built rather than left deferred. The
 * table grows one row per delivered mail (the `SendMandantMail` idempotency
 * claim) plus one per invalidated JWT, and nothing collected them: Laravel
 * 13.33.0 has no `cache:prune`, `cache:prune-stale-tags` is Redis-only, and
 * `DatabaseStore::many()` only deletes an expired row when that very key is read
 * again — which never happens for a delivered mail.
 *
 * ## What it may delete, and why that is a security property
 *
 * The same table carries the **JWT blacklist**. Deleting a row that is still
 * readable there means a logged-out token is accepted again, so the command
 * deletes exactly one class of row: `expiration <= now`
 * ({@see CacheRow}), which is the identical condition
 * `DatabaseStore::many()` already applies when it drops an expired row on read
 * (`vendor/…/Cache/DatabaseStore.php:152-154` and `:437`). The predicate lives in
 * exactly one place in the codebase and is not reachable by accident: this
 * command cannot issue an unqualified delete, because it does not write one.
 *
 * There is deliberately **no `--force`, no dry run and no "all rows" mode**: the
 * destructive ceiling of this command is its TTL. A dry run would be honest only
 * if a reaper could do more than the safe thing, and it cannot.
 *
 * ## Observability
 *
 * A scheduled command runs as a separate process whose stdout Laravel throws
 * away, and `schedule:run` exits 0 even when a task fails — both measured in
 * `App\Support\ScheduledTaskObserver`. So the service writes the outcome
 * (rows deleted, the exact predicate it applied) to the application log, which
 * is the only durable record; the observer then records THAT IT RAN. A reaper
 * that silently deleted nothing is indistinguishable from one that never
 * started, which is precisely the failure mode worth designing against.
 *
 * ## Non-`database` stores
 *
 * With any other default store there is no table to prune, so the run is a
 * reported no-op instead of a guess about a table name (`ExpiredCachePruner`).
 */
class PruneExpiredCacheRows extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete EXPIRED rows (expiration <= now) from the database cache store — daily via the scheduler; never touches unexpired rows, which carry the JWT blacklist';

    public function handle(ExpiredCachePruner $pruner): int
    {
        $result = $pruner->prune();

        if (! $result['pruned']) {
            $this->line(sprintf(
                'Skipped: the default cache store is "%s", not "database" — there is no cache table to prune.',
                $result['store'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Deleted %d expired row(s) from `%s` (expiration <= %d).',
            $result['deleted'],
            $result['table'],
            $result['moment'],
        ));

        return self::SUCCESS;
    }
}

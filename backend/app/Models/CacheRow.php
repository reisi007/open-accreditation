<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE row of the `database` cache store's table, and — more importantly — the
 * ONLY place in this application where "expired" is defined for the reaper.
 *
 * ## Why this class exists at all
 *
 * The `cache` table is not one store's private scratch space. It carries, on the
 * same store the production deployment uses (`CACHE_STORE=database`):
 *
 * - the **idempotency claim** of `App\Jobs\SendMandantMail` — one row per
 *   delivered mail, written with `Cache::add(…, self::CLAIM_TTL_SECONDS)`, never
 *   read again — so the row outlives its TTL until the daily reaper reaches it.
 * - the **JWT blacklist** (`php-open-source-saver/jwt-auth`'s `DatabaseCache`
 *   storage, `config('jwt.providers.storage')`) — every logged-out token.
 *
 * The second one is what makes a cache reaper a security operation rather than
 * housekeeping. **A deletion that is not scoped to expired rows resurrects
 * every invalidated token**: an invalidated-but-unexpired JWT would be accepted
 * again, and a delivered mail's claim could be re-claimed. So the predicate is
 * not repeated as an inline string at the call sites — it exists here, once,
 * and the reaper can only reach a row through {@see scopeExpiredAt()}.
 *
 * ## The predicate is Laravel's own, not a second opinion
 *
 * `expiration <= now` is exactly the condition `DatabaseStore` already applies
 * when it deletes a row it observes to be expired:
 *
 * - `vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php:152-154`
 *   partitions with `$cache->expiration > $currentTime`, i.e. a row is EXPIRED
 *   when `expiration <= $currentTime`;
 * - the delete that follows runs the same comparison a second time, defensively
 *   and with the prefixed key set — `:427-441`, `forgetManyIfExpired()`,
 *   `->where('expiration', '<=', $this->getTime())`.
 *
 * The clock is the same one, too: `InteractsWithTime::currentTime()` is
 * `Carbon::now()->getTimestamp()` (`vendor/…/Support/InteractsWithTime.php:61-64`),
 * which is what the reaper passes in. **This is why the boundary is `<=` and not
 * `<`**, and the boundary is a tested second, not a comment: a row whose
 * expiration is exactly the current second is one that `many()` has already
 * declared expired, so the reaper must delete it too. An off-by-one in the other
 * direction (`<`) would leave a row that every read treats as gone — harmless but
 * a permanent leak, one row per second at worst; `>` would delete live rows, which
 * is the failure this class exists to make impossible.
 *
 * No Eloquent behaviour is relied upon beyond `where()`/`delete()` on a query
 * builder: the row is never hydrated, the table has no `id`, and `expiration` is
 * a `bigInteger` column carrying a UNIX timestamp (indexed — the daily reaper is
 * an index range scan, not a table scan; see
 * `database/migrations/0001_01_01_000001_create_cache_table.php:17`).
 */
class CacheRow extends Model
{
    /**
     * The column carrying the UNIX timestamp after which the row is unreadable.
     * Named so that a test or a reviewer can refer to it without repeating the
     * string.
     */
    public const EXPIRATION_COLUMN = 'expiration';

    /**
     * "expired" is `expiration <= now`, matching `DatabaseStore`'s own
     * definition (see the class docblock). The inclusive operator is the whole
     * contract: `<` leaves a row every read already treats as gone, `>` deletes
     * rows that still answer a read — which is how a reaper un-revokes a JWT.
     */
    public const EXPIRED_OPERATOR = '<=';

    /**
     * The table holds no `created_at`/`updated_at`, and it is only ever reached
     * through a query builder, so the model's own conventions are switched off
     * rather than half-honoured.
     */
    public $timestamps = false;

    protected $guarded = [];

    protected $primaryKey = 'key';

    public $incrementing = false;

    /**
     * The table comes from the cache configuration, because that is where the
     * store reads it from (`config/cache.php:42-48`, `DB_CACHE_TABLE`). Reading
     * it here instead of hard-coding `cache` is what keeps the reaper pointed at
     * the table the resolved store actually writes to.
     */
    public function getTable(): string
    {
        return (string) config('cache.stores.database.table', 'cache');
    }

    /**
     * The connection of the same configured store (`DB_CACHE_CONNECTION`).
     *
     * No shipped env file sets it — not `deployment/dev.env`, not
     * `backend/.env.example` (measured: zero hits for either name), so "unset"
     * is the normal production case and the store falls back to the default
     * connection.
     *
     * Returning `null` — not the string `''` — is what makes
     * {@see Model::getConnection()} fall through to that default connection
     * instead of looking for a connection named `''`.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('cache.stores.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Restrict the query to the rows that are EXPIRED at `$moment` — the single
     * authorised way for a caller to select cache rows for deletion.
     *
     * @param  int  $moment  UNIX timestamp; `Carbon::now()->getTimestamp()` in
     *                       production, so the same clock `DatabaseStore` reads
     *                       on (`InteractsWithTime::currentTime()`).
     */
    public function scopeExpiredAt(Builder $query, int $moment): Builder
    {
        return $query->where(self::EXPIRATION_COLUMN, self::EXPIRED_OPERATOR, $moment);
    }
}

<?php

namespace App\Support;

use App\Models\Mandant;
use App\Models\MandantDomain;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the current Mandant (Verband) from the request host and exposes it
 * as the "current tenant" — the Mandant equivalent of the portal's
 * `BrandRegistry`. Unlike the portal, mandants are stored in the database
 * (config only holds TTL/fallback defaults), and there is no theme — only
 * logo/header images and legal texts.
 */
class MandantContext
{
    public const CONTAINER_KEY = 'mandant.context';

    /**
     * P1a-B1: TTL for negative lookups (unknown hosts). Kept short (~60s) so a
     * newly added mandant domain is resolvable within a minute.
     */
    public const NEGATIVE_CACHE_TTL_SECONDS = 60;

    /**
     * Sentinel cached for unknown hosts. `Cache::remember`/`put` cannot store
     * `null`; the string marker makes the "cached miss" unmistakable in any
     * cache backend (mandant ids are always positive integers).
     */
    public const MISSING = 'missing';

    /**
     * Resolve the mandant owning the given host, via `mandant_domains.hostname`.
     *
     * The cache stores the host → mandant ID mapping (cache key:
     * `mandant.domain.{host}`) AND a `MISSING` sentinel for unknown hosts
     * (P1a-B1, short TTL), so repeated lookups within the TTL skip the domain
     * query entirely — both for known and unknown hosts (a host-request flood
     * on a bogus domain must not hammer the domain table). The mandant row
     * itself is always re-read from the database — caching Eloquent models
     * would require class serialization in the cache (see
     * `cache.serializable_classes`) and risks stale rows.
     *
     * Known host, unknown (e.g. inactive) mandant: the host→id mapping IS
     * cached (positive TTL), but `Mandant::active()->find()` re-reads the row
     * on every call, so activation flips are picked up without cache drops.
     */
    public static function resolve(string $host): ?Mandant
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        $cacheKey = 'mandant.domain.'.$host;

        $cached = Cache::get($cacheKey);

        if ($cached === self::MISSING) {
            return null;
        }

        if (is_int($cached) || (is_string($cached) && ctype_digit($cached))) {
            return Mandant::query()->active()->find((int) $cached);
        }

        $mandantId = MandantDomain::query()->where('hostname', $host)->value('mandant_id');

        if ($mandantId === null) {
            Cache::put($cacheKey, self::MISSING, self::NEGATIVE_CACHE_TTL_SECONDS);

            return null;
        }

        Cache::put($cacheKey, (int) $mandantId, (int) config('mandants.cache_ttl', 3600));

        return Mandant::query()->active()->find((int) $mandantId);
    }

    /**
     * The mandant set for the current request/container, if any.
     */
    public static function current(): ?Mandant
    {
        if (! app()->bound(self::CONTAINER_KEY)) {
            return null;
        }

        $value = app(self::CONTAINER_KEY);

        return $value instanceof Mandant ? $value : null;
    }

    /**
     * The id of the current mandant, or null if none is set.
     */
    public static function currentId(): ?int
    {
        return self::current()?->id;
    }

    /**
     * The primary mandant (`is_primary = true`), falling back to the optional
     * `mandants.fallback_mandant` slug when no primary exists. Not cached —
     * it is a rare call and always returns a fresh row.
     */
    public static function default(): ?Mandant
    {
        $primary = Mandant::query()->where('is_primary', true)->active()->first();

        if ($primary !== null) {
            return $primary;
        }

        $fallbackSlug = config('mandants.fallback_mandant');

        if (is_string($fallbackSlug) && $fallbackSlug !== '') {
            return Mandant::query()->where('slug', $fallbackSlug)->active()->first();
        }

        return null;
    }

    /**
     * Set the current mandant in the application container (used by the
     * middleware and by tests/CLI that set the mandant manually).
     */
    public static function set(?Mandant $mandant): void
    {
        if ($mandant === null) {
            app()->offsetUnset(self::CONTAINER_KEY);

            return;
        }

        app()->instance(self::CONTAINER_KEY, $mandant);
    }

    /**
     * Clear the current mandant from the container.
     */
    public static function reset(): void
    {
        self::set(null);
    }

    /**
     * Drop the cached host→mandant mapping (positive AND negative/missing
     * entry — both share the same cache key), e.g. after a domain or mandant
     * update, so the next lookup re-reads the database.
     */
    public static function forgetHost(string $host): void
    {
        Cache::forget('mandant.domain.'.strtolower(trim($host)));
    }
}

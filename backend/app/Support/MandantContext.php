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
     * Resolve the mandant owning the given host, via `mandant_domains.hostname`.
     *
     * The cache only stores the host → mandant ID mapping (cache key:
     * `mandant.domain.{host}`), so repeated lookups within the TTL skip the
     * domain query entirely. The mandant row itself is always re-read from the
     * database — caching Eloquent models would require class serialization in
     * the cache (see `cache.serializable_classes`) and risks stale rows.
     * Unknown hosts are not cached and return null.
     */
    public static function resolve(string $host): ?Mandant
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        $mandantId = Cache::remember(
            'mandant.domain.'.$host,
            (int) config('mandants.cache_ttl', 3600),
            static fn (): ?int => MandantDomain::query()
                ->where('hostname', $host)
                ->value('mandant_id'),
        );

        if ($mandantId === null) {
            return null;
        }

        return Mandant::query()->active()->find($mandantId);
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
     * Drop the cached host→mandant mapping, e.g. after a domain or mandant
     * update, so the next lookup re-reads the database.
     */
    public static function forgetHost(string $host): void
    {
        Cache::forget('mandant.domain.'.strtolower(trim($host)));
    }
}

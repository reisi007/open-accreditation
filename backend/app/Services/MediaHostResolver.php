<?php

namespace App\Services;

use App\Models\Mandant;

/**
 * Central resolution of the host used as the media-layout directory prefix for
 * one mandant (W6).
 *
 * The domain layout keys files by the *first configured* mandant domain
 * (`orderBy('id')`), which is the stable, documented "primary domain"
 * convention: a later-added alias domain must never silently change where a
 * mandant's media already lives. Without any domain the caller falls back to
 * the host-neutral `_tenants/<id>/…` layout (see `MediaPathService`).
 *
 * All media services (mandant brand, badge images, team logos, event-type
 * logos) share this helper so the host assumption is defined exactly once.
 * Querying only, no disk access — path building stays in `MediaPathService`.
 *
 * ## F2 — the app.url fallback and what it may be used for
 *
 * `BadgeRenderService` needs a HOST, not a directory prefix, and falls back to
 * the host of `config('app.url')` when a mandant has no domain (the same
 * convention as `MediaPathService`'s host-neutral layout). That fallback is
 * only correct while the app.url host actually belongs to the mandant that is
 * about to receive the URL: the QR token is TENANT-BOUND (format v2 signs the
 * mandant id) and `VerifyController` requires the token's claim to name the
 * mandant the request host resolved to. A URL on a host that routes to a
 * DIFFERENT mandant therefore 404s on every single scan — the token is fine, the
 * URL around it is not, and no backfill can repair that.
 *
 * `BadgeExportController` therefore refuses a domain-less mandant
 * UNCONDITIONALLY (422) rather than asking whether the app.url host happens to
 * route back to that mandant: an unowned host is only safe until someone routes
 * it, and every badge printed until then dies at that moment. That is why this
 * class exposes no ownership predicate any more — nothing in the render path
 * needs one, and the decision now lives where the 422 is raised.
 */
final class MediaHostResolver
{
    /**
     * The mandant's first (primary) domain hostname, or null when the mandant
     * has no configured domain.
     */
    public function hostFor(Mandant $mandant): ?string
    {
        $host = $mandant->domains()->orderBy('id')->value('hostname');

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The host of `config('app.url')` — the documented fallback for a mandant
     * without a domain, lower-cased, or null when APP_URL carries no host.
     */
    public function fallbackHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(trim($host));
    }
}

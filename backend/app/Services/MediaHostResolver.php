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
}

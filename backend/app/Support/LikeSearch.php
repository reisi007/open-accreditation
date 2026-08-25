<?php

namespace App\Support;

/**
 * Single source of truth for LIKE-search escaping (P3e-B3): consolidates the
 * four former controller-local `escapeLike()` duplicates
 * (AdminApplicationController, BlacklistController, UserController,
 * PortalController) into one shared helper.
 */
final class LikeSearch
{
    /**
     * Escape LIKE wildcards so a search for a literal `%`, `_` or `\` does
     * not act as a pattern. Bind the result with an explicit
     * `escape '\'` clause (portable across Postgres and SQLite — SQLite has
     * no default escape character), e.g.:
     *
     *   whereRaw("LOWER(col) like LOWER(?) escape '\\'", ['%'.LikeSearch::escape($term).'%'])
     *
     * Replacement order matters: the backslash is replaced FIRST so its own
     * escapes are never re-escaped by the subsequent replacements.
     */
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

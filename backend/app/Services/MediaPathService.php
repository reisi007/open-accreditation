<?php

namespace App\Services;

use DomainException;

/**
 * Single source of truth for the public media layout served directly by the
 * web server (Caddy) from the `media` disk:
 *
 *   <MEDIA_ROOT>/<file>                          root fallback (global)
 *   <MEDIA_ROOT>/<domain>/<file>                 mandant override
 *   <MEDIA_ROOT>/<domain>/teams/<slug>/<file>    vereins logos / versus images
 *   <MEDIA_ROOT>/<domain>/event-types/<slug>/<file>
 *   <MEDIA_ROOT>/<domain>/badges/<file>          badge upload mirror
 *
 * Every path returned by this service is relative to the media root, uses `/`
 * as the only separator and never contains a leading slash or a `..` segment.
 * The service is pure (no disk access) so the path contract can be unit-tested
 * in isolation; W6 wires it into the upload/delete services.
 */
class MediaPathService
{
    /**
     * The filesystem disk whose root maps to the media layout.
     */
    public const DISK = 'media';

    /**
     * Directory segment for club/team media below a mandant domain.
     */
    public const TEAMS_SEGMENT = 'teams';

    /**
     * Directory segment for event-type media below a mandant domain.
     */
    public const EVENT_TYPES_SEGMENT = 'event-types';

    /**
     * Directory segment for the public badge image mirror below a mandant
     * domain.
     */
    public const BADGES_SEGMENT = 'badges';

    /**
     * Normalize a request host into the directory name used below the media
     * root: trimmed, lower-cased, port stripped and IDN/Punycode normalized
     * (`münchen.de` -> `xn--mnchen-3ya.de`).
     *
     * `idn_to_ascii` requires ext-intl. When intl is unavailable the fallback
     * only accepts already-ASCII hosts (validated against the same host
     * pattern as the intl path); non-ASCII hosts are rejected because they
     * cannot be normalized safely without ICU.
     *
     * @throws DomainException when the host is empty, non-ASCII without intl or
     *                         does not form a portable directory name
     */
    public function dirForHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            throw new DomainException('Cannot derive a media directory from an empty host.');
        }

        // Strip an optional port suffix (`example.com:8080` -> `example.com`).
        // IPv6 literals (`[::1]:8080`) are unwrapped here and rejected by the
        // hostname validation below: a colon is not a portable path segment.
        if (str_starts_with($host, '[')) {
            $closing = strpos($host, ']');

            if ($closing === false) {
                throw new DomainException(sprintf('Invalid media host "%s".', $host));
            }

            $host = substr($host, 1, $closing - 1);
        } elseif (preg_match('/^(.+):\d+$/', $host, $matches) === 1) {
            $host = $matches[1];
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii) || $ascii === '') {
                throw new DomainException(sprintf('Invalid media host "%s".', $host));
            }

            $host = strtolower($ascii);
        } elseif (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            throw new DomainException(
                sprintf('Cannot normalize non-ASCII media host "%s" without ext-intl.', $host),
            );
        }

        if (! $this->isValidHost($host)) {
            throw new DomainException(sprintf('Invalid media host "%s".', $host));
        }

        return $host;
    }

    /**
     * Root fallback file, shared by every mandant (e.g. `logo.svg`).
     */
    public function rootFile(string $name): string
    {
        return $this->sanitizeFileName($name);
    }

    /**
     * Mandant override file directly below the normalized host directory.
     */
    public function domainFile(string $host, string $name): string
    {
        return $this->dirForHost($host).'/'.$this->sanitizeFileName($name);
    }

    /**
     * Club/team file below the mandant host (`<host>/teams/<slug>/<file>`).
     */
    public function teamFile(string $host, string $teamSlug, string $name): string
    {
        return $this->dirForHost($host)
            .'/'.self::TEAMS_SEGMENT
            .'/'.$this->sanitizeSlug($teamSlug)
            .'/'.$this->sanitizeFileName($name);
    }

    /**
     * Event-type file below the mandant host
     * (`<host>/event-types/<slug>/<file>`).
     */
    public function eventTypeFile(string $host, string $typeSlug, string $name): string
    {
        return $this->dirForHost($host)
            .'/'.self::EVENT_TYPES_SEGMENT
            .'/'.$this->sanitizeSlug($typeSlug)
            .'/'.$this->sanitizeFileName($name);
    }

    /**
     * Public badge mirror file below the mandant host
     * (`<host>/badges/<file>`).
     */
    public function badgeFile(string $host, string $name): string
    {
        return $this->dirForHost($host)
            .'/'.self::BADGES_SEGMENT
            .'/'.$this->sanitizeFileName($name);
    }

    /**
     * Reduce a file name to a portable, path-traversal-safe leaf name:
     * separators, `..` sequences and any character outside `[a-z0-9._-]` are
     * rejected (never silently dropped). The result is lower-cased.
     *
     * @throws DomainException when the name is empty, contains a traversal
     *                         sequence or an unsupported character
     */
    public function sanitizeFileName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('Media file name must not be empty.');
        }

        if (str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '..')
            || str_contains($name, "\0")
        ) {
            throw new DomainException(sprintf('Media file name "%s" contains a path traversal sequence.', $name));
        }

        // Defensive: no separators remain after the guard above, but basename
        // keeps the contract explicit (and future-proof) at the leaf level.
        $base = strtolower(basename($name));

        if ($base === '.' || $base === '..'
            || preg_match('/^[a-z0-9._-]+$/', $base) !== 1
        ) {
            throw new DomainException(sprintf('Media file name "%s" contains unsupported characters.', $name));
        }

        return $base;
    }

    /**
     * Validate a slug used as a directory segment (`teams/<slug>`,
     * `event-types/<slug>`): lower-cased `[a-z0-9_-]`, no leading/trailing
     * hyphen or underscore, no traversal, no dots.
     *
     * @throws DomainException when the slug is empty or invalid
     */
    public function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        if ($slug === ''
            || str_contains($slug, '/')
            || str_contains($slug, '\\')
            || str_contains($slug, '..')
            || str_contains($slug, "\0")
            || preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $slug) !== 1
        ) {
            throw new DomainException(sprintf('Invalid media slug "%s".', $slug));
        }

        return $slug;
    }

    /**
     * DNS-like hostname check applied after port stripping and punycode
     * conversion. Labels are alnum/hyphen (no leading/trailing hyphen), the
     * total length stays within the DNS limit and no empty labels (`..`) or
     * non-ASCII characters are allowed.
     */
    private function isValidHost(string $host): bool
    {
        return preg_match(
            '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/',
            $host,
        ) === 1;
    }
}

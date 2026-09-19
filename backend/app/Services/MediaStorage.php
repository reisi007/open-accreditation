<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin storage adapter for the public media layout (W6/W11).
 *
 * Writes ALWAYS go to the `media` disk in the domain/host-neutral layout. Reads
 * prefer `media` and transparently fall back to the legacy `private` disk, so
 * files that predate the `media:migrate-to-domain-layout` backfill stay
 * readable (both during and after rollout). Deletes touch both disks and are
 * idempotent, which keeps legacy `mandants/{slug}/…` / `badge-images/…`
 * leftovers removable.
 *
 * W11 adds the internal accel delivery used by the public/admin show routes:
 * with a configured `media.accel_prefix` a request is answered by an empty 200
 * carrying `X-Accel-Redirect: <prefix>/<relative-path>` (Caddy then serves the
 * file from MEDIA_ROOT), and a WebP sibling is preferred when the client sends
 * `Accept: image/webp`. Legacy/private and host-neutral (`_tenants/`) files —
 * which Caddy can never reach — keep streaming through PHP. See
 * `features/media-domain-layout.md` and `deployment/caddy-media-api-accel.Caddyfile`.
 */
final class MediaStorage
{
    /**
     * The disk new public media is written to.
     */
    public const PUBLIC_DISK = MediaPathService::DISK;

    /**
     * The disk public media lived on before W6.
     */
    public const LEGACY_DISK = 'private';

    /**
     * Extensions a public image can live under. Used to clean up stale
     * sibling variants when an upload replaces a file with a different
     * extension (W11) — e.g. `logo.png` -> `logo.webp` must not leave
     * `logo.png` behind.
     */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * Positive allowlist of the brand leaf names written directly below a
     * `<domain>/` directory: the mandant logo/header raster images. The SVG
     * root fallbacks are deployment-provided and never DB-managed.
     */
    private const BRAND_LEAF_PATTERN = '/^(?:logo|header)\.(?:png|jpe?g|webp)$/';

    /**
     * Positive allowlist of the image leaf names written below the
     * `teams`/`event-types`/`badges` segments: a sanitised file name with a
     * raster extension (`logo.png`, `<ulid>.webp`, …).
     */
    private const PUBLIC_LEAF_PATTERN = '/^[a-z0-9][a-z0-9._-]*\.(?:png|jpe?g|webp)$/';

    /**
     * Slug pattern for the `<slug>` segment of
     * `<domain>/teams/<slug>/…` / `<domain>/event-types/<slug>/…`
     * (mirrors `MediaPathService::sanitizeSlug`).
     */
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

    public function __construct(private readonly MediaPathService $paths) {}

    /**
     * Store raw bytes at a relative path on the public disk (used by the
     * backfill command to re-materialise a migrated file).
     *
     * The `media` disk is configured with `throw => false`, so a write failure
     * surfaces as a `false` return instead of an exception. A silent `false`
     * MUST never be mistaken for a successful write: the caller would
     * otherwise update the DB path and remove the legacy source for a file
     * that was never written. `put()` therefore fails loudly.
     *
     * @throws RuntimeException when the disk reports a write failure
     */
    public function put(string $path, string $contents): void
    {
        if (Storage::disk(self::PUBLIC_DISK)->put($path, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write media file "%s".', $path));
        }
    }

    /**
     * Persist an uploaded file below `$directory` under the given leaf name and
     * return the stored relative path, or `false` when the disk reported a
     * write failure (`throw => false` on the `media` disk).
     *
     * Callers MUST treat `false` as fatal: delete the previous file and update
     * the stored path only after a truthy return, otherwise they would point
     * the DB at a file that does not exist while destroying the previous one.
     *
     * @return string|false the stored relative path, or `false` on write failure
     */
    public function putFileAs(string $directory, UploadedFile $file, string $name): string|false
    {
        return Storage::disk(self::PUBLIC_DISK)->putFileAs($directory, $file, $name);
    }

    /**
     * Whether the file exists on the public OR the legacy disk.
     */
    public function exists(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return Storage::disk(self::PUBLIC_DISK)->exists($path)
            || Storage::disk(self::LEGACY_DISK)->exists($path);
    }

    /**
     * The disk holding the file. Public wins when present on both (the backfill
     * copies before it removes the legacy source).
     */
    public function diskFor(string $path): string
    {
        return Storage::disk(self::PUBLIC_DISK)->exists($path)
            ? self::PUBLIC_DISK
            : self::LEGACY_DISK;
    }

    /**
     * The file contents from whichever disk holds them.
     */
    public function get(string $path): string
    {
        return (string) Storage::disk($this->diskFor($path))->get($path);
    }

    /**
     * The detected MIME type from whichever disk holds the file.
     */
    public function mimeType(string $path): string
    {
        return (string) Storage::disk($this->diskFor($path))->mimeType($path);
    }

    /**
     * Delete the file on both disks (idempotent).
     */
    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk(self::PUBLIC_DISK)->delete($path);
        Storage::disk(self::LEGACY_DISK)->delete($path);
    }

    /**
     * An inline streamed HTTP response for the stored file.
     *
     * @param  array<string, string>  $headers
     */
    public function response(string $path, ?string $name = null, array $headers = []): StreamedResponse
    {
        return Storage::disk($this->diskFor($path))->response($path, $name, $headers);
    }

    /**
     * Delivery for a public media file (W11): an empty 200 + `X-Accel-Redirect`
     * when the accel mode is configured AND the file is genuinely below
     * MEDIA_ROOT; otherwise the historical `Storage::response` stream.
     *
     * The accel mode is config-gated (`media.accel_prefix`, default empty =
     * off), so a missing Caddy snippet can never produce a bodyless 200.
     * Legacy/private and host-neutral paths always stream — Caddy cannot reach
     * them.
     *
     * When the client accepts `image/webp` and a `.webp` sibling exists on the
     * media disk, the sibling is served instead of the original. No `Vary` is
     * emitted: the canonical URL is the DB path and the API is not a shared
     * content-negotiated cache.
     *
     * @param  string  $accept  the raw `Accept` request header
     * @param  array<string, string>  $headers  stream-branch headers (ignored for accel)
     */
    public function accelResponse(string $path, string $accept = '', ?string $name = null, array $headers = []): Response
    {
        if (! $this->accelEnabled() || ! $this->isAccelEligible($path)) {
            return $this->response($path, $name, $headers);
        }

        $variant = $this->preferredVariant($path, $accept);

        $accelHeaders = [
            'X-Accel-Redirect' => $this->accelPrefix().'/'.$variant,
            'Content-Type' => $this->mimeType($variant),
            'Cache-Control' => $this->cacheControlFor($variant),
        ];

        if ($name !== null && $name !== '') {
            $accelHeaders['Content-Disposition'] = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $name,
            );
        }

        return new IlluminateResponse('', 200, $accelHeaders);
    }

    /**
     * The `.webp` sibling path of an image path (extension swap), or null when
     * the path already is WebP or carries no leaf extension.
     */
    public function webpSiblingPath(string $path): ?string
    {
        if ($path === '' || strtolower(substr($path, -5)) === '.webp') {
            return null;
        }

        $slash = strrpos($path, '/');
        $dot = strrpos($path, '.');

        if ($dot === false || ($slash !== false && $dot < $slash)) {
            return null;
        }

        return substr($path, 0, $dot).'.webp';
    }

    /**
     * Delete every known image extension of the same leaf in the same
     * directory, except the paths in `$keep`. Removes stale siblings after an
     * upload replaces a file with a different extension (W11).
     *
     * @param  list<string>  $keep
     */
    public function deleteAlternateExtensions(string $path, array $keep = []): void
    {
        if ($path === '') {
            return;
        }

        $directory = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);

        if ($filename === '') {
            return;
        }

        $prefix = $directory === '.' ? '' : $directory.'/';

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $candidate = $prefix.$filename.'.'.$extension;

            if (! in_array($candidate, $keep, true)) {
                $this->delete($candidate);
            }
        }
    }

    /**
     * The configured internal accel prefix, normalised to `/…` without a
     * trailing slash; an empty string when the accel mode is off (default).
     */
    private function accelPrefix(): string
    {
        $prefix = trim((string) config('media.accel_prefix', ''));

        return $prefix === '' ? '' : '/'.trim($prefix, '/');
    }

    private function accelEnabled(): bool
    {
        return $this->accelPrefix() !== '';
    }

    /**
     * Whether the path may be handed to Caddy. This is a POSITIVE allowlist of
     * the known public layout forms (W11-F1) — never a denylist:
     *
     * - `<domain>/logo.<ext>` / `<domain>/header.<ext>` (brand leaves)
     * - `<domain>/teams/<slug>/<file>`
     * - `<domain>/event-types/<slug>/<file>`
     * - `<domain>/badges/<file>`
     *
     * The first segment must be a normalisable, canonical host, which rejects
     * the host-neutral `_tenants/` prefix and every legacy `private` layout
     * (`mandants/`, `badge-images/`, `user-media/`) without enumerating them.
     * Control characters (CR/LF/NUL, …), traversal, backslashes and a leading
     * slash are rejected up front. The file must actually exist on the public
     * disk, because Caddy serves from MEDIA_ROOT.
     */
    private function isAccelEligible(string $path): bool
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, '..')
        ) {
            return false;
        }

        // Reject every control character (NUL, CR, LF, TAB, DEL) — a smuggled
        // CR/LF must never reach the accel header value.
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $segments = explode('/', $path);

        if (count($segments) < 2 || ! $this->isHostSegment($segments[0])) {
            return false;
        }

        if (count($segments) === 2) {
            return preg_match(self::BRAND_LEAF_PATTERN, $segments[1]) === 1;
        }

        $kind = $segments[1];

        if ($kind === MediaPathService::BADGES_SEGMENT) {
            $matches = count($segments) === 3
                && preg_match(self::PUBLIC_LEAF_PATTERN, $segments[2]) === 1;
        } elseif (($kind === MediaPathService::TEAMS_SEGMENT || $kind === MediaPathService::EVENT_TYPES_SEGMENT)
            && count($segments) === 4
        ) {
            $matches = preg_match(self::SLUG_PATTERN, $segments[2]) === 1
                && preg_match(self::PUBLIC_LEAF_PATTERN, $segments[3]) === 1;
        } else {
            return false;
        }

        return $matches && Storage::disk(self::PUBLIC_DISK)->exists($path);
    }

    /**
     * Whether the first path segment is a canonical, normalisable host. Routing
     * it through `MediaPathService::dirForHost()` keeps the host contract in one
     * place and rejects `_tenants`, uppercase and non-ASCII/IDN-invalid
     * segments. A dot is required as well, so the reserved single-label legacy
     * roots (`mandants`, `badge-images`, `user-media`) can never masquerade as a
     * `<domain>/` directory; failing closed only means one more PHP stream.
     */
    private function isHostSegment(string $segment): bool
    {
        if (! str_contains($segment, '.')) {
            return false;
        }

        try {
            return $this->paths->dirForHost($segment) === $segment;
        } catch (DomainException) {
            return false;
        }
    }

    private function clientAcceptsWebp(string $accept): bool
    {
        return $accept !== '' && preg_match('~image/webp~i', $accept) === 1;
    }

    /**
     * The path actually handed to Caddy: the WebP sibling when the client
     * accepts it and the sibling exists, otherwise the original.
     */
    private function preferredVariant(string $path, string $accept): string
    {
        if (! $this->clientAcceptsWebp($accept)) {
            return $path;
        }

        $sibling = $this->webpSiblingPath($path);

        if ($sibling === null || ! Storage::disk(self::PUBLIC_DISK)->exists($sibling)) {
            return $path;
        }

        return $sibling;
    }

    /**
     * W7 cache semantics: badge files are ULID-addressed and never overwritten
     * (`immutable`); every fixed-name image (`logo.<ext>`) revalidates after an
     * hour.
     */
    private function cacheControlFor(string $path): string
    {
        if (in_array(MediaPathService::BADGES_SEGMENT, explode('/', $path), true)) {
            return 'public, max-age=31536000, immutable';
        }

        return 'public, max-age=3600, must-revalidate';
    }
}

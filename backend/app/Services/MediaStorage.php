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
 *
 * **`throw => false` (both disks, `config/filesystems.php`):** every write and
 * delete against these disks reports a failure as a `false` RETURN VALUE instead
 * of raising. Two consequences, and the reason nothing in this class trusts a
 * raw adapter return value:
 *
 * 1. A `false` from `put()`/`putFileAs()` is NOT "written" — callers must abort
 *    before they delete the previous file or rewrite a stored path. `put()`
 *    raises on `false` itself; `putFileAs()` hands the decision to the caller.
 *    Neither trusts the adapter alone: `put()` and `putFileAs()` verify their
 *    own post-condition (the file is on the disk afterwards), mirroring `delete()`.
 * 2. A `false` from `delete()` is NOT "deleted" — the file may still be on disk
 *    and keeps being served. `delete()` therefore verifies its own post-
 *    condition and returns `bool`; a caller may only drop the DB reference on
 *    `true` (R-D7). `deleteWithVariants()` builds on it for the "current file +
 *    extension variants" case of every `purge()`/`destroy()`.
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
     * Extensions a public image can live under. Single source of truth for
     * every "which variant of this leaf may exist" question: it drives the
     * stale-sibling cleanup in `deleteAlternateExtensions()` and the legacy
     * brand cleanup in `MandantMediaService`.
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
     * that was never written. `put()` therefore fails loudly — and checks the
     * post-condition as well, so a driver that reports success for a file it
     * did not materialise is caught too (see `putFileAs()`).
     *
     * @throws RuntimeException when the write did not happen
     */
    public function put(string $path, string $contents): void
    {
        if (Storage::disk(self::PUBLIC_DISK)->put($path, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write media file "%s".', $path));
        }

        if (! $this->isWritten($path)) {
            throw new RuntimeException(sprintf('Media file "%s" is missing after the write.', $path));
        }
    }

    /**
     * Persist an uploaded file below `$directory` under the given leaf name and
     * return the stored relative path, or `false` when the write did not happen.
     *
     * Callers MUST treat `false` as fatal: delete the previous file and update
     * the stored path only after a truthy return, otherwise they would point
     * the DB at a file that does not exist while destroying the previous one.
     *
     * Two independent signals decide, and BOTH must say "written" (WP-4-b):
     *
     * 1. the adapter return value — `false` for `UnableToWriteFile` /
     *    `UnableToSetVisibility` under `throw => false`, and
     * 2. the post-condition — `putFileAs()` hands back the relative path it
     *    derived, so the file can be probed on the disk it was just written to.
     *
     * Signal 2 is the write-side twin of the verified `delete()`: the returned
     * path is what the caller is about to persist, and a path that is not there
     * must never reach a column. Without it a driver that reports success for
     * a file it did not materialise would delete the predecessor's file and
     * leave the column pointing at nothing — the data loss write-then-delete
     * exists to prevent, reached through the write side. A false negative is
     * possible only in a TOCTOU window (the file disappears between the write
     * and the probe) and is the safe direction: the upload is refused, nothing
     * is destroyed.
     *
     * @return string|false the stored relative path, or `false` on write failure
     */
    public function putFileAs(string $directory, UploadedFile $file, string $name): string|false
    {
        $stored = Storage::disk(self::PUBLIC_DISK)->putFileAs($directory, $file, $name);

        if ($stored === false || ! $this->isWritten($stored)) {
            return false;
        }

        return $stored;
    }

    /**
     * Whether the file is on the public disk — the write-side post-condition.
     */
    private function isWritten(string $path): bool
    {
        return Storage::disk(self::PUBLIC_DISK)->exists($path);
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
     * Delete the file on both disks and verify the outcome.
     *
     * The delete counts as successful when the path is no longer present on
     * EITHER disk afterwards — the post-condition is checked instead of
     * trusting the adapter, because both disks run with `throw => false`: a
     * failed `unlink` (read-only MEDIA_ROOT, a permissions regression) is
     * reported as a `false` return value, and a `true` from the adapter only
     * means `unlink()` reported no error.
     *
     * Idempotent by contract: a path that is absent on both disks BEFORE the
     * attempt is "nothing to delete" and returns `true`, so a repeated cleanup
     * or a second `destroy()` never turns into a 500. That pre-check is also
     * what distinguishes the two benign-looking cases from a real failure —
     * `false` is returned ONLY when the file was present and is still there
     * afterwards.
     *
     * Callers that drop a DB reference (path column, row) must keep it on
     * `false` and raise instead: a file that could not be removed is still
     * served by Caddy. "Success" is always relative to the disks as this
     * process currently sees them — a completely unmounted volume is
     * indistinguishable from an empty one.
     */
    public function delete(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        // Nothing to delete: idempotent success, no disk write attempted.
        if (! $this->exists($path)) {
            return true;
        }

        Storage::disk(self::PUBLIC_DISK)->delete($path);
        Storage::disk(self::LEGACY_DISK)->delete($path);

        return ! $this->exists($path);
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
     * Reports the aggregate outcome of the individual `delete()` calls, with
     * the same meaning: `true` when no stale variant survived, `false` when at
     * least one is still on disk. Every candidate is attempted even after a
     * failure, so one stuck variant never hides the others. Kept paths are
     * never touched and therefore never make this fail.
     *
     * @param  list<string>  $keep
     */
    public function deleteAlternateExtensions(string $path, array $keep = []): bool
    {
        if ($path === '') {
            return true;
        }

        $directory = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);

        if ($filename === '') {
            return true;
        }

        $prefix = $directory === '.' ? '' : $directory.'/';
        $removed = true;

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $candidate = $prefix.$filename.'.'.$extension;

            if (in_array($candidate, $keep, true)) {
                continue;
            }

            $removed = $this->delete($candidate) && $removed;
        }

        return $removed;
    }

    /**
     * Remove `$path` together with every sibling image-extension variant of the
     * same leaf — the primitive every `purge()`/`destroy()` of the media
     * services needs (original + `.webp` sibling, and any stale extension a
     * previous replacement left behind).
     *
     * **The current file is removed LAST, and a surviving sibling aborts before
     * that happens.** Deleting the current file first is what made the
     * aggregate observable after the damage: a `false` from a stuck variant
     * (a directory sitting on `logo.webp`, where `unlink` can never succeed)
     * was only reported once `logo.png` was already gone, so the caller kept
     * the column/row — pointing at a file that no longer existed — and every
     * retry 500'd on the same stuck sibling. Siblings first means the `false`
     * is discovered while the referenced file is still on the disk: the
     * reference is kept, the image keeps being served, and a retry attempts
     * exactly the same set again.
     *
     * The same reasoning holds the other way round: a `false` for the current
     * file alone still means "it is still there", so the caller's reference
     * must survive. Aggregate semantics as in `deleteAlternateExtensions()`:
     * `true` only when no variant survived, `false` when at least one is still
     * on disk. Every candidate is attempted, so one stuck variant never hides
     * the others.
     */
    public function deleteWithVariants(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        // `$keep` holds the current path, so this pass can only touch the
        // SIBLING variants — the referenced file is off limits here.
        if (! $this->deleteAlternateExtensions($path, [$path])) {
            return false;
        }

        return $this->delete($path);
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
            return preg_match(self::BRAND_LEAF_PATTERN, $segments[1]) === 1
                && Storage::disk(self::PUBLIC_DISK)->exists($path);
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

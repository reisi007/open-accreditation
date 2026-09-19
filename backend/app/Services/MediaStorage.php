<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin storage adapter for the public media layout (W6).
 *
 * Writes ALWAYS go to the `media` disk in the domain/host-neutral layout. Reads
 * prefer `media` and transparently fall back to the legacy `private` disk, so
 * files that predate the `media:migrate-to-domain-layout` backfill stay
 * readable (both during and after rollout). Deletes touch both disks and are
 * idempotent, which keeps legacy `mandants/{slug}/…` / `badge-images/…`
 * leftovers removable.
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
}

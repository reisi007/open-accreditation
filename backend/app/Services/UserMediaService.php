<?php

namespace App\Services;

use App\Enums\MediaType;
use App\Models\User;
use App\Models\UserMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Handles storage lifecycle of user media on the private disk. The service is
 * the only place that touches the private storage layer; controllers only
 * validate requests and enforce ownership.
 *
 * **Write-then-delete:** every write happens BEFORE anything is deleted or
 * created. The `private` disk runs with `throw => false`, so a failed write
 * (disk full, permissions, unmounted volume) returns `false` instead of
 * raising — that `false` aborts the upload with a `RuntimeException` while the
 * applicant's previous portrait/press id is still intact. The predecessor is
 * only removed once the replacement is really on disk; the quota arithmetic
 * runs before both, so a rejected upload changes nothing at all.
 *
 * **Delete contract (R-D7):** a row is only dropped when its file is
 * verifiably gone from the disk; an already absent file counts as success (a
 * harmless re-run never becomes a 500), a file that survives the delete attempt
 * raises and keeps its row.
 */
class UserMediaService
{
    /**
     * Maximum width/height for uploaded images (px). Enforced server-side so
     * oversized scans do not end up on the private disk.
     */
    public const MAX_IMAGE_DIMENSION = 2000;

    /**
     * F5: per-user upload quota on the private disk.
     *
     * Max number of media files per user — portrait + press_id + attachments
     * are counted TOGETHER. Replacing a singular type (portrait/press_id) does
     * not consume quota: the predecessor is swapped out for the new file, so
     * neither the file count nor the stored bytes grow.
     */
    public const MAX_MEDIA_FILES = 10;

    /**
     * F5: max total bytes a single user may store on the private disk
     * (10 MiB). Enforced server-side before persisting, on top of the
     * controller's per-file `max:10240` validation.
     */
    public const MAX_MEDIA_BYTES = 10 * 1024 * 1024;

    /**
     * Store a new media file for a user under
     * `user-media/{mandantSlug}/{userId}/{type}/{uuid}.{ext}`.
     *
     * Singular types (portrait, press_id) replace the previous file of the
     * same type; `attachment` allows multiple files.
     *
     * Order of operations (write-then-delete, see the class docblock):
     *
     * 1. dimension + quota checks — nothing is written, deleted or created yet,
     * 2. write the new file; a `false` from `putFileAs()` aborts here, with the
     *    previous file and its row untouched,
     * 3. swap the predecessor rows in one transaction, so a failing insert
     *    rolls the previous rows back instead of leaving a gap,
     * 4. unlink the predecessor files after the commit — best effort, a
     *    leftover is logged and the new file is the one that is referenced.
     *
     * @throws ValidationException when the image exceeds the dimension limit
     *                             or the user would exceed the per-user quota
     * @throws RuntimeException when the file could not be written
     */
    public function store(User $user, MediaType $type, UploadedFile $file, string $mandantSlug): UserMedia
    {
        $this->assertWithinDimensionLimit($file);
        $this->assertWithinQuota($user, $type, $file);

        $path = Storage::disk('private')->putFileAs(
            sprintf('user-media/%s/%d/%s', $mandantSlug, $user->id, $type->value),
            $file,
            // W11: the on-disk extension derives from the validated MIME type,
            // never from the client-supplied filename (same rule as the public
            // brand media, `ImageUploadRules`). The client extension was
            // previously passed through verbatim.
            Str::uuid()->toString().'.'.ImageUploadRules::extensionFor($file),
        );

        // The `private` disk is configured with `throw => false`: a failed write
        // returns `false` instead of raising. Casting that to a string would
        // persist a row with an EMPTY path — and it used to arrive here only
        // AFTER the previous portrait had already been deleted, so the failed
        // upload destroyed the applicant's existing photo. Abort instead.
        if ($path === false) {
            throw new RuntimeException(sprintf(
                'Could not store the %s upload of user #%d on the private disk.',
                $type->value,
                $user->id,
            ));
        }

        $superseded = $type->isSingular() ? $this->supersededRows($user, $type) : [];

        try {
            $media = DB::transaction(function () use ($user, $type, $file, $path, $superseded): UserMedia {
                foreach ($superseded as $row) {
                    $row->delete();
                }

                return UserMedia::create([
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'path' => $path,
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'original_name' => $file->getClientOriginalName(),
                ]);
            });
        } catch (Throwable $exception) {
            // The insert failed, so nothing references the file we just wrote:
            // remove it again instead of leaving a quota-consuming phantom.
            // The previous file was never unlinked (that happens after the
            // commit), so the user keeps the file they had.
            $this->removeFile($path);

            throw $exception;
        }

        $this->removeSupersededFiles($superseded);

        return $media;
    }

    /**
     * Remove a media file from disk and delete its row.
     *
     * @throws RuntimeException when the file is still on the disk afterwards —
     *                          the row is kept, so a file that could not be
     *                          removed never loses its only reference
     */
    public function destroy(UserMedia $media): void
    {
        if (! $this->removeFile($media->path)) {
            Log::error('Could not remove a user media file; the row was kept.', [
                'path' => $media->path,
                'user_media_id' => $media->id,
            ]);

            throw new RuntimeException(sprintf(
                'Could not remove the media file "%s"; the stored reference was kept.',
                $media->path,
            ));
        }

        $media->delete();
    }

    /**
     * The rows a singular upload supersedes — read before the transaction, so
     * the pre-commit cleanup knows what to unlink afterwards.
     *
     * @return list<UserMedia>
     */
    private function supersededRows(User $user, MediaType $type): array
    {
        return $user->media()
            ->where('user_media.type', $type->value)
            ->get()
            ->all();
    }

    /**
     * Unlink the files of the superseded rows, after the new row was committed.
     *
     * Best effort: the replacement is written and referenced at this point, so
     * aborting would leave the new file unreferenced and the old one still
     * displayed. A leftover is only logged.
     *
     * @param  list<UserMedia>  $superseded
     */
    private function removeSupersededFiles(array $superseded): void
    {
        foreach ($superseded as $row) {
            if (! $this->removeFile($row->path)) {
                Log::warning('A superseded user media file could not be removed; it is unreferenced now.', [
                    'path' => $row->path,
                    'user_id' => $row->user_id,
                    'type' => $row->type,
                ]);
            }
        }
    }

    /**
     * Delete a file from the private disk and verify the outcome.
     *
     * The disk runs with `throw => false`, so a failed `unlink` is reported as
     * a `false` return value instead of an exception — the return value alone
     * is not trusted either, the post-condition is: the file is gone when
     * `exists()` reports it gone. A path that is absent BEFORE the attempt is
     * "nothing to delete" and succeeds, so a repeated `destroy()` never turns
     * into a 500.
     *
     * An empty path (only reachable through a hand-edited row) counts as gone
     * as well — otherwise the disk root itself would look like a file.
     */
    private function removeFile(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        $disk = Storage::disk('private');

        if (! $disk->exists($path)) {
            return true;
        }

        $disk->delete($path);

        return ! $disk->exists($path);
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinDimensionLimit(UploadedFile $file): void
    {
        $dimensions = getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Bilddimensionen konnten nicht ermittelt werden.',
            ]);
        }

        [$width, $height] = $dimensions;

        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'Das Bild darf maximal %d×%d Pixel groß sein.',
                    self::MAX_IMAGE_DIMENSION,
                    self::MAX_IMAGE_DIMENSION,
                ),
            ]);
        }
    }

    /**
     * F5: enforce the per-user file-count and total-byte quota on the private
     * disk. Checks run BEFORE anything is written, persisted or deleted, so a
     * rejected upload leaves the previous state untouched.
     *
     * @throws ValidationException when the upload would exceed the quota
     */
    private function assertWithinQuota(User $user, MediaType $type, UploadedFile $file): void
    {
        $existing = $user->media()->get();

        // A singular upload swaps its predecessor out, so it neither increases
        // the file count nor the stored bytes; attachments and new singular
        // files do.
        $replacementCount = $type->isSingular() && $existing->contains('type', $type->value) ? 1 : 0;
        $replacementBytes = $type->isSingular()
            ? (int) $existing->where('type', $type->value)->sum('size')
            : 0;

        if ($existing->count() - $replacementCount + 1 > self::MAX_MEDIA_FILES) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'Das Upload-Limit von %d Dateien pro Konto ist erreicht.',
                    self::MAX_MEDIA_FILES,
                ),
            ]);
        }

        $totalBytes = (int) $existing->sum('size') - $replacementBytes + (int) $file->getSize();

        if ($totalBytes > self::MAX_MEDIA_BYTES) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'Das Speicherlimit von %d MB pro Konto ist erreicht.',
                    (int) (self::MAX_MEDIA_BYTES / 1024 / 1024),
                ),
            ]);
        }
    }
}

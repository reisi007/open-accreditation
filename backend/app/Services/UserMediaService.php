<?php

namespace App\Services;

use App\Enums\MediaType;
use App\Models\User;
use App\Models\UserMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handles storage lifecycle of user media on the private disk. The service is
 * the only place that touches the private storage layer; controllers only
 * validate requests and enforce ownership.
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
     * not consume quota, because the previous file is deleted first.
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
     * @throws ValidationException when the image exceeds the dimension limit
     *                             or the user would exceed the per-user quota
     */
    public function store(User $user, MediaType $type, UploadedFile $file, string $mandantSlug): UserMedia
    {
        $this->assertWithinDimensionLimit($file);
        $this->assertWithinQuota($user, $type, $file);

        if ($type->isSingular()) {
            $this->replaceSingular($user, $type);
        }

        $path = Storage::disk('private')->putFileAs(
            sprintf('user-media/%s/%d/%s', $mandantSlug, $user->id, $type->value),
            $file,
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
        );

        return UserMedia::create([
            'user_id' => $user->id,
            'type' => $type->value,
            'path' => (string) $path,
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Remove a media file from disk and delete its row.
     */
    public function destroy(UserMedia $media): void
    {
        Storage::disk('private')->delete($media->path);
        $media->delete();
    }

    /**
     * Singular types keep exactly one file per user+type: delete old rows and
     * their files before persisting the replacement.
     */
    private function replaceSingular(User $user, MediaType $type): void
    {
        $existing = $user->media()->where('user_media.type', $type->value)->get();

        foreach ($existing as $media) {
            Storage::disk('private')->delete($media->path);
            $media->delete();
        }
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
     * disk. Checks run BEFORE anything is persisted/deleted, so a rejected
     * upload leaves the previous state untouched.
     *
     * @throws ValidationException when the upload would exceed the quota
     */
    private function assertWithinQuota(User $user, MediaType $type, UploadedFile $file): void
    {
        $existing = $user->media()->get();

        // A singular replacement deletes its predecessor files first, so it
        // neither increases the file count nor the stored bytes; attachments
        // and new singular files do.
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

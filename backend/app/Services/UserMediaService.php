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
     * Store a new media file for a user under
     * `user-media/{mandantSlug}/{userId}/{type}/{uuid}.{ext}`.
     *
     * Singular types (portrait, press_id) replace the previous file of the
     * same type; `attachment` allows multiple files.
     *
     * @throws ValidationException when the image exceeds the dimension limit
     */
    public function store(User $user, MediaType $type, UploadedFile $file, string $mandantSlug): UserMedia
    {
        $this->assertWithinDimensionLimit($file);

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
}

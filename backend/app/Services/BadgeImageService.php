<?php

namespace App\Services;

use App\Models\BadgeImage;
use App\Models\Mandant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handles the storage lifecycle of a mandant's uploaded badge images on the
 * private disk (path pattern `badge-images/{slug}/{uniq}.{ext}` —
 * features/badge-template-editor.md, "Upload-Infrastruktur"). The service is
 * the only place that touches the private storage layer for these files —
 * delivery stays auth-gated through the admin API, mirroring the
 * `MandantMediaService` pattern. Upload validation (MIME whitelist, size and
 * dimension limits) matches the self-service media exactly; the file
 * extension is derived from the validated MIME type, never from the
 * client-supplied filename.
 */
class BadgeImageService
{
    /**
     * Maximum width/height for uploaded images (px). Same limit as the
     * mandant brand media — single source of truth in `MandantMediaService`.
     */
    public const MAX_IMAGE_DIMENSION = MandantMediaService::MAX_IMAGE_DIMENSION;

    /**
     * Store an uploaded badge image of a mandant: validate the pixel
     * dimensions, persist the file under a server-generated unique name and
     * create the addressing row.
     *
     * @throws ValidationException when the image exceeds the dimension limit
     */
    public function store(Mandant $mandant, UploadedFile $file): BadgeImage
    {
        $this->assertWithinDimensionLimit($file);

        $path = Storage::disk('private')->putFileAs(
            'badge-images/'.$mandant->slug,
            $file,
            ((string) Str::ulid()).'.'.$this->extensionFor($file),
        );

        return BadgeImage::create([
            'mandant_id' => $mandant->id,
            'path' => (string) $path,
            'mime' => (string) $file->getMimeType(),
            'original_name' => (string) $file->getClientOriginalName(),
        ]);
    }

    /**
     * Remove the stored file and the row. Template `layout` entries referencing
     * this id are intentionally NOT rewritten — they keep their `image_id` and
     * the renderer falls back to an empty box (documented behavior,
     * features/badge-template-editor.md).
     */
    public function destroy(BadgeImage $image): void
    {
        Storage::disk('private')->delete($image->path);

        $image->delete();
    }

    /**
     * File extension derived from the validated MIME type, never from the
     * client-supplied filename (which may claim an arbitrary extension). The
     * upload validation restricts MIME types to png/jpeg/webp; for anything
     * unexpected the client extension is kept as a safe fallback.
     */
    private function extensionFor(UploadedFile $file): string
    {
        return match (strtolower((string) $file->getMimeType())) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => strtolower((string) $file->getClientOriginalExtension()),
        };
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

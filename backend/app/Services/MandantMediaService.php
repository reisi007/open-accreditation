<?php

namespace App\Services;

use App\Models\Mandant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Handles the storage lifecycle of a mandant's logo/header images on the
 * private disk (path pattern `mandants/{slug}/logo|header.{ext}`). The service
 * is the only place that touches the private storage layer for these files —
 * delivery stays auth-gated through the admin API, mirroring the user media
 * pattern.
 */
class MandantMediaService
{
    /**
     * Maximum width/height for uploaded images (px). Enforced server-side so
     * oversized images do not end up on the private disk.
     */
    public const MAX_IMAGE_DIMENSION = 2000;

    /**
     * Store (or replace) the logo or header image of a mandant. The previous
     * file is removed before the new one is persisted.
     *
     * @throws ValidationException when the image exceeds the dimension limit
     */
    public function store(Mandant $mandant, string $kind, UploadedFile $file): void
    {
        $this->assertWithinDimensionLimit($file);

        $previous = $this->path($mandant, $kind);
        if ($previous !== null) {
            Storage::disk('private')->delete($previous);
        }

        $path = Storage::disk('private')->putFileAs(
            'mandants/'.$mandant->slug,
            $file,
            $kind.'.'.$this->extensionFor($file),
        );

        $mandant->update([$this->columnFor($kind) => (string) $path]);
    }

    /**
     * Remove the stored file (if any) and reset the path column.
     */
    public function destroy(Mandant $mandant, string $kind): void
    {
        $path = $this->path($mandant, $kind);
        if ($path !== null) {
            Storage::disk('private')->delete($path);
        }

        $mandant->update([$this->columnFor($kind) => null]);
    }

    /**
     * The stored relative path for the given kind, or null when none exists.
     */
    public function path(Mandant $mandant, string $kind): ?string
    {
        return $kind === 'logo' ? $mandant->logo_path : $mandant->header_path;
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

    private function columnFor(string $kind): string
    {
        return $kind === 'logo' ? 'logo_path' : 'header_path';
    }
}

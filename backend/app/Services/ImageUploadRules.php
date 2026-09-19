<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for the upload contract of public brand media
 * (mandant logo/header, badge images, team logos, event-type logos).
 *
 * The HTTP layer additionally validates `image` + `mimes:jpeg,png,webp` +
 * `max:2048` KB; this helper enforces the parts that do not depend on a
 * request (the 2000×2000 px dimension limit) and derives the on-disk extension
 * from the validated MIME type. Anything outside the whitelist is a 422 — the
 * client-supplied filename/extension is never trusted as a fallback (W2-F2 L1).
 */
final class ImageUploadRules
{
    /**
     * Maximum width/height for uploaded images (px).
     */
    public const MAX_DIMENSION = 2000;

    /**
     * MIME whitelist mapped to the canonical on-disk extension.
     *
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * The on-disk extension derived from the validated MIME type, never from
     * the client-supplied filename.
     *
     * @throws ValidationException when the MIME type is not whitelisted
     */
    public static function extensionFor(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());

        if (! array_key_exists($mime, self::MIME_EXTENSIONS)) {
            throw ValidationException::withMessages([
                'file' => 'Der Bildtyp wird nicht unterstützt. Erlaubt sind JPEG, PNG und WebP.',
            ]);
        }

        return self::MIME_EXTENSIONS[$mime];
    }

    /**
     * @throws ValidationException when the image exceeds the dimension limit
     */
    public static function assertWithinDimensionLimit(UploadedFile $file): void
    {
        $dimensions = getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Bilddimensionen konnten nicht ermittelt werden.',
            ]);
        }

        [$width, $height] = $dimensions;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'Das Bild darf maximal %d×%d Pixel groß sein.',
                    self::MAX_DIMENSION,
                    self::MAX_DIMENSION,
                ),
            ]);
        }
    }
}

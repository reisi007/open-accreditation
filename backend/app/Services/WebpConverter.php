<?php

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Synchronous (no queue) WebP conversion for public brand/badge media (W11).
 *
 * The original upload stays authoritative and keeps its path/extension; a
 * `.webp` sibling is written next to it (extension swap, `MediaStorage`).
 * Delivery prefers the sibling when the client accepts `image/webp`, otherwise
 * the original. SVG is never converted (and never reaches this service — the
 * upload whitelist is JPEG/PNG/WebP).
 *
 * Presets:
 * - `photo` (quality 82): wide, photographic images (mandant header/hero).
 * - `logo` (quality 90): logos, emblems, badge templates — crisp edges.
 *
 * GD quirks handled here:
 * - PNG/WebP alpha via `imagepalettetotruecolor` + `imagesavealpha` (a palette
 *   PNG would otherwise render its transparent background black).
 * - JPEG EXIF orientation is applied before encoding, so a phone photo does
 *   not come out sideways.
 * - Animated WebP is rejected: flattening it to the first frame would silently
 *   drop the animation (the original file keeps working).
 */
final class WebpConverter
{
    public const PRESET_PHOTO = 'photo';

    public const PRESET_LOGO = 'logo';

    /**
     * @var array<string, int>
     */
    private const QUALITY = [
        self::PRESET_PHOTO => 82,
        self::PRESET_LOGO => 90,
    ];

    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * Convert `$sourceAbsolutePath` and write the `.webp` sibling of
     * `$originalPath`. Returns the sibling path, or null when the original is
     * already WebP (no sibling needed).
     *
     * @throws RuntimeException when the image cannot be converted or written
     */
    public function syncSibling(string $originalPath, string $sourceAbsolutePath, string $preset = self::PRESET_LOGO): ?string
    {
        $sibling = $this->storage->webpSiblingPath($originalPath);

        if ($sibling === null) {
            return null;
        }

        $this->storage->put($sibling, $this->convertFile($sourceAbsolutePath, $preset));

        return $sibling;
    }

    /**
     * WebP bytes for a file on disk.
     *
     * @throws RuntimeException
     */
    public function convertFile(string $absolutePath, string $preset = self::PRESET_LOGO): string
    {
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read image "%s" for WebP conversion.', $absolutePath));
        }

        return $this->convertContents($contents, $preset);
    }

    /**
     * WebP bytes for in-memory image data (backfill command).
     *
     * @throws RuntimeException for unreadable, unsupported or animated input
     */
    public function convertContents(string $contents, string $preset = self::PRESET_LOGO): string
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            throw new RuntimeException('The source is not a readable image.');
        }

        $type = $info[2];

        if ($type === IMAGETYPE_WEBP && $this->isAnimatedWebp($contents)) {
            throw new RuntimeException('Animated WebP is not converted.');
        }

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Unsupported image type for WebP conversion.');
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new RuntimeException('The source image could not be decoded.');
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $this->exifOrientation($contents));
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $encoded = imagewebp($image, null, self::QUALITY[$preset] ?? self::QUALITY[self::PRESET_LOGO]);
        $bytes = (string) ob_get_clean();

        if ($encoded === false || $bytes === '') {
            throw new RuntimeException('The WebP encoding failed.');
        }

        return $bytes;
    }

    /**
     * Whether the binary is an animated WebP (RIFF `VP8X` chunk with the
     * animation flag set).
     */
    public function isAnimatedWebp(string $contents): bool
    {
        if (strlen($contents) < 21
            || substr($contents, 0, 4) !== 'RIFF'
            || substr($contents, 8, 4) !== 'WEBP'
        ) {
            return false;
        }

        if (substr($contents, 12, 4) !== 'VP8X') {
            return false;
        }

        return (ord($contents[20]) & 0x02) === 0x02;
    }

    /**
     * The EXIF orientation of a JPEG, or 1 (normal) when absent/unreadable.
     * `exif_read_data` runs on a memory stream so no temp file is needed.
     */
    private function exifOrientation(string $contents): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return 1;
        }

        fwrite($stream, $contents);
        rewind($stream);

        $exif = @exif_read_data($stream);

        fclose($stream);

        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        if (is_int($orientation)) {
            return $orientation;
        }

        if (is_string($orientation) && ctype_digit($orientation)) {
            return (int) $orientation;
        }

        return 1;
    }

    /**
     * Apply the EXIF orientation matrix (values 2–8; 1 leaves the image as is).
     *
     * `imagerotate` rotates counter-clockwise for positive degrees, so a
     * "rotate 90 CW" orientation (6) uses -90.
     */
    private function applyExifOrientation(GdImage $image, int $orientation): GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return $this->rotate($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                $image = $this->rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 6:
                return $this->rotate($image, -90);
            case 7:
                $image = $this->rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 8:
                return $this->rotate($image, 90);
            default:
                return $image;
        }
    }

    private function rotate(GdImage $image, int $degrees): GdImage
    {
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees, $transparent);

        if ($rotated === false) {
            return $image;
        }

        imagesavealpha($rotated, true);

        return $rotated;
    }
}

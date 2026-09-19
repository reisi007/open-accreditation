<?php

namespace App\Services;

use App\Models\BadgeImage;
use App\Models\Mandant;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Handles the storage lifecycle of a mandant's uploaded badge images on the
 * public `media` disk, in the W1 domain layout (`<host>/badges/{ulid}.<ext>`
 * via `MediaPathService::badgeFile`, host-neutral `_tenants/<id>/badges/…`
 * without a domain) — features/badge-template-editor.md, "Upload-
 * Infrastruktur". Delivery stays gated through the admin API route; Caddy
 * serves the mirror directly once the domain layout applies.
 *
 * The service is the only place that touches the storage layer for these
 * files. Legacy files (`badge-images/{slug}/{ulid}.<ext>` on the `private`
 * disk) stay readable and are removed on delete (both disks are probed), so
 * rows written before W6 keep working until the `media:migrate-to-domain-
 * layout` backfill moves them. Upload validation mirrors the brand media
 * exactly; the extension derives from the validated MIME type.
 */
class BadgeImageService
{
    /**
     * Maximum width/height for uploaded images (px). Same limit as the
     * mandant brand media — single source of truth in `ImageUploadRules`.
     */
    public const MAX_IMAGE_DIMENSION = ImageUploadRules::MAX_DIMENSION;

    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaHostResolver $hosts,
        private readonly MediaStorage $storage,
    ) {}

    /**
     * Store an uploaded badge image of a mandant: validate the pixel
     * dimensions, persist the file under a server-generated unique name and
     * create the addressing row. The ULID name is lower-cased by
     * `MediaPathService::sanitizeFileName`; lower-casing stays injective over
     * the ULID alphabet, so two uploads never collide (W1-F3).
     *
     * A write failure (`putFileAs()` returns `false`) aborts with a
     * `RuntimeException` before the addressing row is created, so no row can
     * ever point at a file that was never written.
     *
     * @throws ValidationException
     * @throws RuntimeException when the file could not be written
     */
    public function store(Mandant $mandant, UploadedFile $file): BadgeImage
    {
        ImageUploadRules::assertWithinDimensionLimit($file);

        $name = (string) Str::ulid().'.'.ImageUploadRules::extensionFor($file);
        $path = $this->targetPath($mandant, $name);

        $stored = $this->storage->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new RuntimeException(sprintf('Could not store the badge image at "%s".', $path));
        }

        return BadgeImage::create([
            'mandant_id' => $mandant->id,
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'original_name' => (string) $file->getClientOriginalName(),
        ]);
    }

    /**
     * Remove the stored file (new layout on `media` or legacy on `private`) and
     * the row. Template `layout` entries referencing this id are intentionally
     * NOT rewritten — they keep their `image_id` and the renderer falls back to
     * an empty box (documented behavior, features/badge-template-editor.md).
     */
    public function destroy(BadgeImage $image): void
    {
        $this->storage->delete($image->path);

        $image->delete();
    }

    /**
     * The relative media path for a new upload: domain layout when the mandant
     * has a (valid) domain, host-neutral otherwise.
     */
    private function targetPath(Mandant $mandant, string $name): string
    {
        $host = $this->hosts->hostFor($mandant);

        if ($host !== null) {
            try {
                return $this->paths->badgeFile($host, $name);
            } catch (DomainException) {
                // A legacy/invalid hostname in the database must not turn an
                // upload into a 500 — fall back to the host-neutral layout.
            }
        }

        return $this->paths->hostNeutralBadgeFile($mandant->id, $name);
    }
}

<?php

namespace App\Services;

use App\Models\Mandant;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Handles the storage lifecycle of a mandant's logo/header images on the
 * public `media` disk, in the W1 domain layout (`<host>/logo|header.<ext>` via
 * `MediaPathService::domainFile`, host-neutral `_tenants/<id>/…` without a
 * domain). The service is the only place that touches the storage layer for
 * these files; delivery stays gated through the (admin/portal) API routes.
 *
 * Legacy files written before W6 (`mandants/{slug}/…` on the `private` disk)
 * stay readable and are cleaned up on replace/delete (both disks are probed),
 * so un-migrated data survives until the `media:migrate-to-domain-layout`
 * backfill moves it.
 */
class MandantMediaService
{
    /**
     * Maximum width/height for uploaded images (px). Single source of truth in
     * `ImageUploadRules`; kept as a public alias for the brand services.
     */
    public const MAX_IMAGE_DIMENSION = ImageUploadRules::MAX_DIMENSION;

    /**
     * Known legacy extensions — a previous replacement may have left a file
     * with a different extension behind, so all variants are cleaned up.
     */
    private const LEGACY_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaHostResolver $hosts,
        private readonly MediaStorage $storage,
    ) {}

    /**
     * Store (or replace) the logo or header image of a mandant. The new file is
     * persisted first; the previous file (new or legacy layout) is removed only
     * afterwards, so a failed write keeps the old image intact.
     *
     * @throws ValidationException
     */
    public function store(Mandant $mandant, string $kind, UploadedFile $file): void
    {
        ImageUploadRules::assertWithinDimensionLimit($file);

        $name = $kind.'.'.ImageUploadRules::extensionFor($file);
        $path = $this->targetPath($mandant, $name);

        $previous = $this->path($mandant, $kind);

        $this->storage->putFileAs(dirname($path), $file, basename($path));

        if ($previous !== null && $previous !== $path) {
            $this->storage->delete($previous);
        }

        $this->deleteLegacy($mandant, $kind);

        $mandant->update([$this->columnFor($kind) => $path]);
    }

    /**
     * Remove the stored file (new and legacy layout) and reset the path column.
     */
    public function destroy(Mandant $mandant, string $kind): void
    {
        $path = $this->path($mandant, $kind);

        if ($path !== null) {
            $this->storage->delete($path);
        }

        $this->deleteLegacy($mandant, $kind);

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
     * The relative media path for a new upload: domain layout when the mandant
     * has a (valid) domain, host-neutral otherwise.
     */
    private function targetPath(Mandant $mandant, string $name): string
    {
        $host = $this->hosts->hostFor($mandant);

        if ($host !== null) {
            try {
                return $this->paths->domainFile($host, $name);
            } catch (DomainException) {
                // A legacy/invalid hostname in the database must not turn an
                // upload into a 500 — fall back to the host-neutral layout.
            }
        }

        return $this->paths->hostNeutralFile($mandant->id, $name);
    }

    /**
     * Delete every known legacy variant of one kind below the old
     * `mandants/{slug}/` prefix on both disks.
     */
    private function deleteLegacy(Mandant $mandant, string $kind): void
    {
        foreach (self::LEGACY_EXTENSIONS as $extension) {
            $this->storage->delete(sprintf('mandants/%s/%s.%s', $mandant->slug, $kind, $extension));
        }
    }

    private function columnFor(string $kind): string
    {
        return $kind === 'logo' ? 'logo_path' : 'header_path';
    }
}

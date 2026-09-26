<?php

namespace App\Services;

use App\Models\Mandant;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
 *
 * **Delete contract (R-D7):** `MediaStorage::delete()` returns `bool`. A file
 * is only dropped together with its DB reference — a failed unlink raises a
 * `RuntimeException` (500) and the column keeps its value. Cleanup of files the
 * NEW upload already supersedes is the one exception: there the new file is
 * written and the column is about to point at it, so a failure is logged and
 * the leftover is left to `media:prune-orphans` (raising would strand the new,
 * unreferenced file and keep serving the old one).
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
     * Aliases the single source of truth in `MediaStorage`.
     */
    private const LEGACY_EXTENSIONS = MediaStorage::IMAGE_EXTENSIONS;

    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaHostResolver $hosts,
        private readonly MediaStorage $storage,
        private readonly WebpConverter $webp,
    ) {}

    /**
     * Store (or replace) the logo or header image of a mandant. The new file is
     * persisted first; the previous file (new or legacy layout) is removed only
     * afterwards, so a failed write keeps the old image intact. A write failure
     * (`putFileAs()` returns `false`) aborts with a `RuntimeException` before
     * anything is deleted or the path column is rewritten, so the stored path
     * can never point at a file that was never written.
     *
     * From the successful write on, a failure to remove a superseded file is
     * logged, not raised — see the class docblock.
     *
     * @throws ValidationException
     * @throws RuntimeException when the new file could not be written
     */
    public function store(Mandant $mandant, string $kind, UploadedFile $file): void
    {
        ImageUploadRules::assertWithinDimensionLimit($file);

        $name = $kind.'.'.ImageUploadRules::extensionFor($file);
        $path = $this->targetPath($mandant, $name);

        $previous = $this->path($mandant, $kind);

        $stored = $this->storage->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new RuntimeException(sprintf('Could not store the %s image at "%s".', $kind, $path));
        }

        // W11: derive the `.webp` sibling next to the original (server-side
        // sync, no queue). A conversion failure is never fatal — the original
        // stays authoritative and delivery falls back to it.
        $sibling = $this->syncWebp($path, $file, $this->presetFor($kind));

        $keep = array_values(array_filter([$path, $sibling], static fn (?string $value): bool => $value !== null));

        $this->removeSuperseded($mandant, $previous, $path, $keep, $kind);

        $mandant->update([$this->columnFor($kind) => $path]);
    }

    /**
     * Remove the stored file (new and legacy layout, plus its `.webp` sibling)
     * and reset the path column.
     *
     * @throws RuntimeException when the file could not be removed — the column
     *                          keeps its value, so a still-served image never
     *                          loses its only reference (R-D7)
     */
    public function destroy(Mandant $mandant, string $kind): void
    {
        $this->purge($mandant, $kind);

        $mandant->update([$this->columnFor($kind) => null]);
    }

    /**
     * Remove the stored file (new and legacy layout, plus its `.webp` sibling)
     * without touching the path column — used when the mandant row itself is
     * deleted and the column write would be pointless.
     *
     * The current file is mandatory and raises on failure (R-D7). Pre-W6
     * legacy variants are no longer delivered once the reference is gone, so a
     * leftover there is only logged.
     *
     * @throws RuntimeException when the current file could not be removed
     */
    public function purge(Mandant $mandant, string $kind): void
    {
        $path = $this->path($mandant, $kind);

        if ($path !== null) {
            $removed = $this->storage->delete($path);
            // W11: the derived WebP sibling must not outlive its original.
            $removed = $this->storage->deleteAlternateExtensions($path) && $removed;

            if (! $removed) {
                throw $this->removalFailed($path, sprintf('mandant#%d %s', $mandant->id, $kind));
            }
        }

        if (! $this->deleteLegacy($mandant, $kind)) {
            $this->logLeftover(
                sprintf('mandants/%s/%s.<ext>', $mandant->slug, $kind),
                sprintf('mandant#%d %s (pre-W6 legacy)', $mandant->id, $kind),
            );
        }
    }

    /**
     * The stored relative path for the given kind, or null when none exists.
     */
    public function path(Mandant $mandant, string $kind): ?string
    {
        return $kind === 'logo' ? $mandant->logo_path : $mandant->header_path;
    }

    /**
     * Write the `.webp` sibling, tolerating a conversion failure (the original
     * is already persisted and stays authoritative).
     */
    private function syncWebp(string $path, UploadedFile $file, string $preset): ?string
    {
        try {
            return $this->webp->syncSibling($path, $file->getRealPath(), $preset);
        } catch (RuntimeException $exception) {
            Log::warning('WebP sibling conversion failed; delivery falls back to the original.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Header images are wide/photographic (q82); the logo is sharp-edged (q90).
     */
    private function presetFor(string $kind): string
    {
        return $kind === 'header' ? WebpConverter::PRESET_PHOTO : WebpConverter::PRESET_LOGO;
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
     *
     * @return bool `false` when at least one variant survived the attempt
     */
    private function deleteLegacy(Mandant $mandant, string $kind): bool
    {
        $removed = true;

        foreach (self::LEGACY_EXTENSIONS as $extension) {
            $removed = $this->storage->delete(sprintf('mandants/%s/%s.%s', $mandant->slug, $kind, $extension)) && $removed;
        }

        return $removed;
    }

    /**
     * Remove the files the freshly written upload supersedes: the previous
     * path (if it is not one of the just-written files), every stale extension
     * variant (`logo.png` -> `logo.jpg`) and the pre-W6 legacy variants.
     *
     * All of it runs AFTER a successful write and BEFORE the path column is
     * rewritten, so a failure here is logged instead of raised: aborting would
     * leave the new file on disk without any reference while the old image
     * stays referenced and keeps being served. Once the column is rewritten the
     * leftovers are unreferenced orphans, which `media:prune-orphans` reaps.
     *
     * @param  list<string>  $keep  the files that were just written
     */
    private function removeSuperseded(Mandant $mandant, ?string $previous, string $path, array $keep, string $kind): void
    {
        if ($previous !== null && ! in_array($previous, $keep, true) && ! $this->storage->delete($previous)) {
            $this->logLeftover($previous, sprintf('mandant#%d %s', $mandant->id, $kind));
        }

        if (! $this->storage->deleteAlternateExtensions($path, $keep)) {
            $this->logLeftover($path, sprintf('mandant#%d %s (stale variant)', $mandant->id, $kind));
        }

        if (! $this->deleteLegacy($mandant, $kind)) {
            $this->logLeftover(sprintf('mandants/%s/%s.<ext>', $mandant->slug, $kind), sprintf('mandant#%d %s (pre-W6 legacy)', $mandant->id, $kind));
        }
    }

    private function logLeftover(string $path, string $context): void
    {
        Log::warning('A superseded media file could not be removed; it is unreferenced now and `media:prune-orphans` reaps it.', [
            'path' => $path,
            'context' => $context,
        ]);
    }

    /**
     * Log and build the exception for a file that must not lose its reference.
     */
    private function removalFailed(string $path, string $context): RuntimeException
    {
        Log::error('Could not remove a media file; the stored reference was kept.', [
            'path' => $path,
            'context' => $context,
        ]);

        return new RuntimeException(sprintf(
            'Could not remove the media file "%s"; the stored reference was kept.',
            $path,
        ));
    }

    private function columnFor(string $kind): string
    {
        return $kind === 'logo' ? 'logo_path' : 'header_path';
    }
}

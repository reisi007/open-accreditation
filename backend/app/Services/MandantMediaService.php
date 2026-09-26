<?php

namespace App\Services;

use App\Exceptions\MediaRemovalFailedException;
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
 * **Delete contract (R-D7):** `MediaStorage::deleteWithVariants()` returns
 * `bool` for the current file and all of its variants, and removes the current
 * file LAST. A file is only dropped together with its DB reference — a failed
 * unlink raises a `RuntimeException` (500) and the column keeps its value,
 * which also means the image keeps being served. Cleanup of files the NEW
 * upload already supersedes is the one exception: there the column already
 * points at the new file, so a failure is logged and the leftover is left
 * behind. `media:prune-orphans` collects such leftovers ONLY in the managed
 * `media` layout; a pre-W6 legacy leftover on the `private` disk has to be
 * deleted by hand, and the log message says so.
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
     * (`putFileAs()` returns `false` — the adapter result AND the post-condition
     * are verified) aborts with a `RuntimeException` before anything is deleted
     * or the path column is rewritten, so the stored path can never point at a
     * file that was never written.
     *
     * The path column is rewritten BEFORE the best-effort cleanup, so a leftover
     * that gets logged is really unreferenced.
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

        $mandant->update([$this->columnFor($kind) => $path]);

        $this->removeSuperseded($mandant, $previous, $path, $keep, $kind);
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
     * The current file and its variants are removed in one verified pass whose
     * CURRENT file goes last (`MediaStorage::deleteWithVariants()`), so a
     * variant that survives the attempt is discovered while the column still
     * resolves: the raise keeps a servable image instead of a dangling
     * reference. Pre-W6 legacy variants are no longer delivered once the
     * reference is gone, so a leftover there is only logged.
     *
     * @throws RuntimeException when a file that is still referenced could not be
     *                          removed
     */
    public function purge(Mandant $mandant, string $kind): void
    {
        $path = $this->path($mandant, $kind);

        if ($path !== null && ! $this->storage->deleteWithVariants($path)) {
            throw $this->removalFailed($path, sprintf('mandant#%d %s', $mandant->id, $kind));
        }

        if (! $this->deleteLegacy($mandant, $kind)) {
            $this->logLeftover(
                sprintf('mandants/%s/%s.<ext>', $mandant->slug, $kind),
                sprintf('mandant#%d %s (pre-W6 legacy)', $mandant->id, $kind),
                reapable: false,
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
     * This runs AFTER the path column was rewritten, so every leftover it logs
     * is genuinely unreferenced — the new file is the one that is referenced, a
     * failure is logged instead of raised (aborting would leave the new file
     * unreferenced while the old image keeps being served) and a retry of the
     * upload is the only thing that could unstick it.
     *
     * The one exception is the pre-W6 legacy variant: it lives on the `private`
     * disk in a layout `media:prune-orphans` does not scan, so its message says
     * "delete it manually" (see `logLeftover()`).
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
            $this->logLeftover(
                sprintf('mandants/%s/%s.<ext>', $mandant->slug, $kind),
                sprintf('mandant#%d %s (pre-W6 legacy)', $mandant->id, $kind),
                reapable: false,
            );
        }
    }

    /**
     * Log a file that is unreferenced but could not be removed.
     *
     * `$reapable` states whether `media:prune-orphans` will actually collect
     * the file. That command enumerates the managed layout on the `media` disk
     * ONLY, so a pre-W6 leftover on the `private` disk (`mandants/{slug}/…`) is
     * outside its view forever — promising automatic convergence there would be
     * a lie an operator acts on, so the message names the manual step instead
     * (same wording as `media:migrate-to-domain-layout`).
     */
    private function logLeftover(string $path, string $context, bool $reapable = true): void
    {
        Log::warning(
            $reapable
                ? 'A superseded media file could not be removed; it is unreferenced now and `media:prune-orphans` reaps it.'
                : 'A superseded media file could not be removed; it is unreferenced now and no automated reaper covers it — delete it manually (`media:prune-orphans` only scans the managed layout on the `media` disk).',
            [
                'path' => $path,
                'context' => $context,
            ],
        );
    }

    /**
     * Log and build the exception for a file that must not lose its reference.
     */
    private function removalFailed(string $path, string $context): MediaRemovalFailedException
    {
        Log::error('Could not remove a media file; the stored reference was kept.', [
            'path' => $path,
            'context' => $context,
        ]);

        return new MediaRemovalFailedException($path, sprintf(
            'Could not remove the media file "%s"; the stored reference was kept.',
            $path,
        ));
    }

    private function columnFor(string $kind): string
    {
        return $kind === 'logo' ? 'logo_path' : 'header_path';
    }
}

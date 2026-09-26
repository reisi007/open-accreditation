<?php

namespace App\Services;

use App\Models\Team;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Storage lifecycle of a team's (Vereins-) logo on the public `media` disk
 * (W6). Extracted from `TeamController` so the controller stays thin and the
 * upload/replace/delete rules live in one place.
 *
 * Layout: `<host>/teams/<slug>/logo.<ext>` via `MediaPathService::teamFile`,
 * host-neutral `_tenants/<id>/teams/<slug>/logo.<ext>` for a mandant without a
 * domain (id keeps same-slug teams of different mandants apart, W2-F2 L3).
 *
 * **Delete contract (R-D7):** `MediaStorage::delete()` returns `bool`. The logo
 * only loses its `logo_path` reference when the file is verifiably gone from
 * both disks; otherwise a `RuntimeException` (500) aborts and the column keeps
 * its value. Cleanup of files the new upload already supersedes is the one
 * exception — there the new file is written and the column is about to point at
 * it, so a failure is logged and the leftover is left to `media:prune-orphans`.
 */
class TeamMediaService
{
    /**
     * Maximum width/height for uploaded logos (px). Single source of truth in
     * `ImageUploadRules`.
     */
    public const MAX_IMAGE_DIMENSION = ImageUploadRules::MAX_DIMENSION;

    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaHostResolver $hosts,
        private readonly MediaStorage $storage,
        private readonly WebpConverter $webp,
    ) {}

    /**
     * Upload/replace the team logo. The new file is persisted first, the
     * previous one removed afterwards. A write failure (`putFileAs()` returns
     * `false`) aborts with a `RuntimeException` before the previous file is
     * deleted or the path column is rewritten.
     *
     * From the successful write on, a failure to remove a superseded file is
     * logged, not raised — see the class docblock.
     *
     * @throws ValidationException
     * @throws RuntimeException when the new file could not be written
     */
    public function store(Team $team, UploadedFile $file): void
    {
        ImageUploadRules::assertWithinDimensionLimit($file);

        $name = 'logo.'.ImageUploadRules::extensionFor($file);
        $path = $this->targetPath($team, $name);

        $previous = $team->logo_path;

        $stored = $this->storage->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new RuntimeException(sprintf('Could not store the team logo at "%s".', $path));
        }

        // W11: derive the `.webp` sibling (server-side sync, no queue). A
        // conversion failure is never fatal — the original stays authoritative.
        $sibling = $this->syncWebp($path, $file);

        $keep = array_values(array_filter([$path, $sibling], static fn (?string $value): bool => $value !== null));

        // W11: drop the previous path and stale extension variants
        // (`logo.png` -> `logo.jpg`) — best effort, see the class docblock.
        if ($previous !== null && ! in_array($previous, $keep, true) && ! $this->storage->delete($previous)) {
            $this->logLeftover($previous);
        }

        if (! $this->storage->deleteAlternateExtensions($path, $keep)) {
            $this->logLeftover($path);
        }

        $team->update(['logo_path' => $path]);
    }

    /**
     * Remove the logo file and reset `logo_path`.
     *
     * @throws RuntimeException when the file could not be removed — the column
     *                          keeps its value (R-D7)
     */
    public function destroy(Team $team): void
    {
        $this->purge($team);

        $team->update(['logo_path' => null]);
    }

    /**
     * Remove the logo file only (no column update) — used when the team row
     * itself is deleted and the column write would be pointless. The derived
     * `.webp` sibling is removed with it (W11).
     *
     * @throws RuntimeException when the file could not be removed
     */
    public function purge(Team $team): void
    {
        if ($team->logo_path === null) {
            return;
        }

        $removed = $this->storage->delete($team->logo_path);
        $removed = $this->storage->deleteAlternateExtensions($team->logo_path) && $removed;

        if (! $removed) {
            throw $this->removalFailed($team);
        }
    }

    /**
     * Keep the logo reachable after a slug change: move the file (and its
     * `.webp` sibling) to the path of the new slug (W2-F2 L2, W11). The path
     * column is rewritten even when the file is already gone, so the stored
     * path never points at the previous slug.
     */
    public function moveForSlugChange(Team $team, string $oldSlug): void
    {
        $previous = $team->logo_path;

        if ($previous === null || $previous === '' || $oldSlug === $team->slug) {
            return;
        }

        $newPath = $this->targetPath($team, basename($previous));

        if ($newPath === $previous) {
            return;
        }

        $this->moveWithSibling($previous, $newPath);

        $team->update(['logo_path' => $newPath]);
    }

    /**
     * Move an image and its derived `.webp` sibling to a new base path.
     */
    private function moveWithSibling(string $from, string $to): void
    {
        $pairs = [
            [$from, $to],
            [$this->storage->webpSiblingPath($from), $this->storage->webpSiblingPath($to)],
        ];

        foreach ($pairs as [$old, $new]) {
            if ($old === null || $new === null || ! $this->storage->exists($old)) {
                continue;
            }

            // `put()` raises on a write failure, so the copy is either complete
            // or the old file is still the only one there. A failed removal of
            // the old path leaves an unreferenced duplicate — the column is
            // rewritten to the new path regardless, so the logo stays
            // reachable, and `media:prune-orphans` reaps the leftover.
            $this->storage->put($new, $this->storage->get($old));

            if (! $this->storage->delete($old)) {
                $this->logLeftover($old);
            }
        }
    }

    private function logLeftover(string $path): void
    {
        Log::warning('A superseded media file could not be removed; it is unreferenced now and `media:prune-orphans` reaps it.', [
            'path' => $path,
        ]);
    }

    /**
     * Log and build the exception for a file that must not lose its reference.
     */
    private function removalFailed(Team $team): RuntimeException
    {
        Log::error('Could not remove the team logo; `logo_path` was kept.', [
            'path' => $team->logo_path,
            'team_id' => $team->id,
        ]);

        return new RuntimeException(sprintf(
            'Could not remove the team logo "%s"; the stored reference was kept.',
            (string) $team->logo_path,
        ));
    }

    /**
     * Write the `.webp` sibling, tolerating a conversion failure (the original
     * is already persisted and stays authoritative).
     */
    private function syncWebp(string $path, UploadedFile $file): ?string
    {
        try {
            return $this->webp->syncSibling($path, $file->getRealPath(), WebpConverter::PRESET_LOGO);
        } catch (RuntimeException $exception) {
            Log::warning('WebP sibling conversion failed; delivery falls back to the original.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The relative media path for a new logo: domain layout when the team's
     * mandant has a (valid) domain, host-neutral otherwise.
     */
    private function targetPath(Team $team, string $name): string
    {
        $mandant = $team->mandant;
        $host = $mandant !== null ? $this->hosts->hostFor($mandant) : null;

        if ($host !== null) {
            try {
                return $this->paths->teamFile($host, $team->slug, $name);
            } catch (DomainException) {
                // A legacy/invalid slug or hostname must not turn an upload
                // into a 500 — fall back to the host-neutral layout.
            }
        }

        return $this->paths->hostNeutralTeamFile($team->mandant_id, $team->slug, $name);
    }
}

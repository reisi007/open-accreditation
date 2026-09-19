<?php

namespace App\Services;

use App\Models\Team;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Storage lifecycle of a team's (Vereins-) logo on the public `media` disk
 * (W6). Extracted from `TeamController` so the controller stays thin and the
 * upload/replace/delete rules live in one place.
 *
 * Layout: `<host>/teams/<slug>/logo.<ext>` via `MediaPathService::teamFile`,
 * host-neutral `_tenants/<id>/teams/<slug>/logo.<ext>` for a mandant without a
 * domain (id keeps same-slug teams of different mandants apart, W2-F2 L3).
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
    ) {}

    /**
     * Upload/replace the team logo. The new file is persisted first, the
     * previous one removed afterwards.
     *
     * @throws ValidationException
     */
    public function store(Team $team, UploadedFile $file): void
    {
        ImageUploadRules::assertWithinDimensionLimit($file);

        $name = 'logo.'.ImageUploadRules::extensionFor($file);
        $path = $this->targetPath($team, $name);

        $previous = $team->logo_path;

        $this->storage->putFileAs(dirname($path), $file, basename($path));

        if ($previous !== null && $previous !== $path) {
            $this->storage->delete($previous);
        }

        $team->update(['logo_path' => $path]);
    }

    /**
     * Remove the logo file and reset `logo_path`.
     */
    public function destroy(Team $team): void
    {
        $this->purge($team);

        $team->update(['logo_path' => null]);
    }

    /**
     * Remove the logo file only (no column update) — used when the team row
     * itself is deleted and the column write would be pointless.
     */
    public function purge(Team $team): void
    {
        if ($team->logo_path !== null) {
            $this->storage->delete($team->logo_path);
        }
    }

    /**
     * Keep the logo reachable after a slug change: move the file to the path of
     * the new slug (W2-F2 L2). The path column is rewritten even when the file
     * is already gone, so the stored path never points at the previous slug.
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

        if ($this->storage->exists($previous)) {
            $this->storage->put($newPath, $this->storage->get($previous));
            $this->storage->delete($previous);
        }

        $team->update(['logo_path' => $newPath]);
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

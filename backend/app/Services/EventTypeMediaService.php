<?php

namespace App\Services;

use App\Models\EventType;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Storage lifecycle of an event type's logo on the public `media` disk (W6).
 * Extracted from `EventTypeController` so the controller stays thin and the
 * upload/replace/delete rules live in one place.
 *
 * Layout: `<host>/event-types/<slug>/logo.<ext>` via
 * `MediaPathService::eventTypeFile`, host-neutral
 * `_tenants/<id>/event-types/<slug>/logo.<ext>` for a mandant without a domain
 * (id keeps same-slug types of different mandants apart, W2-F2 L3).
 *
 * **Delete contract (R-D7):** `MediaStorage::deleteWithVariants()` returns
 * `bool` for the logo and all of its extension variants and removes the current
 * file LAST. The logo only loses its `logo_path` reference when the file is
 * verifiably gone from both disks; otherwise a `RuntimeException` (500) aborts,
 * the column keeps its value and the image keeps being served. Cleanup of files
 * the new upload already supersedes is the one exception — there the column
 * already points at the new file, so a failure is logged and the leftover is
 * left to `media:prune-orphans`, which covers every path an event-type logo
 * can have (they only ever existed in the managed `media` layout).
 */
class EventTypeMediaService
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
     * Upload/replace the event-type logo. The new file is persisted first, the
     * previous one removed afterwards. A write failure (`putFileAs()` returns
     * `false` — the adapter result AND the post-condition are verified) aborts
     * with a `RuntimeException` before the previous file is deleted or the path
     * column is rewritten.
     *
     * The column is rewritten BEFORE the best-effort cleanup, so a leftover that
     * gets logged is really unreferenced — see the class docblock.
     *
     * @throws ValidationException
     * @throws RuntimeException when the new file could not be written
     */
    public function store(EventType $eventType, UploadedFile $file): void
    {
        ImageUploadRules::assertWithinDimensionLimit($file);

        $name = 'logo.'.ImageUploadRules::extensionFor($file);
        $path = $this->targetPath($eventType, $name);

        $previous = $eventType->logo_path;

        $stored = $this->storage->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new RuntimeException(sprintf('Could not store the event-type logo at "%s".', $path));
        }

        // W11: derive the `.webp` sibling (server-side sync, no queue). A
        // conversion failure is never fatal — the original stays authoritative.
        $sibling = $this->syncWebp($path, $file);

        $keep = array_values(array_filter([$path, $sibling], static fn (?string $value): bool => $value !== null));

        $eventType->update(['logo_path' => $path]);

        // W11: drop the previous path and stale extension variants
        // (`logo.png` -> `logo.jpg`) — best effort, see the class docblock.
        if ($previous !== null && ! in_array($previous, $keep, true) && ! $this->storage->delete($previous)) {
            $this->logLeftover($previous);
        }

        if (! $this->storage->deleteAlternateExtensions($path, $keep)) {
            $this->logLeftover($path);
        }
    }

    /**
     * Remove the logo file and reset `logo_path`.
     *
     * @throws RuntimeException when the file could not be removed — the column
     *                          keeps its value (R-D7)
     */
    public function destroy(EventType $eventType): void
    {
        $this->purge($eventType);

        $eventType->update(['logo_path' => null]);
    }

    /**
     * Remove the logo file only (no column update) — used when the event-type
     * row itself is deleted and the column write would be pointless. The derived
     * `.webp` sibling and stale extension variants are removed with it (W11).
     *
     * `MediaStorage::deleteWithVariants()` removes the current file LAST, so a
     * surviving variant is discovered while the column still resolves: the
     * raise keeps a servable logo instead of a dangling reference.
     *
     * @throws RuntimeException when the file could not be removed
     */
    public function purge(EventType $eventType): void
    {
        if ($eventType->logo_path === null) {
            return;
        }

        if (! $this->storage->deleteWithVariants($eventType->logo_path)) {
            throw $this->removalFailed($eventType);
        }
    }

    /**
     * Keep the logo reachable after a slug change: move the file (and its
     * `.webp` sibling) to the path of the new slug (W2-F2 L2, W11). The path
     * column is rewritten even when the file is already gone, so the stored
     * path never points at the previous slug.
     */
    public function moveForSlugChange(EventType $eventType, string $oldSlug): void
    {
        $previous = $eventType->logo_path;

        if ($previous === null || $previous === '' || $oldSlug === $eventType->slug) {
            return;
        }

        $newPath = $this->targetPath($eventType, basename($previous));

        if ($newPath === $previous) {
            return;
        }

        $this->moveWithSibling($previous, $newPath);

        $eventType->update(['logo_path' => $newPath]);
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
    private function removalFailed(EventType $eventType): RuntimeException
    {
        Log::error('Could not remove the event-type logo; `logo_path` was kept.', [
            'path' => $eventType->logo_path,
            'event_type_id' => $eventType->id,
        ]);

        return new RuntimeException(sprintf(
            'Could not remove the event-type logo "%s"; the stored reference was kept.',
            (string) $eventType->logo_path,
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
     * The relative media path for a new logo: domain layout when the event
     * type's mandant has a (valid) domain, host-neutral otherwise.
     */
    private function targetPath(EventType $eventType, string $name): string
    {
        $mandant = $eventType->mandant;
        $host = $mandant !== null ? $this->hosts->hostFor($mandant) : null;

        if ($host !== null) {
            try {
                return $this->paths->eventTypeFile($host, $eventType->slug, $name);
            } catch (DomainException) {
                // A legacy/invalid slug or hostname must not turn an upload
                // into a 500 — fall back to the host-neutral layout.
            }
        }

        return $this->paths->hostNeutralEventTypeFile($eventType->mandant_id, $eventType->slug, $name);
    }
}

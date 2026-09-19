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
     * `false`) aborts with a `RuntimeException` before the previous file is
     * deleted or the path column is rewritten.
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

        if ($previous !== null && ! in_array($previous, $keep, true)) {
            $this->storage->delete($previous);
        }

        // W11: drop stale extension variants (`logo.png` -> `logo.jpg`).
        $this->storage->deleteAlternateExtensions($path, $keep);

        $eventType->update(['logo_path' => $path]);
    }

    /**
     * Remove the logo file and reset `logo_path`.
     */
    public function destroy(EventType $eventType): void
    {
        $this->purge($eventType);

        $eventType->update(['logo_path' => null]);
    }

    /**
     * Remove the logo file only (no column update) — used when the event-type
     * row itself is deleted and the column write would be pointless. The
     * derived `.webp` sibling is removed with it (W11).
     */
    public function purge(EventType $eventType): void
    {
        if ($eventType->logo_path !== null) {
            $this->storage->delete($eventType->logo_path);
            $this->storage->deleteAlternateExtensions($eventType->logo_path);
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

            $this->storage->put($new, $this->storage->get($old));
            $this->storage->delete($old);
        }
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

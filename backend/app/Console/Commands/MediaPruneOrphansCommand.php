<?php

namespace App\Console\Commands;

use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * W11 reconciliation: the self-cleaning counterweight to the derived WebP
 * cache. Lists (dry run, default) or deletes (`--force`) public media files on
 * the `media` disk that no DB row references any more.
 *
 * Referenced paths come from every DB media column — `mandants.logo_path` /
 * `header_path`, `teams.logo_path`, `event_types.logo_path`,
 * `badge_images.path` — plus their derived `.webp` siblings. Host-neutral
 * `_tenants/<id>/…` paths are ordinary references here: they are preserved as
 * long as a row points at them and become orphans once the mandant is gone
 * (the `_tenants` special case).
 *
 * Only paths that match the managed media layout are considered
 * (`<domain>/{teams,event-types,badges}/…`, `<domain>/logo|header.<ext>`,
 * `_tenants/<id>/…`). Deployment-provided brand fallbacks at the media root
 * (`logo.svg`, favicons, `site.webmanifest`, …) are never touched, even though
 * no DB row references them.
 *
 * Idempotent: a second run finds nothing. Recommended cadence: weekly through
 * the application scheduler, e.g.
 * `Schedule::command('media:prune-orphans --force')->weekly();`
 * (no scheduler infrastructure is created here). Synchronous deletes already
 * cascade the derivatives; this command only catches historical/other orphans.
 */
class MediaPruneOrphansCommand extends Command
{
    protected $signature = 'media:prune-orphans
        {--force : Delete the orphaned files (default is a dry run)}
        {--dry-run : List orphaned files without deleting anything (default)}';

    protected $description = 'List/delete public media files no DB row references (derived WebP cache reaper; recommended weekly via the scheduler)';

    /**
     * Raster brand leaf names written by the media services. SVG is excluded:
     * root/domain fallback SVGs are deployment-provided, not DB-managed.
     */
    private const MANAGED_BRAND_LEAF = '/^(?:logo|header)\.(?:png|jpg|jpeg|webp)$/';

    public function handle(MediaStorage $storage): int
    {
        $force = (bool) $this->option('force') && ! $this->option('dry-run');

        $referenced = $this->referencedPaths($storage);
        $orphans = [];

        foreach (Storage::disk(MediaStorage::PUBLIC_DISK)->allFiles() as $file) {
            if (! $this->isManagedPath($file) || isset($referenced[$file])) {
                continue;
            }

            $orphans[] = $file;
        }

        sort($orphans);

        if ($orphans === []) {
            $this->info('No orphaned media files.');

            return self::SUCCESS;
        }

        foreach ($orphans as $file) {
            if (! $force) {
                $this->line('[dry-run] orphan: '.$file);

                continue;
            }

            Storage::disk(MediaStorage::PUBLIC_DISK)->delete($file);
            $this->line('deleted orphan: '.$file);
        }

        if (! $force) {
            $this->info(sprintf('%d orphaned file(s) found. Re-run with --force to delete.', count($orphans)));

            return self::SUCCESS;
        }

        $this->info(sprintf('Deleted %d orphaned media file(s).', count($orphans)));

        return self::SUCCESS;
    }

    /**
     * Every DB-referenced media path plus its derived `.webp` sibling, as a
     * lookup set.
     *
     * @return array<string, true>
     */
    private function referencedPaths(MediaStorage $storage): array
    {
        /** @var array<string, true> $referenced */
        $referenced = [];

        foreach (Mandant::query()->get(['logo_path', 'header_path']) as $mandant) {
            $this->add($referenced, $mandant->logo_path);
            $this->add($referenced, $mandant->header_path);
        }

        foreach (Team::query()->get(['logo_path']) as $team) {
            $this->add($referenced, $team->logo_path);
        }

        foreach (EventType::query()->get(['logo_path']) as $eventType) {
            $this->add($referenced, $eventType->logo_path);
        }

        foreach (BadgeImage::query()->get(['path']) as $image) {
            $this->add($referenced, $image->path);
        }

        // The derived WebP sibling of a referenced original is referenced too.
        foreach (array_keys($referenced) as $path) {
            $sibling = $storage->webpSiblingPath($path);

            if ($sibling !== null) {
                $referenced[$sibling] = true;
            }
        }

        return $referenced;
    }

    /**
     * @param  array<string, true>  $referenced
     */
    private function add(array &$referenced, mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            $referenced[$path] = true;
        }
    }

    /**
     * Whether the path belongs to the DB-managed media layout. Root-level brand
     * fallbacks (`logo.svg`, `favicon.ico`, …) are deliberately not managed.
     */
    private function isManagedPath(string $path): bool
    {
        $segments = explode('/', $path);

        if (count($segments) < 2) {
            return false;
        }

        if ($segments[0] === MediaPathService::HOST_NEUTRAL_SEGMENT) {
            $rest = array_slice($segments, 2);
        } else {
            $rest = array_slice($segments, 1);
        }

        if ($rest === []) {
            return false;
        }

        if (in_array($rest[0], [
            MediaPathService::TEAMS_SEGMENT,
            MediaPathService::EVENT_TYPES_SEGMENT,
            MediaPathService::BADGES_SEGMENT,
        ], true)) {
            return true;
        }

        return count($rest) === 1 && preg_match(self::MANAGED_BRAND_LEAF, $rest[0]) === 1;
    }
}

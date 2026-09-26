<?php

namespace App\Console\Commands;

use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use DomainException;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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
 * `_tenants/<id>/…`), and the FIRST path segment must itself be a plausible
 * layout root: a canonical, normalisable mandant host (or the reserved
 * `_tenants/<positive id>` prefix). Anything else is left alone — a directory
 * a deployment happened to create at `<anything>/badges/…` on the media root
 * is not DB-managed and must never be deleted, so the check fails closed
 * (WP-10-b). Deployment-provided brand fallbacks at the media root (`logo.svg`,
 * favicons, `site.webmanifest`, …) stay untouched too: they carry no
 * `<domain>/` prefix at all, even though no DB row references them.
 *
 * **Memory (WP-10-a).** Both sides of the comparison are streamed: the DB rows
 * are read in `chunkById` batches, and the disk is walked lazily instead of
 * through `allFiles()`, which materialises the entire media tree as one array.
 * This command is proposed for a weekly schedule, so the tree it walks is
 * expected to grow with every mandant; what still scales with the data set is
 * the two lookup structures (the referenced-path set and the orphan list) —
 * both plain path strings, not models.
 *
 * `--include-legacy` is the documented, strictly opt-in extension of the scope
 * (see "Wer räumt Waisen auf?" in `features/media-domain-layout.md`): it
 * additionally walks the two pre-W6 layouts on the `private` disk
 * (`mandants/{slug}/{logo,header}.<ext>`, `badge-images/{slug}/<ulid>.<ext>`),
 * which is the one case the services cannot self-heal. Default off, so a
 * scheduled `media:prune-orphans --force` never touches a file it did not
 * touch before; the dry run applies there as well, and the two passes report
 * separately. `user-media/**` is NOT part of the legacy scope: person images
 * (portrait, press ID, attachments) are live private data, never public media.
 *
 * Idempotent: a second run finds nothing. Recommended cadence: weekly through
 * the application scheduler, e.g.
 * `Schedule::command('media:prune-orphans --force')->weekly();`
 * (no scheduler infrastructure is created here).
 *
 * Deletes go through `MediaStorage::delete()` and are verified: a file that
 * survives the attempt (read-only volume, permissions regression) is reported
 * as a warning and makes the command exit non-zero, so a scheduled run cannot
 * look successful while orphans keep piling up (R-D7). Deletion also touches the
 * legacy `private` disk for the same relative path, which is consistent with
 * every other delete path.
 *
 * Synchronous vs. weekly: entity/service deletes remove their files (original
 * + `.webp` sibling) immediately, and the mandant delete purges the brand,
 * event-type and badge files before the DB cascade drops the rows (a mandant
 * with teams cannot be deleted at all). This command is therefore the safety
 * net for historical orphans and out-of-band disk changes — never the primary
 * delete path.
 */
class MediaPruneOrphansCommand extends Command
{
    /**
     * Rows per `chunkById` batch for every media table the reference set is
     * built from. The reaper is a weekly batch job over all mandants, so
     * `->get()` per table would hydrate every mandant, team, event type and
     * badge image of the whole installation at once; the chunk keeps only one
     * batch of models alive while the paths are collected. The collection
     * itself is a path-string lookup set and is built incrementally inside the
     * chunk callbacks.
     */
    private const DB_CHUNK_SIZE = 200;

    /**
     * Raster brand leaf names written by the media services. SVG is excluded:
     * root/domain fallback SVGs are deployment-provided, not DB-managed.
     */
    private const MANAGED_BRAND_LEAF = '/^(?:logo|header)\.(?:png|jpg|jpeg|webp)$/';

    /**
     * The two pre-W6 public layouts on the `private` disk, exactly as
     * `media:migrate-to-domain-layout` reads them: mandant brand files below
     * `mandants/{slug}/` and badge images below `badge-images/{slug}/`.
     */
    private const LEGACY_BRAND_ROOT = 'mandants';

    private const LEGACY_BADGE_ROOT = 'badge-images';

    /**
     * The `{slug}` segment of both legacy layouts. Looser than today's
     * mandant rule (`[a-z0-9-]+`) on purpose: these are historical files
     * written before the rule existed, and the pattern is only there to keep
     * the shape — a dot-free, traversal-free, single path segment.
     */
    private const LEGACY_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/';

    /**
     * Leaf of a legacy badge file: `{ULID}.{ext}`. Case-insensitive because
     * `Str::ulid()` is upper-case Crockford base32 while the extension comes
     * from the (lower-case) MIME whitelist.
     */
    private const LEGACY_BADGE_LEAF_PATTERN = '/^[a-z0-9][a-z0-9._-]*\.(?:png|jpg|jpeg|webp)$/i';

    protected $signature = 'media:prune-orphans
        {--force : Delete the orphaned files (default is a dry run)}
        {--dry-run : List orphaned files without deleting anything (default)}
        {--include-legacy : Additionally reap the pre-W6 legacy layouts (mandants/*, badge-images/*) on the private disk}';

    protected $description = 'List/delete public media files no DB row references (derived WebP cache reaper; recommended weekly via the scheduler)';

    public function handle(MediaStorage $storage, MediaPathService $paths): int
    {
        $force = (bool) $this->option('force') && ! $this->option('dry-run');
        $includeLegacy = (bool) $this->option('include-legacy');

        $referenced = $this->referencedPaths($storage);

        $orphans = $this->collectOrphans(
            MediaStorage::PUBLIC_DISK,
            $referenced,
            fn (string $path): bool => $this->isManagedPath($paths, $path),
        );

        // Opt-in only: without the flag the `private` disk is not enumerated
        // at all, so the default run cannot reach a pre-W6 file at all.
        $legacy = $includeLegacy
            ? $this->collectOrphans(
                MediaStorage::LEGACY_DISK,
                $referenced,
                fn (string $path): bool => $this->isLegacyPath($path),
            )
            : [];

        if ($orphans === [] && $legacy === []) {
            $this->info('No orphaned media files.');

            return self::SUCCESS;
        }

        $reaped = $this->reap($orphans, $force, $storage, 'orphan');
        $reapedLegacy = $this->reap($legacy, $force, $storage, 'legacy orphan');

        if (! $force) {
            if ($orphans !== []) {
                $this->info(sprintf('%d orphaned file(s) found. Re-run with --force to delete.', count($orphans)));
            }

            if ($legacy !== []) {
                $this->info(sprintf(
                    '%d legacy file(s) found on the pre-W6 layouts of the %s disk. Re-run with --force to delete.',
                    count($legacy),
                    MediaStorage::LEGACY_DISK,
                ));
            }

            return self::SUCCESS;
        }

        // `MediaStorage::delete()` verifies its own outcome (both disks run
        // with `throw => false`, so a failed unlink is a `false` return). An
        // orphan is not referenced by any row, so nothing else in the app
        // depends on it — but the operator must still learn that the file is
        // still on disk, hence the non-zero exit code.
        if ($reaped['failed'] !== []) {
            $this->error(sprintf(
                '%d orphan(s) could not be deleted (read-only volume or a permissions regression?). Re-run once the media disk is writable.',
                count($reaped['failed']),
            ));
        }

        if ($reapedLegacy['failed'] !== []) {
            $this->error(sprintf(
                '%d legacy orphan(s) could not be deleted (read-only volume or a permissions regression?). Re-run once the %s disk is writable.',
                count($reapedLegacy['failed']),
                MediaStorage::LEGACY_DISK,
            ));
        }

        if ($reaped['failed'] !== [] || $reapedLegacy['failed'] !== []) {
            return self::FAILURE;
        }

        if ($orphans !== []) {
            $this->info(sprintf('Deleted %d orphaned media file(s).', $reaped['deleted']));
        }

        if ($legacy !== []) {
            $this->info(sprintf(
                'Deleted %d legacy media file(s) from the %s disk.',
                $reapedLegacy['deleted'],
                MediaStorage::LEGACY_DISK,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Every DB-referenced media path plus its derived `.webp` sibling, as a
     * lookup set.
     *
     * Read in `chunkById` batches (WP-10-a) — the set only grows by the two
     * path strings per row, never by a hydrated model per row.
     *
     * @return array<string, true>
     */
    private function referencedPaths(MediaStorage $storage): array
    {
        /** @var array<string, true> $referenced */
        $referenced = [];

        Mandant::query()
            ->select(['id', 'logo_path', 'header_path'])
            ->orderBy('id')
            ->chunkById(self::DB_CHUNK_SIZE, function (Collection $mandants) use (&$referenced, $storage): void {
                foreach ($mandants as $mandant) {
                    $this->add($referenced, $storage, $mandant->logo_path);
                    $this->add($referenced, $storage, $mandant->header_path);
                }
            });

        Team::query()
            ->select(['id', 'logo_path'])
            ->orderBy('id')
            ->chunkById(self::DB_CHUNK_SIZE, function (Collection $teams) use (&$referenced, $storage): void {
                foreach ($teams as $team) {
                    $this->add($referenced, $storage, $team->logo_path);
                }
            });

        EventType::query()
            ->select(['id', 'logo_path'])
            ->orderBy('id')
            ->chunkById(self::DB_CHUNK_SIZE, function (Collection $eventTypes) use (&$referenced, $storage): void {
                foreach ($eventTypes as $eventType) {
                    $this->add($referenced, $storage, $eventType->logo_path);
                }
            });

        BadgeImage::query()
            ->select(['id', 'path'])
            ->orderBy('id')
            ->chunkById(self::DB_CHUNK_SIZE, function (Collection $images) use (&$referenced, $storage): void {
                foreach ($images as $image) {
                    $this->add($referenced, $storage, $image->path);
                }
            });

        return $referenced;
    }

    /**
     * Add a stored media path and the derived WebP sibling of a referenced
     * original — the sibling is derived, so it is referenced too. Folding the
     * two together here keeps the second pass over the whole set unnecessary.
     *
     * @param  array<string, true>  $referenced
     */
    private function add(array &$referenced, MediaStorage $storage, mixed $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $referenced[$path] = true;

        $sibling = $storage->webpSiblingPath($path);

        if ($sibling !== null) {
            $referenced[$sibling] = true;
        }
    }

    /**
     * Every file of `$disk` that is unreferenced and accepted by `$isManaged`,
     * sorted.
     *
     * @param  array<string, true>  $referenced
     * @param  callable(string): bool  $isManaged
     * @return list<string>
     */
    private function collectOrphans(string $disk, array $referenced, callable $isManaged): array
    {
        $orphans = [];

        foreach ($this->walkFiles($disk) as $file) {
            if (! $isManaged($file) || isset($referenced[$file])) {
                continue;
            }

            $orphans[] = $file;
        }

        sort($orphans);

        return $orphans;
    }

    /**
     * Walk every file of `$disk` lazily, one at a time.
     *
     * `FilesystemAdapter::allFiles()` is exactly this iteration plus an
     * accumulator (`listContents()->filter(isFile)->sortByPath()->toArray()`),
     * so the file set is unchanged — the array is what had to go: a weekly
     * reaper would otherwise hold every file of every mandant in memory before
     * it could discard the (vast majority of) referenced ones. Ordering
     * changes from "sorted by the adapter" to "whatever the adapter yields",
     * which the caller normalises with `sort()` on the much smaller orphan set.
     *
     * @return Generator<int, string>
     */
    private function walkFiles(string $disk): Generator
    {
        foreach (Storage::disk($disk)->getDriver()->listContents('', true) as $entry) {
            if ($entry->isFile()) {
                yield $entry->path();
            }
        }
    }

    /**
     * List (dry run) or delete (force) one pass' files, reporting each of them.
     *
     * @param  list<string>  $files
     * @return array{deleted: int, failed: list<string>}
     */
    private function reap(array $files, bool $force, MediaStorage $storage, string $label): array
    {
        $deleted = 0;
        $failed = [];

        foreach ($files as $file) {
            if (! $force) {
                $this->line(sprintf('[dry-run] %s: %s', $label, $file));

                continue;
            }

            if (! $storage->delete($file)) {
                $failed[] = $file;
                $this->warn('could not delete '.$label.': '.$file);

                continue;
            }

            $deleted++;
            $this->line('deleted '.$label.': '.$file);
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Whether the path belongs to the DB-managed media layout — and whether its
     * first segment is a layout root at all.
     *
     * The first segment decides, and it is the check that keeps the reaper from
     * eating deployment data (WP-10-b):
     *
     * - `<domain>/…` — the segment must be a canonical, normalisable mandant
     *   host, checked through the public `MediaPathService::dirForHost()`
     *   contract (identical to the accel guard in `MediaStorage`), and it must
     *   contain a dot. Without the dot requirement the reserved single-label
     *   legacy roots (`mandants`, `badge-images`, `user-media`) would pass as
     *   a `<domain>/` directory, and `<anything>/badges/x.png` would be
     *   classified DB-managed and deleted under `--force`.
     * - `_tenants/<id>/…` — the second segment must be a positive mandant id,
     *   the same contract `MediaPathService::hostNeutralFile()` writes.
     *
     * Everything else is not managed and is never deleted. Failing closed costs
     * at most an orphan that has to be removed by hand; failing open destroys
     * files the application does not own.
     */
    private function isManagedPath(MediaPathService $paths, string $path): bool
    {
        if ($this->hasUnsafeSegment($path)) {
            return false;
        }

        $segments = explode('/', $path);

        if (count($segments) < 2) {
            return false;
        }

        if ($segments[0] === MediaPathService::HOST_NEUTRAL_SEGMENT) {
            if (! $this->isMandantIdSegment($segments[1] ?? null)) {
                return false;
            }

            $rest = array_slice($segments, 2);
        } else {
            if (! $this->isHostSegment($paths, $segments[0])) {
                return false;
            }

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

    /**
     * Whether the path sits in one of the two pre-W6 public layouts on the
     * `private` disk (`--include-legacy` only).
     *
     * Deliberately a POSITIVE allowlist of the two roots — `user-media/**` is
     * the third legacy root on that disk and is excluded on purpose: person
     * images (portrait, press ID, attachments) are live private data, not
     * public media, and a reaper must never reach them. The same reference
     * set decides: a legacy file that a row still points at (an un-migrated
     * mandant) is kept exactly like a managed one.
     */
    private function isLegacyPath(string $path): bool
    {
        if ($this->hasUnsafeSegment($path)) {
            return false;
        }

        $segments = explode('/', $path);

        if (count($segments) !== 3) {
            return false;
        }

        if (preg_match(self::LEGACY_SLUG_PATTERN, $segments[1]) !== 1) {
            return false;
        }

        return match ($segments[0]) {
            self::LEGACY_BRAND_ROOT => preg_match(self::MANAGED_BRAND_LEAF, $segments[2]) === 1,
            self::LEGACY_BADGE_ROOT => preg_match(self::LEGACY_BADGE_LEAF_PATTERN, $segments[2]) === 1,
            default => false,
        };
    }

    /**
     * Whether the first segment is a canonical, normalisable host. Routing it
     * through `MediaPathService::dirForHost()` keeps the host contract in one
     * place and rejects `_tenants`, uppercase and non-ASCII/IDN-invalid
     * segments. The dot is required so the single-label legacy roots can never
     * masquerade as a `<domain>/` directory (same rule as the accel guard).
     */
    private function isHostSegment(MediaPathService $paths, string $segment): bool
    {
        if (! str_contains($segment, '.')) {
            return false;
        }

        try {
            return $paths->dirForHost($segment) === $segment;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * Whether the `_tenants/` prefix is followed by a positive mandant id — the
     * contract `MediaPathService::hostNeutralFile()` writes (an int cast to
     * string, so no leading zeros).
     */
    private function isMandantIdSegment(?string $segment): bool
    {
        return is_string($segment) && preg_match('/^[1-9][0-9]*$/', $segment) === 1;
    }

    /**
     * Whether the path carries a segment that must never reach a delete: a
     * traversal step, a backslash or a control character. A local disk listing
     * cannot produce one, a remote key can — and the reaper is the one command
     * that deletes paths nobody in the application ever composed.
     */
    private function hasUnsafeSegment(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return true;
        }

        return in_array('..', explode('/', $path), true);
    }
}

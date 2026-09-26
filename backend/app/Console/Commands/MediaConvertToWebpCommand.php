<?php

namespace App\Console\Commands;

use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\MediaStorage;
use App\Services\WebpConverter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * W11 backfill: generate `.webp` siblings for the public brand/badge media that
 * already exists on the `media` disk (files uploaded before the synchronous
 * WebP writer landed).
 *
 * The command is a DRY RUN by default: it lists the candidates without writing.
 * `--force` performs the conversion (extension swap, original stays
 * authoritative). `--prune-originals` additionally deletes the raster original
 * after a successful conversion and repoints the DB row at the `.webp` file —
 * only meaningful once every delivery path can handle WebP (the badge renderer
 * does, `BadgeRenderService`). `--dry-run` wins over `--force`.
 *
 * Idempotent: an image whose `.webp` sibling already exists is skipped (unless
 * combined with `--prune-originals`, which still removes the redundant
 * original). SVG files are never converted (and never reach this command
 * through the upload whitelist).
 *
 * `--prune-originals` deletes through `MediaStorage::delete()`, which verifies
 * its outcome: a raster original that survives the attempt (read-only volume,
 * permissions regression — the disks run with `throw => false`) is reported as
 * a warning and NOT counted as pruned, while the row keeps pointing at the
 * WebP sibling.
 *
 * **Memory (WP-10-a).** The candidate tables are read in `chunkById` batches
 * and each batch is fully processed before the next one is read, so the peak
 * is one batch of models instead of every mandant, team, event type and badge
 * image of the installation (each of which used to be held in memory together
 * with a closure per media column). Rewriting a row inside the batch is safe:
 * only the path columns change, never the `id` the paging keys on. The
 * candidate counter is accumulated while iterating, so the dry-run summary is
 * unchanged.
 */
class MediaConvertToWebpCommand extends Command
{
    /**
     * Rows per `chunkById` batch, for every media table the candidates come
     * from. Large enough that a normal installation never pays for a second
     * round trip, small enough that the peak stays flat.
     */
    private const CHUNK_SIZE = 200;

    protected $signature = 'media:convert-to-webp
        {--force : Execute the conversion (default is a dry run)}
        {--dry-run : List candidates without touching anything (default)}
        {--prune-originals : Delete the raster original and repoint the DB row after conversion}';

    protected $description = 'Generate .webp siblings for existing public brand/badge media (idempotent)';

    /**
     * Extensions the WebP encoder accepts; SVG (and everything else) is never
     * converted.
     */
    private const CONVERTIBLE_EXTENSIONS = ['png', 'jpg', 'jpeg'];

    public function handle(MediaStorage $storage, WebpConverter $converter): int
    {
        $force = (bool) $this->option('force') && ! $this->option('dry-run');
        $prune = (bool) $this->option('prune-originals');

        $found = 0;
        $converted = 0;
        $pruned = 0;

        // One consumer for all four entity tables: the candidate is built
        // inside the chunk callback and processed right there, so nothing but
        // the current batch is alive.
        $consume = function (string $label, string $path, string $preset, callable $apply) use ($force, $prune, $storage, $converter, &$found, &$converted, &$pruned): void {
            $found++;

            $result = $this->process($label, $path, $preset, $apply, $force, $prune, $storage, $converter);

            $converted += $result['converted'];
            $pruned += $result['pruned'];
        };

        $this->eachMandantCandidate($consume);
        $this->eachTeamCandidate($consume);
        $this->eachEventTypeCandidate($consume);
        $this->eachBadgeImageCandidate($consume);

        if ($found === 0) {
            $this->info('Nothing to convert.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->info(sprintf('%d candidate(s) would be processed. Re-run with --force to execute.', $found));

            return self::SUCCESS;
        }

        $this->info(sprintf('Converted %d file(s); pruned %d original(s).', $converted, $pruned));

        return self::SUCCESS;
    }

    /**
     * Convert (or, in a dry run, list) one candidate.
     *
     * @return array{converted: int, pruned: int}
     */
    private function process(string $label, string $path, string $preset, callable $apply, bool $force, bool $prune, MediaStorage $storage, WebpConverter $converter): array
    {
        $sibling = $storage->webpSiblingPath($path);

        if ($sibling === null || ! $this->isConvertible($path)) {
            return ['converted' => 0, 'pruned' => 0];
        }

        if (! Storage::disk(MediaStorage::PUBLIC_DISK)->exists($path)) {
            $this->warn(sprintf('skipped %s: source file is missing (%s).', $label, $path));

            return ['converted' => 0, 'pruned' => 0];
        }

        $siblingExists = Storage::disk(MediaStorage::PUBLIC_DISK)->exists($sibling);

        // Already converted and no prune requested → nothing to do.
        if ($siblingExists && ! $prune) {
            return ['converted' => 0, 'pruned' => 0];
        }

        if (! $force) {
            $this->line(sprintf(
                '[dry-run] %s: %s -> %s%s',
                $label,
                $path,
                $sibling,
                $prune ? ' (prune original)' : '',
            ));

            return ['converted' => 0, 'pruned' => 0];
        }

        $converted = 0;
        $pruned = 0;

        if (! $siblingExists) {
            try {
                $storage->put($sibling, $converter->convertContents($storage->get($path), $preset));
            } catch (RuntimeException $exception) {
                $this->warn(sprintf('skipped %s: %s', $label, $exception->getMessage()));

                return ['converted' => 0, 'pruned' => 0];
            }

            $converted++;
            $this->line(sprintf('converted %s: %s -> %s', $label, $path, $sibling));
        }

        if ($prune) {
            $apply($sibling);

            // The row already points at the WebP sibling, so a raster
            // original that survives the delete is an unreferenced
            // leftover on the media disk (reaped by `media:prune-orphans`).
            // It is reported as a warning, not raised — the conversion
            // itself succeeded, and aborting the loop would skip every
            // remaining candidate.
            if (! $storage->delete($path)) {
                $this->warn(sprintf(
                    'pruned %s: the row now points at %s, but the original could not be removed: %s',
                    $label,
                    $sibling,
                    $path,
                ));

                return ['converted' => $converted, 'pruned' => 0];
            }

            $pruned++;
            $this->line(sprintf('pruned original %s: %s', $label, $path));
        }

        return ['converted' => $converted, 'pruned' => $pruned];
    }

    /**
     * Feed every mandant brand path to `$consume`, one `chunkById` batch at a
     * time.
     *
     * @param  callable(string, string, string, callable(string): void): void  $consume
     */
    private function eachMandantCandidate(callable $consume): void
    {
        Mandant::query()
            ->select(['id', 'logo_path', 'header_path'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $mandants) use ($consume): void {
                foreach ($mandants as $mandant) {
                    foreach (['logo' => 'logo_path', 'header' => 'header_path'] as $kind => $column) {
                        $path = $mandant->{$column};

                        if (! is_string($path) || $path === '') {
                            continue;
                        }

                        $consume(
                            sprintf('mandant#%d %s', $mandant->id, $kind),
                            $path,
                            $kind === 'header' ? WebpConverter::PRESET_PHOTO : WebpConverter::PRESET_LOGO,
                            static function (string $newPath) use ($mandant, $column): void {
                                $mandant->update([$column => $newPath]);
                            },
                        );
                    }
                }
            });
    }

    /**
     * @param  callable(string, string, string, callable(string): void): void  $consume
     */
    private function eachTeamCandidate(callable $consume): void
    {
        Team::query()
            ->select(['id', 'logo_path'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $teams) use ($consume): void {
                foreach ($teams as $team) {
                    if (! is_string($team->logo_path) || $team->logo_path === '') {
                        continue;
                    }

                    $consume(
                        sprintf('team#%d', $team->id),
                        $team->logo_path,
                        WebpConverter::PRESET_LOGO,
                        static function (string $newPath) use ($team): void {
                            $team->update(['logo_path' => $newPath]);
                        },
                    );
                }
            });
    }

    /**
     * @param  callable(string, string, string, callable(string): void): void  $consume
     */
    private function eachEventTypeCandidate(callable $consume): void
    {
        EventType::query()
            ->select(['id', 'logo_path'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $eventTypes) use ($consume): void {
                foreach ($eventTypes as $eventType) {
                    if (! is_string($eventType->logo_path) || $eventType->logo_path === '') {
                        continue;
                    }

                    $consume(
                        sprintf('event-type#%d', $eventType->id),
                        $eventType->logo_path,
                        WebpConverter::PRESET_LOGO,
                        static function (string $newPath) use ($eventType): void {
                            $eventType->update(['logo_path' => $newPath]);
                        },
                    );
                }
            });
    }

    /**
     * @param  callable(string, string, string, callable(string): void): void  $consume
     */
    private function eachBadgeImageCandidate(callable $consume): void
    {
        BadgeImage::query()
            ->select(['id', 'path', 'mime'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $images) use ($consume): void {
                foreach ($images as $image) {
                    if (! is_string($image->path) || $image->path === '') {
                        continue;
                    }

                    $consume(
                        sprintf('badge-image#%d', $image->id),
                        $image->path,
                        WebpConverter::PRESET_LOGO,
                        static function (string $newPath) use ($image): void {
                            // The renderer embeds the stored mime as the data URI.
                            $image->update(['path' => $newPath, 'mime' => 'image/webp']);
                        },
                    );
                }
            });
    }

    private function isConvertible(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONVERTIBLE_EXTENSIONS, true);
    }
}

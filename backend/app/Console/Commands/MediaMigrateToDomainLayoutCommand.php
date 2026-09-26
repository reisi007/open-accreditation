<?php

namespace App\Console\Commands;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaHostResolver;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * W6 backfill: move public brand/badge media written before the domain layout
 * (`mandants/{slug}/…` for logo/header, `badge-images/{slug}/…` for badge
 * images, both on the legacy `private` disk) onto the `media` disk in the
 * domain layout (`<host>/…`, host-neutral `_tenants/<id>/…` without a domain).
 *
 * Scope (deliberately narrow, W6-F3) — only rows whose stored path still starts
 * with one of the two legacy `private` prefixes above are candidates. NOT
 * candidates:
 * - `_tenants/<id>/…`: already on the `media` disk in the host-neutral W6
 *   layout; it stays readable through `MediaStorage` and is left untouched.
 * - old host-neutral `teams/<slug>/…` / `event-types/<slug>/…` paths without a
 *   `<domain>/` or `_tenants/<id>/` prefix: the services never wrote that
 *   layout (`TeamMediaService`/`EventTypeMediaService` always key on a host),
 *   so the command does not migrate them.
 * - team and event-type logos in general: introduced after W1/W6, never written
 *   in the old layout. Slug changes are handled by `moveForSlugChange`.
 *
 * The command is a DRY RUN by default (safe to run in production): it only
 * lists the candidates. `--force` performs the migration — copy the file to
 * the new path, update the DB path, then delete the legacy file. The
 * `--dry-run` flag is honoured even when combined with `--force`, so the safe
 * mode always wins.
 *
 * Idempotent: a row whose stored path is no longer in the legacy layout is
 * skipped, and a candidate whose source file is missing is reported and
 * skipped. Re-runs therefore converge and never touch already-migrated media.
 *
 * A migration whose legacy source cannot be deleted afterwards (read-only
 * volume, permissions regression — both disks run with `throw => false`) is
 * reported as a warning and counted as migrated: the DB already points at the
 * new file, the leftover is unreferenced, and a re-run would not retry it.
 *
 * **Memory (WP-10-D1).** Both candidate tables are read in `chunkById` batches
 * and each batch is fully processed before the next one is read, so the peak is
 * one batch of models instead of every mandant and every badge image of the
 * installation at once (each of which used to be held in memory together with a
 * closure per media column, and both lists were merged into one big candidate
 * array before the first line was printed). Same shape as
 * `MediaConvertToWebpCommand` / `MediaPruneOrphansCommand` (WP-10-a) and
 * `BackfillQrTokens`. Rewriting a row inside the batch is safe: only the path
 * columns change, never the `id` the paging keys on, so the candidate counter
 * and the summary are unchanged.
 */
class MediaMigrateToDomainLayoutCommand extends Command
{
    /**
     * Rows per `chunkById` batch, for both candidate tables. Large enough that
     * a normal installation never pays for a second round trip, small enough
     * that the peak stays flat.
     */
    private const CHUNK_SIZE = 200;

    protected $signature = 'media:migrate-to-domain-layout
        {--force : Execute the migration (default is a dry run)}
        {--dry-run : List candidates without touching anything (default)}';

    protected $description = 'Migrate legacy mandants/* and badge-images/* media into the media domain layout';

    public function handle(MediaPathService $paths, MediaHostResolver $hosts, MediaStorage $storage): int
    {
        $force = (bool) $this->option('force') && ! $this->option('dry-run');

        $found = 0;
        $migrated = 0;

        // One consumer for both entity tables: the candidate is built inside the
        // chunk callback and migrated right there, so nothing but the current
        // batch is alive. A write failure (`put()`) still aborts the whole run
        // — the exception is deliberately not caught, same as before.
        $consume = function (string $label, string $from, string $to, callable $apply) use ($force, $storage, &$found, &$migrated): void {
            $found++;

            if (! $force) {
                $this->line(sprintf('[dry-run] %s: %s -> %s', $label, $from, $to));

                return;
            }

            if (! $storage->exists($from)) {
                $this->warn(sprintf('skipped %s: source file is missing (%s).', $label, $from));

                return;
            }

            $storage->put($to, $storage->get($from));
            $apply($to);

            $migrated++;
            $this->line(sprintf('migrated %s: %s -> %s', $label, $from, $to));

            // The DB now points at the new file, so a legacy source that
            // survives the delete is an unreferenced leftover on the private
            // disk (not served by Caddy). It is reported, not raised: aborting
            // the loop here would strand every candidate after this one, and a
            // re-run skips the row anyway (its path is no longer legacy).
            if (! $storage->delete($from)) {
                $this->warn(sprintf(
                    'migrated %s, but the legacy source could not be removed: %s — delete it manually.',
                    $label,
                    $from,
                ));
            }
        };

        $this->eachBrandCandidate($consume, $paths, $hosts);
        $this->eachBadgeImageCandidate($consume, $paths, $hosts);

        if ($found === 0) {
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->info(sprintf('%d candidate(s) would be migrated. Re-run with --force to execute.', $found));

            return self::SUCCESS;
        }

        $this->info(sprintf('Migrated %d file(s) to the media domain layout.', $migrated));

        return self::SUCCESS;
    }

    /**
     * Hand every legacy mandant brand path to `$consume`, one `chunkById`
     * batch at a time (WP-10-D1).
     *
     * @param  callable(string, string, string, callable(string): void): void  $consume
     */
    private function eachBrandCandidate(callable $consume, MediaPathService $paths, MediaHostResolver $hosts): void
    {
        Mandant::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $mandants) use ($consume, $paths, $hosts): void {
                foreach ($mandants as $mandant) {
                    foreach (['logo' => 'logo_path', 'header' => 'header_path'] as $kind => $column) {
                        $current = $mandant->{$column};

                        if (! is_string($current) || ! str_starts_with($current, 'mandants/')) {
                            continue;
                        }

                        $to = $this->brandPath($paths, $hosts, $mandant, basename($current));

                        if ($to === $current) {
                            continue;
                        }

                        $consume(
                            sprintf('mandant#%d %s', $mandant->id, $kind),
                            $current,
                            $to,
                            function (string $path) use ($mandant, $column): void {
                                $mandant->update([$column => $path]);
                            },
                        );
                    }
                }
            });
    }

    /**
     * The same for the badge images. The mandant is eager-loaded per batch (one
     * query per chunk, not one per image).
     *
     * @param  callable(string, string, string, callable(string): void): void  $consume
     */
    private function eachBadgeImageCandidate(callable $consume, MediaPathService $paths, MediaHostResolver $hosts): void
    {
        BadgeImage::query()
            ->with('mandant')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $images) use ($consume, $paths, $hosts): void {
                foreach ($images as $image) {
                    $current = $image->path;

                    if (! is_string($current) || ! str_starts_with($current, 'badge-images/')) {
                        continue;
                    }

                    $mandant = $image->mandant;

                    if ($mandant === null) {
                        continue;
                    }

                    $to = $this->badgePath($paths, $hosts, $mandant, basename($current));

                    if ($to === $current) {
                        continue;
                    }

                    $consume(
                        sprintf('badge-image#%d', $image->id),
                        $current,
                        $to,
                        function (string $path) use ($image): void {
                            $image->update(['path' => $path]);
                        },
                    );
                }
            });
    }

    private function brandPath(MediaPathService $paths, MediaHostResolver $hosts, Mandant $mandant, string $name): string
    {
        $host = $hosts->hostFor($mandant);

        if ($host !== null) {
            try {
                return $paths->domainFile($host, $name);
            } catch (DomainException) {
                // Invalid legacy hostname → host-neutral fallback below.
            }
        }

        return $paths->hostNeutralFile($mandant->id, $name);
    }

    private function badgePath(MediaPathService $paths, MediaHostResolver $hosts, Mandant $mandant, string $name): string
    {
        $host = $hosts->hostFor($mandant);

        if ($host !== null) {
            try {
                return $paths->badgeFile($host, $name);
            } catch (DomainException) {
                // Invalid legacy hostname → host-neutral fallback below.
            }
        }

        return $paths->hostNeutralBadgeFile($mandant->id, $name);
    }
}

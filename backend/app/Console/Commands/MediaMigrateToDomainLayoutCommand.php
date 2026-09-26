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
 *   so the command does not migrate them. (`media:prune-orphans` reaps such a
 *   file as an orphan once nothing references it.)
 * - team and event-type logos in general: introduced after W1/W6, never written
 *   in the old layout. Slug changes are handled by `moveForSlugChange`.
 *
 * The command is a DRY RUN by default (safe to run in production): it only
 * lists the candidates. `--force` performs the migration — copy the file to the
 * new path, update the DB path, then delete the legacy file. The
 * `--dry-run` flag is honoured even when combined with `--force`, so the safe
 * mode always wins.
 *
 * Idempotent: a row whose stored path is no longer in the legacy layout is
 * skipped, and a candidate whose source file is missing is reported and
 * skipped. Re-runs therefore converge and never touch already-migrated media.
 *
 * Three outcomes per candidate, and they do NOT all count the same (W4):
 *
 * - **migrated** — the copy is written, the DB points at the new path, the
 *   legacy source is removed. The only success.
 * - **skipped** — the source file is missing, i.e. the DB references a legacy
 *   path that nothing is stored under any more. Nothing can be migrated and
 *   nothing is broken; the run reports the count and stays successful, so a
 *   one-shot backfill is not permanently "red" over a hand-cleaned row.
 * - **failed** — the command cannot even derive a target path for the row (a
 *   legacy leaf name the sanitizer rejects, W3). That is a defect in the data,
 *   not a benign absence, and it is reported per candidate AND fails the run:
 *   the alternative was a command printing a success summary while silently
 *   leaving rows behind, of which nobody ever learns.
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
        $skipped = 0;

        // One consumer for both entity tables: the candidate is built inside the
        // chunk callback and migrated right there, so nothing but the current
        // batch is alive. A write failure (`put()`) still aborts the whole run
        // — the exception is deliberately not caught, same as before.
        $consume = function (string $label, string $from, string $to, callable $apply) use ($force, $storage, &$found, &$migrated, &$skipped): void {
            $found++;

            if (! $force) {
                $this->line(sprintf('[dry-run] %s: %s -> %s', $label, $from, $to));

                return;
            }

            if (! $storage->exists($from)) {
                $skipped++;
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

        $failed = $this->eachBrandCandidate($consume, $paths, $hosts)
            + $this->eachBadgeImageCandidate($consume, $paths, $hosts);

        if ($found === 0) {
            $this->info('Nothing to migrate.');

            return $this->summariseFailures($failed);
        }

        if (! $force) {
            $this->info(sprintf('%d candidate(s) would be migrated. Re-run with --force to execute.', $found));

            return $this->summariseFailures($failed);
        }

        // W4: the summary states how many of the candidates actually moved.
        // "Migrated N file(s)" without the denominator read as a total, so a run
        // that migrated one of nine looked complete.
        $this->info(sprintf(
            'Migrated %d of %d candidate(s) to the media domain layout.',
            $migrated,
            $found,
        ));

        if ($skipped > 0) {
            $this->warn(sprintf(
                '%d candidate(s) were skipped because their legacy source file is missing.',
                $skipped,
            ));
        }

        return $this->summariseFailures($failed);
    }

    /**
     * The non-zero exit of a run that left candidates behind because their
     * target path could not be derived (W4). Without this the command returned
     * SUCCESS for a run that migrated nothing, so neither a scheduled run nor
     * an operator learned that a row was left in the legacy layout.
     */
    private function summariseFailures(int $failed): int
    {
        if ($failed === 0) {
            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d candidate(s) had no usable target path in the new layout and were left unmigrated. Correct the stored path and re-run.',
            $failed,
        ));

        return self::FAILURE;
    }

    /**
     * Hand every legacy mandant brand path to `$consume`, one `chunkById`
     * batch at a time (WP-10-D1).
     *
     * @param  callable(string, string, string, callable(string): void): void  $consume
     * @return int the number of candidates that could not even be addressed
     */
    private function eachBrandCandidate(callable $consume, MediaPathService $paths, MediaHostResolver $hosts): int
    {
        $failed = 0;

        Mandant::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $mandants) use ($consume, $paths, $hosts, &$failed): void {
                foreach ($mandants as $mandant) {
                    foreach (['logo' => 'logo_path', 'header' => 'header_path'] as $kind => $column) {
                        $current = $mandant->{$column};

                        if (! is_string($current) || ! str_starts_with($current, 'mandants/')) {
                            continue;
                        }

                        $label = sprintf('mandant#%d %s', $mandant->id, $kind);
                        $to = $this->brandPath($paths, $hosts, $mandant, basename($current));

                        if ($to === null) {
                            $failed++;
                            $this->warn(sprintf('skipped %s: "%s" has no usable target path in the new layout.', $label, $current));

                            continue;
                        }

                        if ($to === $current) {
                            continue;
                        }

                        $consume(
                            $label,
                            $current,
                            $to,
                            function (string $path) use ($mandant, $column): void {
                                $mandant->update([$column => $path]);
                            },
                        );
                    }
                }
            });

        return $failed;
    }

    /**
     * The same for the badge images. The mandant is eager-loaded per batch (one
     * query per chunk, not one per image).
     *
     * @param  callable(string, string, string, callable(string): void): void  $consume
     * @return int the number of candidates that could not even be addressed
     */
    private function eachBadgeImageCandidate(callable $consume, MediaPathService $paths, MediaHostResolver $hosts): int
    {
        $failed = 0;

        BadgeImage::query()
            ->with('mandant')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $images) use ($consume, $paths, $hosts, &$failed): void {
                foreach ($images as $image) {
                    $current = $image->path;

                    if (! is_string($current) || ! str_starts_with($current, 'badge-images/')) {
                        continue;
                    }

                    $mandant = $image->mandant;

                    if ($mandant === null) {
                        continue;
                    }

                    $label = sprintf('badge-image#%d', $image->id);
                    $to = $this->badgePath($paths, $hosts, $mandant, basename($current));

                    if ($to === null) {
                        $failed++;
                        $this->warn(sprintf('skipped %s: "%s" has no usable target path in the new layout.', $label, $current));

                        continue;
                    }

                    if ($to === $current) {
                        continue;
                    }

                    $consume(
                        $label,
                        $current,
                        $to,
                        function (string $path) use ($image): void {
                            $image->update(['path' => $path]);
                        },
                    );
                }
            });

        return $failed;
    }

    /**
     * The target path of a legacy brand file, or null when no layout can hold
     * the given leaf name (W3).
     *
     * The `DomainException` is caught around BOTH attempts, not only around the
     * domain-layout one: the fallback sanitises the very same name, so a leaf
     * the sanitizer rejects (a legacy non-ASCII name, a name carrying a
     * traversal sequence) raised again from the fallback and aborted the whole
     * run — every row after the corrupt one stayed in the legacy layout and the
     * operator got a stack trace instead of a per-row warning. One bad column
     * is now one reported row, and the run reports it in the exit code.
     */
    private function brandPath(MediaPathService $paths, MediaHostResolver $hosts, Mandant $mandant, string $name): ?string
    {
        $host = $hosts->hostFor($mandant);

        if ($host !== null) {
            try {
                return $paths->domainFile($host, $name);
            } catch (DomainException) {
                // Invalid legacy hostname → host-neutral fallback below.
            }
        }

        try {
            return $paths->hostNeutralFile($mandant->id, $name);
        } catch (DomainException) {
            return null;
        }
    }

    /**
     * The badge counterpart of `brandPath()` — same two-step fallback, same
     * reason to catch the second `DomainException` as well.
     */
    private function badgePath(MediaPathService $paths, MediaHostResolver $hosts, Mandant $mandant, string $name): ?string
    {
        $host = $hosts->hostFor($mandant);

        if ($host !== null) {
            try {
                return $paths->badgeFile($host, $name);
            } catch (DomainException) {
                // Invalid legacy hostname → host-neutral fallback below.
            }
        }

        try {
            return $paths->hostNeutralBadgeFile($mandant->id, $name);
        } catch (DomainException) {
            return null;
        }
    }
}

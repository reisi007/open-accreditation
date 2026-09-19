<?php

namespace App\Console\Commands;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaHostResolver;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use DomainException;
use Illuminate\Console\Command;

/**
 * W6 backfill: move public brand/badge media written before the domain layout
 * (`mandants/{slug}/…` for logo/header, `badge-images/{slug}/…` for badge
 * images, both on the legacy `private` disk) onto the `media` disk in the
 * domain layout (`<host>/…`, host-neutral `_tenants/<id>/…` without a domain).
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
 */
class MediaMigrateToDomainLayoutCommand extends Command
{
    protected $signature = 'media:migrate-to-domain-layout
        {--force : Execute the migration (default is a dry run)}
        {--dry-run : List candidates without touching anything (default)}';

    protected $description = 'Migrate legacy mandants/* and badge-images/* media into the media domain layout';

    public function handle(MediaPathService $paths, MediaHostResolver $hosts, MediaStorage $storage): int
    {
        $force = (bool) $this->option('force') && ! $this->option('dry-run');

        $candidates = [
            ...$this->mandantBrandCandidates($paths, $hosts),
            ...$this->badgeImageCandidates($paths, $hosts),
        ];

        if ($candidates === []) {
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        $migrated = 0;

        foreach ($candidates as $candidate) {
            if (! $force) {
                $this->line(sprintf('[dry-run] %s: %s -> %s', $candidate['label'], $candidate['from'], $candidate['to']));

                continue;
            }

            if (! $storage->exists($candidate['from'])) {
                $this->warn(sprintf('skipped %s: source file is missing (%s).', $candidate['label'], $candidate['from']));

                continue;
            }

            $storage->put($candidate['to'], $storage->get($candidate['from']));
            ($candidate['apply'])($candidate['to']);
            $storage->delete($candidate['from']);

            $migrated++;
            $this->line(sprintf('migrated %s: %s -> %s', $candidate['label'], $candidate['from'], $candidate['to']));
        }

        if (! $force) {
            $this->info(sprintf('%d candidate(s) would be migrated. Re-run with --force to execute.', count($candidates)));

            return self::SUCCESS;
        }

        $this->info(sprintf('Migrated %d file(s) to the media domain layout.', $migrated));

        return self::SUCCESS;
    }

    /**
     * @return list<array{label: string, from: string, to: string, apply: callable(string): void}>
     */
    private function mandantBrandCandidates(MediaPathService $paths, MediaHostResolver $hosts): array
    {
        $candidates = [];

        foreach (Mandant::query()->orderBy('id')->get() as $mandant) {
            foreach (['logo' => 'logo_path', 'header' => 'header_path'] as $kind => $column) {
                $current = $mandant->{$column};

                if (! is_string($current) || ! str_starts_with($current, 'mandants/')) {
                    continue;
                }

                $to = $this->brandPath($paths, $hosts, $mandant, basename($current));

                if ($to === $current) {
                    continue;
                }

                $candidates[] = [
                    'label' => sprintf('mandant#%d %s', $mandant->id, $kind),
                    'from' => $current,
                    'to' => $to,
                    'apply' => function (string $path) use ($mandant, $column): void {
                        $mandant->update([$column => $path]);
                    },
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return list<array{label: string, from: string, to: string, apply: callable(string): void}>
     */
    private function badgeImageCandidates(MediaPathService $paths, MediaHostResolver $hosts): array
    {
        $candidates = [];

        foreach (BadgeImage::query()->with('mandant')->orderBy('id')->get() as $image) {
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

            $candidates[] = [
                'label' => sprintf('badge-image#%d', $image->id),
                'from' => $current,
                'to' => $to,
                'apply' => function (string $path) use ($image): void {
                    $image->update(['path' => $path]);
                },
            ];
        }

        return $candidates;
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

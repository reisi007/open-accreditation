<?php

namespace App\Console\Commands;

use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\MediaStorage;
use App\Services\WebpConverter;
use Illuminate\Console\Command;
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
 */
class MediaConvertToWebpCommand extends Command
{
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

        $candidates = $this->candidates();

        if ($candidates === []) {
            $this->info('Nothing to convert.');

            return self::SUCCESS;
        }

        $converted = 0;
        $pruned = 0;

        foreach ($candidates as $candidate) {
            $path = $candidate['path'];
            $sibling = $storage->webpSiblingPath($path);

            if ($sibling === null || ! $this->isConvertible($path)) {
                continue;
            }

            if (! Storage::disk(MediaStorage::PUBLIC_DISK)->exists($path)) {
                $this->warn(sprintf('skipped %s: source file is missing (%s).', $candidate['label'], $path));

                continue;
            }

            $siblingExists = Storage::disk(MediaStorage::PUBLIC_DISK)->exists($sibling);

            // Already converted and no prune requested → nothing to do.
            if ($siblingExists && ! $prune) {
                continue;
            }

            if (! $force) {
                $this->line(sprintf(
                    '[dry-run] %s: %s -> %s%s',
                    $candidate['label'],
                    $path,
                    $sibling,
                    $prune ? ' (prune original)' : '',
                ));

                continue;
            }

            if (! $siblingExists) {
                try {
                    $storage->put($sibling, $converter->convertContents($storage->get($path), $candidate['preset']));
                } catch (RuntimeException $exception) {
                    $this->warn(sprintf('skipped %s: %s', $candidate['label'], $exception->getMessage()));

                    continue;
                }

                $converted++;
                $this->line(sprintf('converted %s: %s -> %s', $candidate['label'], $path, $sibling));
            }

            if ($prune) {
                $candidate['apply']($sibling);
                $storage->delete($path);
                $pruned++;
                $this->line(sprintf('pruned original %s: %s', $candidate['label'], $path));
            }
        }

        if (! $force) {
            $this->info(sprintf('%d candidate(s) would be processed. Re-run with --force to execute.', count($candidates)));

            return self::SUCCESS;
        }

        $this->info(sprintf('Converted %d file(s); pruned %d original(s).', $converted, $pruned));

        return self::SUCCESS;
    }

    /**
     * @return list<array{label: string, path: string, preset: string, apply: callable(string): void}>
     */
    private function candidates(): array
    {
        $candidates = [];

        foreach (Mandant::query()->orderBy('id')->get() as $mandant) {
            foreach (['logo' => 'logo_path', 'header' => 'header_path'] as $kind => $column) {
                $path = $mandant->{$column};

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $candidates[] = [
                    'label' => sprintf('mandant#%d %s', $mandant->id, $kind),
                    'path' => $path,
                    'preset' => $kind === 'header' ? WebpConverter::PRESET_PHOTO : WebpConverter::PRESET_LOGO,
                    'apply' => static function (string $newPath) use ($mandant, $column): void {
                        $mandant->update([$column => $newPath]);
                    },
                ];
            }
        }

        foreach (Team::query()->orderBy('id')->get() as $team) {
            if (is_string($team->logo_path) && $team->logo_path !== '') {
                $candidates[] = [
                    'label' => sprintf('team#%d', $team->id),
                    'path' => $team->logo_path,
                    'preset' => WebpConverter::PRESET_LOGO,
                    'apply' => static function (string $newPath) use ($team): void {
                        $team->update(['logo_path' => $newPath]);
                    },
                ];
            }
        }

        foreach (EventType::query()->orderBy('id')->get() as $eventType) {
            if (is_string($eventType->logo_path) && $eventType->logo_path !== '') {
                $candidates[] = [
                    'label' => sprintf('event-type#%d', $eventType->id),
                    'path' => $eventType->logo_path,
                    'preset' => WebpConverter::PRESET_LOGO,
                    'apply' => static function (string $newPath) use ($eventType): void {
                        $eventType->update(['logo_path' => $newPath]);
                    },
                ];
            }
        }

        foreach (BadgeImage::query()->orderBy('id')->get() as $image) {
            if (is_string($image->path) && $image->path !== '') {
                $candidates[] = [
                    'label' => sprintf('badge-image#%d', $image->id),
                    'path' => $image->path,
                    'preset' => WebpConverter::PRESET_LOGO,
                    'apply' => static function (string $newPath) use ($image): void {
                        // The renderer embeds the stored mime as the data URI.
                        $image->update(['path' => $newPath, 'mime' => 'image/webp']);
                    },
                ];
            }
        }

        return $candidates;
    }

    private function isConvertible(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONVERTIBLE_EXTENSIONS, true);
    }
}

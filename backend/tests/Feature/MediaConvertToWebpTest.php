<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W11 — `media:convert-to-webp` backfill for pre-existing media.
 *
 * Dry run by default, `--force` executes, `--prune-originals` repoints the DB
 * at the WebP file after deleting the raster original. Idempotent; SVG is
 * never converted.
 */
class MediaConvertToWebpTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaPathService::DISK);
        Storage::fake(MediaStorage::LEGACY_DISK);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    public function test_dry_run_lists_candidates_without_writing(): void
    {
        $this->seedRasterLogo('png');

        Artisan::call('media:convert-to-webp');
        $output = Artisan::output();

        $this->assertStringContainsString('[dry-run] mandant#'.$this->mandant->id.' logo:', $output);
        $this->assertStringContainsString('verband-a.test/logo.png -> verband-a.test/logo.webp', $output);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.webp');
        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);
    }

    public function test_force_writes_the_sibling_and_keeps_the_original(): void
    {
        $this->seedRasterLogo('png');

        Artisan::call('media:convert-to-webp', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');
        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);

        // Idempotent: the second run finds nothing to convert.
        Artisan::call('media:convert-to-webp', ['--force' => true]);
        $this->assertStringContainsString('Converted 0 file(s)', Artisan::output());
    }

    public function test_prune_originals_deletes_the_original_and_repoints_the_db(): void
    {
        $this->seedRasterLogo('png');

        Artisan::call('media:convert-to-webp', ['--force' => true, '--prune-originals' => true]);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');
        $this->assertSame('verband-a.test/logo.webp', $this->mandant->fresh()->logo_path);
    }

    public function test_svg_is_never_converted(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.svg', '<svg></svg>');
        $this->mandant->update(['logo_path' => 'verband-a.test/logo.svg']);

        Artisan::call('media:convert-to-webp', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.svg');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.webp');
        $this->assertStringContainsString('Converted 0 file(s)', Artisan::output());
    }

    public function test_prune_originals_updates_the_badge_mime_for_the_renderer(): void
    {
        $path = 'verband-a.test/badges/01j0abc.png';
        Storage::disk(MediaPathService::DISK)->put($path, $this->imageBytes('png'));

        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        Artisan::call('media:convert-to-webp', ['--force' => true, '--prune-originals' => true]);

        $fresh = $image->fresh();
        $this->assertSame('verband-a.test/badges/01j0abc.webp', $fresh->path);
        $this->assertSame('image/webp', $fresh->mime);
        Storage::disk(MediaPathService::DISK)->assertMissing($path);
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/badges/01j0abc.webp');
    }

    private function seedRasterLogo(string $extension): void
    {
        $path = 'verband-a.test/logo.'.$extension;
        Storage::disk(MediaPathService::DISK)->put($path, $this->imageBytes($extension));
        $this->mandant->update(['logo_path' => $path]);
    }

    private function imageBytes(string $format): string
    {
        $image = imagecreatetruecolor(24, 16);

        ob_start();

        match ($format) {
            'png' => imagepng($image),
            'jpg' => imagejpeg($image),
            'webp' => imagewebp($image),
            default => throw new \RuntimeException('Unsupported test format '.$format),
        };

        return (string) ob_get_clean();
    }
}

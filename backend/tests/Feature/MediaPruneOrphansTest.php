<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W11 reconciliation — `media:prune-orphans`.
 *
 * The dry run lists only managed media files that no DB row references (nor
 * their `.webp` siblings); `--force` deletes exactly those. Deployment-provided
 * brand fallbacks at the media root are never touched.
 */
class MediaPruneOrphansTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaPathService::DISK);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    public function test_dry_run_lists_the_orphan_but_never_referenced_or_brand_files(): void
    {
        $this->seedReferencedLogo();
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/teams/gone/logo.png', $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/teams/gone/logo.webp', $this->webpBytes());

        // Deployment-provided brand fallback at the media root.
        Storage::disk(MediaPathService::DISK)->put('logo.svg', '<svg></svg>');

        Artisan::call('media:prune-orphans');
        $output = Artisan::output();

        $this->assertStringContainsString('verband-a.test/teams/gone/logo.png', $output);
        $this->assertStringNotContainsString('verband-a.test/logo.png', $output);
        $this->assertStringNotContainsString('verband-a.test/logo.webp', $output);
        $this->assertStringNotContainsString('logo.svg', $output);
        $this->assertStringContainsString('2 orphaned file(s) found', $output);

        // Dry run touches nothing.
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/gone/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('logo.svg');
    }

    public function test_force_deletes_only_the_orphans(): void
    {
        $this->seedReferencedLogo();
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/teams/gone/logo.png', $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/teams/gone/logo.webp', $this->webpBytes());
        Storage::disk(MediaPathService::DISK)->put('logo.svg', '<svg></svg>');

        Artisan::call('media:prune-orphans', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/gone/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/gone/logo.webp');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');
        Storage::disk(MediaPathService::DISK)->assertExists('logo.svg');
    }

    public function test_force_is_idempotent(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/teams/gone/logo.png', $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--force' => true]);
        Artisan::call('media:prune-orphans', ['--force' => true]);

        $this->assertStringContainsString('No orphaned media files', Artisan::output());
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/gone/logo.png');
    }

    public function test_badge_reference_keeps_the_original_and_its_sibling(): void
    {
        $path = 'verband-a.test/badges/01j0abc.png';
        Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/badges/01j0abc.webp', $this->webpBytes());

        BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        Artisan::call('media:prune-orphans', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertExists($path);
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/badges/01j0abc.webp');
    }

    public function test_unreferenced_host_neutral_files_are_pruned_but_referenced_ones_kept(): void
    {
        $referenced = '_tenants/'.$this->mandant->id.'/logo.png';
        Storage::disk(MediaPathService::DISK)->put($referenced, $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('_tenants/'.$this->mandant->id.'/logo.webp', $this->webpBytes());
        $this->mandant->update(['logo_path' => $referenced]);

        Storage::disk(MediaPathService::DISK)->put('_tenants/999/teams/gone/logo.png', $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertExists($referenced);
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandant->id.'/logo.webp');
        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/999/teams/gone/logo.png');
    }

    private function seedReferencedLogo(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.webp', $this->webpBytes());
        $this->mandant->update(['logo_path' => 'verband-a.test/logo.png']);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(4, 4);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function webpBytes(): string
    {
        $image = imagecreatetruecolor(4, 4);

        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }
}

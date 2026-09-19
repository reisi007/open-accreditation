<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaPathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W6 backfill — `media:migrate-to-domain-layout`.
 *
 * Legacy brand/badge media (`mandants/{slug}/…` for logo/header,
 * `badge-images/{slug}/…` for badge images, both on the `private` disk) is
 * moved onto the `media` disk in the domain layout. The command is a dry run
 * by default, `--force` executes the copy + DB update + legacy delete, and a
 * re-run is idempotent.
 */
class MediaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake(MediaPathService::DISK);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    public function test_dry_run_lists_candidates_without_touching_anything(): void
    {
        Storage::disk('private')->put('mandants/verband-a/logo.png', 'logo-bytes');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'badge-images/verband-a/01j0abc.png',
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);
        Storage::disk('private')->put($image->path, 'badge-bytes');

        $this->artisan('media:migrate-to-domain-layout')
            ->expectsOutputToContain('[dry-run] mandant#'.$this->mandant->id.' logo')
            ->expectsOutputToContain('[dry-run] badge-image#'.$image->id)
            ->assertSuccessful();

        // Nothing moved and nothing was deleted.
        $this->assertSame('mandants/verband-a/logo.png', $this->mandant->fresh()->logo_path);
        $this->assertSame('badge-images/verband-a/01j0abc.png', $image->fresh()->path);
        Storage::disk('private')->assertExists('mandants/verband-a/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.png');
    }

    public function test_force_migrates_brand_and_badge_media_into_the_domain_layout(): void
    {
        Storage::disk('private')->put('mandants/verband-a/logo.png', 'logo-bytes');
        Storage::disk('private')->put('mandants/verband-a/header.jpg', 'header-bytes');
        $this->mandant->update([
            'logo_path' => 'mandants/verband-a/logo.png',
            'header_path' => 'mandants/verband-a/header.jpg',
        ]);

        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'badge-images/verband-a/01j0abc.png',
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);
        Storage::disk('private')->put($image->path, 'badge-bytes');

        $this->artisan('media:migrate-to-domain-layout --force')->assertSuccessful();

        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);
        $this->assertSame('verband-a.test/header.jpg', $this->mandant->fresh()->header_path);
        $this->assertSame('verband-a.test/badges/01j0abc.png', $image->fresh()->path);

        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/header.jpg');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/badges/01j0abc.png');

        Storage::disk('private')->assertMissing('mandants/verband-a/logo.png');
        Storage::disk('private')->assertMissing('mandants/verband-a/header.jpg');
        Storage::disk('private')->assertMissing('badge-images/verband-a/01j0abc.png');
    }

    public function test_force_is_idempotent(): void
    {
        Storage::disk('private')->put('mandants/verband-a/logo.png', 'logo-bytes');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        $this->artisan('media:migrate-to-domain-layout --force')->assertSuccessful();
        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);

        // Second run has no candidates and leaves the migrated media untouched.
        $this->artisan('media:migrate-to-domain-layout --force')
            ->expectsOutputToContain('Nothing to migrate.')
            ->assertSuccessful();

        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
    }

    public function test_migration_uses_host_neutral_layout_without_a_domain(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband-ohne', 'name' => 'Ohne Domain']);
        Storage::disk('private')->put('mandants/verband-ohne/logo.png', 'logo-bytes');
        $mandant->update(['logo_path' => 'mandants/verband-ohne/logo.png']);

        $this->artisan('media:migrate-to-domain-layout --force')->assertSuccessful();

        $expected = '_tenants/'.$mandant->id.'/logo.png';
        $this->assertSame($expected, $mandant->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertExists($expected);
        Storage::disk('private')->assertMissing('mandants/verband-ohne/logo.png');
    }

    public function test_dry_run_wins_over_force(): void
    {
        Storage::disk('private')->put('mandants/verband-a/logo.png', 'logo-bytes');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        $this->artisan('media:migrate-to-domain-layout --force --dry-run')
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertSame('mandants/verband-a/logo.png', $this->mandant->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.png');
    }

    public function test_missing_source_file_is_skipped_and_path_kept(): void
    {
        // DB references a legacy path whose file is already gone.
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        $this->artisan('media:migrate-to-domain-layout --force')
            ->expectsOutputToContain('source file is missing')
            ->assertSuccessful();

        $this->assertSame('mandants/verband-a/logo.png', $this->mandant->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.png');
    }

    public function test_already_migrated_paths_are_not_candidates(): void
    {
        // Domain layout and host-neutral layout are both "new" — only the
        // legacy `mandants/` prefix is migrated.
        $this->mandant->update([
            'logo_path' => 'verband-a.test/logo.png',
            'header_path' => '_tenants/'.$this->mandant->id.'/header.png',
        ]);

        $this->artisan('media:migrate-to-domain-layout --force')
            ->expectsOutputToContain('Nothing to migrate.')
            ->assertSuccessful();

        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);
        $this->assertSame('_tenants/'.$this->mandant->id.'/header.png', $this->mandant->fresh()->header_path);
    }
}

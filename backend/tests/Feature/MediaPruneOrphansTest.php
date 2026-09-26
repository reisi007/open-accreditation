<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Finding 2 — the reaper is NOT a catch-all. It enumerates the managed
     * layout on the `media` disk only, so a stuck pre-W6 leftover on the
     * `private` disk is invisible to it, even with `--force`.
     *
     * That is why `MandantMediaService::logLeftover()` says "delete it manually"
     * for the legacy case instead of promising self-healing: this test is the
     * reason that promise would be false. Closing the gap would mean teaching
     * this command the two legacy layouts behind an explicit opt-in flag
     * (`--include-legacy`), which is deliberately NOT assumed here.
     */
    public function test_a_pre_w6_legacy_leftover_on_the_private_disk_is_never_reaped(): void
    {
        $legacy = 'mandants/verband-a/logo.jpg';
        Storage::disk(MediaStorage::LEGACY_DISK)->put($legacy, $this->pngBytes());

        // The path is invisible to the command: the `media` disk has nothing
        // under it, so `allFiles()` never yields the file.
        Storage::disk(MediaPathService::DISK)->assertMissing($legacy);

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString($legacy, $output);
        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists($legacy);
    }

    /**
     * The counterpart: a leftover in the managed layout — even below the
     * reserved legacy-looking roots, as long as it is DB-managed media — IS
     * reaped, which is what the "self-cleaning" claim in the docs is about.
     */
    public function test_a_managed_leftover_on_the_media_disk_is_reaped(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/header.png', $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/header.png');
    }

    /* ---------------------------------------------------------------------
     | WP-10-b — the first path segment decides, and an unknown root is never
     | deleted
     | ------------------------------------------------------------------- */

    /**
     * Positive control for the checks below: a real mandant host with a real
     * managed layout below it IS collected.
     */
    public function test_a_real_domain_badge_file_is_managed(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/badges/01j0abc.png', $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/badges/01j0abc.png');
    }

    /**
     * The finding: `isManagedPath()` used to strip `segments[0]` and only look
     * at the NEXT segment. Any deployment-provided directory that happened to
     * sit at `<anything>/badges/…` on the media root was therefore classified
     * DB-managed and deleted under `--force` — a directory the application does
     * not own and cannot reference from any row.
     */
    #[DataProvider('unknownRootProvider')]
    public function test_an_unknown_root_directory_is_never_reaped(string $path): void
    {
        Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        Storage::disk(MediaPathService::DISK)->assertExists($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownRootProvider(): array
    {
        return [
            'not a host at all' => ['not-a-host/badges/01j0abc.png'],
            'a reserved single-label legacy root' => ['mandants/badges/01j0abc.png'],
            'the private person-image root' => ['user-media/badges/01j0abc.png'],
            'the legacy badge root' => ['badge-images/badges/01j0abc.png'],
            'a single-label deployment directory' => ['uploads/badges/01j0abc.png'],
            'teams below an unknown root' => ['not-a-host/teams/verein-a/logo.png'],
            'a brand leaf below an unknown root' => ['not-a-host/logo.png'],
            // Not canonical: `dirForHost()` normalises both of these to
            // `verband-a.test`, so no service could have written them.
            'an upper-case host' => ['Verband-A.test/badges/01j0abc.png'],
            'a trailing-dot host' => ['verband-a.test./badges/01j0abc.png'],
            // The `_tenants/` prefix needs a positive mandant id.
            'host-neutral id 0' => ['_tenants/0/badges/01j0abc.png'],
            'host-neutral id abc' => ['_tenants/abc/badges/01j0abc.png'],
            'host-neutral negative id' => ['_tenants/-1/badges/01j0abc.png'],
            'host-neutral leading zeros' => ['_tenants/007/badges/01j0abc.png'],
            'host-neutral without an id' => ['_tenants/badges/01j0abc.png'],
        ];
    }

    /**
     * The positive counterpart of the `_tenants` checks: a real mandant id is
     * managed, so the host-neutral fallback of a mandant without a domain is
     * still reconciled.
     */
    public function test_a_host_neutral_path_with_a_positive_mandant_id_is_managed(): void
    {
        $path = '_tenants/'.$this->mandant->id.'/teams/verein-a/logo.png';
        Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--force' => true]);

        Storage::disk(MediaPathService::DISK)->assertMissing($path);
    }

    /**
     * A dry run must not even mention an unknown root: the operator reads the
     * list as "files this command would delete", so an entry there is already a
     * wrong promise.
     */
    public function test_the_dry_run_does_not_list_an_unknown_root(): void
    {
        Storage::disk(MediaPathService::DISK)->put('not-a-host/badges/01j0abc.png', $this->pngBytes());

        Artisan::call('media:prune-orphans');
        $output = Artisan::output();

        $this->assertStringNotContainsString('not-a-host', $output);
        $this->assertStringContainsString('No orphaned media files', $output);
        Storage::disk(MediaPathService::DISK)->assertExists('not-a-host/badges/01j0abc.png');
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

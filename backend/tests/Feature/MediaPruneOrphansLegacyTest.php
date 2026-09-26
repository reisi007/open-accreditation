<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * WP-10-c — `media:prune-orphans --include-legacy`.
 *
 * The pre-W6 public layouts live on the `private` disk
 * (`mandants/{slug}/{logo,header}.<ext>`, `badge-images/{slug}/{ulid}.<ext>`)
 * and nothing in the application reaps them: a brand upload whose predecessor
 * cannot be removed logs "delete it manually" (see `MediaDeleteFailureTest`),
 * because promising a self-healing the reaper does not deliver would be a lie
 * an operator acts on.
 *
 * `--include-legacy` is the documented fix for that — and it is strictly
 * opt-in, so a scheduled `media:prune-orphans --force` keeps its exact scope
 * until an operator asks for more. The dry run applies there as well, both
 * passes report separately, and a removal that fails makes the command exit
 * non-zero like every other failed removal (R-D7).
 *
 * `user-media/**` is NOT part of the legacy scope: person images (portrait,
 * press ID, attachments) are live private data, never public media.
 */
class MediaPruneOrphansLegacyTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    public function test_include_legacy_reaps_an_unreferenced_legacy_brand_file(): void
    {
        $this->seedPrivate('mandants/verband-a/logo.jpg');
        $this->seedPrivate('mandants/verband-a/header.png');

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);

        $this->assertSame(0, $exitCode);
        Storage::disk(MediaStorage::LEGACY_DISK)->assertMissing('mandants/verband-a/logo.jpg');
        Storage::disk(MediaStorage::LEGACY_DISK)->assertMissing('mandants/verband-a/header.png');
    }

    public function test_include_legacy_reaps_an_unreferenced_legacy_badge_file(): void
    {
        // Upper-case ULID, lower-case extension — the shape `Str::ulid()` and
        // the MIME whitelist produced before the domain layout.
        $this->seedPrivate('badge-images/verband-a/01M3EX7MXB8CBEGPY0VX9TPA5R.png');

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);

        $this->assertSame(0, $exitCode);
        Storage::disk(MediaStorage::LEGACY_DISK)->assertMissing('badge-images/verband-a/01M3EX7MXB8CBEGPY0VX9TPA5R.png');
    }

    public function test_include_legacy_is_idempotent(): void
    {
        $this->seedPrivate('mandants/verband-a/logo.jpg');

        Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);
        Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);

        $this->assertStringContainsString('No orphaned media files', Artisan::output());
    }

    /**
     * An un-migrated row still points at its legacy file: the reference set is
     * the same one the managed pass uses, so the file stays exactly as long as
     * the row does.
     */
    public function test_include_legacy_keeps_a_legacy_file_a_row_still_references(): void
    {
        $this->seedPrivate('mandants/verband-a/logo.jpg');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.jpg']);

        Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);

        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists('mandants/verband-a/logo.jpg');
    }

    public function test_include_legacy_keeps_a_legacy_badge_file_its_row_still_references(): void
    {
        $path = 'badge-images/verband-a/01M3EX7MXB8CBEGPY0VX9TPA5R.png';
        $this->seedPrivate($path);

        BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);

        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists($path);
    }

    /**
     * The third legacy root on that disk is the person-image store. It is live
     * private data, not public media, and no reaper may reach it.
     */
    public function test_include_legacy_never_reaps_person_images(): void
    {
        $portrait = 'user-media/verband-a/'.$this->mandant->id.'/portrait/portrait.jpg';
        $pressId = 'user-media/verband-a/'.$this->mandant->id.'/press-id/press.jpg';
        $attachment = 'user-media/verband-a/'.$this->mandant->id.'/attachment/zeug.pdf';

        $this->seedPrivate($portrait);
        $this->seedPrivate($pressId);
        $this->seedPrivate($attachment);

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('user-media', $output);
        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists($portrait);
        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists($pressId);
        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists($attachment);
    }

    /**
     * A shape that is not one of the two documented legacy layouts stays
     * untouched even behind the flag: the scope is a positive allowlist.
     */
    public function test_include_legacy_ignores_anything_outside_the_two_legacy_layouts(): void
    {
        $paths = [
            'mandants/verband-a/logo.txt',
            'mandants/verband-a/not-a-brand-leaf.png',
            'mandants/verband-a/nested/logo.jpg',
            'badge-images/verband-a/notes.txt',
            'sonstiges/verband-a/logo.jpg',
        ];

        foreach ($paths as $path) {
            $this->seedPrivate($path);
        }

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No orphaned media files', Artisan::output());

        foreach ($paths as $path) {
            Storage::disk(MediaStorage::LEGACY_DISK)->assertExists($path);
        }
    }

    public function test_include_legacy_dry_run_lists_both_passes_separately_and_deletes_nothing(): void
    {
        $this->seedMedia('verband-a.test/teams/gone/logo.png');
        $this->seedPrivate('mandants/verband-a/logo.jpg');

        $exitCode = Artisan::call('media:prune-orphans', ['--include-legacy' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[dry-run] orphan: verband-a.test/teams/gone/logo.png', $output);
        $this->assertStringContainsString('[dry-run] legacy orphan: mandants/verband-a/logo.jpg', $output);
        $this->assertStringContainsString('1 orphaned file(s) found', $output);
        $this->assertStringContainsString('1 legacy file(s) found on the pre-W6 layouts of the private disk', $output);

        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/gone/logo.png');
        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists('mandants/verband-a/logo.jpg');
    }

    /**
     * The safe mode still wins, as it does for every other option of the
     * command.
     */
    public function test_include_legacy_dry_run_wins_over_force(): void
    {
        $this->seedPrivate('mandants/verband-a/logo.jpg');

        Artisan::call('media:prune-orphans', ['--force' => true, '--dry-run' => true, '--include-legacy' => true]);

        $this->assertStringContainsString('[dry-run] legacy orphan: mandants/verband-a/logo.jpg', Artisan::output());
        Storage::disk(MediaStorage::LEGACY_DISK)->assertExists('mandants/verband-a/logo.jpg');
    }

    /**
     * Without the flag the `private` disk is not enumerated AT ALL — not
     * "enumerated and filtered", not read. The managed pass alone decides the
     * run, so a scheduled `--force` can never touch a legacy file.
     */
    public function test_without_the_flag_the_private_disk_is_never_enumerated(): void
    {
        $media = Storage::disk(MediaPathService::DISK);
        $private = Storage::disk(MediaStorage::LEGACY_DISK);

        $media->put('verband-a.test/teams/gone/logo.png', 'orphan-bytes');
        $private->put('mandants/verband-a/logo.jpg', 'legacy-bytes');

        $notEnumerated = Mockery::mock(Filesystem::class);
        $notEnumerated->shouldNotReceive('getDriver');
        $notEnumerated->shouldNotReceive('allFiles');
        $notEnumerated->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => $private->exists($path));
        $notEnumerated->shouldReceive('delete')->andReturnUsing(fn (...$paths) => $private->delete(...$paths));

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaStorage::LEGACY_DISK ? $notEnumerated : $media,
        );

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $private->assertExists('mandants/verband-a/logo.jpg');
    }

    /**
     * A legacy removal that fails is reported and makes the run exit non-zero,
     * exactly like a managed one: a scheduled run must not look successful
     * while files are still on disk (R-D7).
     */
    public function test_a_failed_legacy_delete_is_reported_and_exits_non_zero(): void
    {
        $media = Storage::disk(MediaPathService::DISK);
        $private = Storage::disk(MediaStorage::LEGACY_DISK);

        $private->put('mandants/verband-a/logo.jpg', 'legacy-bytes');

        $unremovable = Mockery::mock(Filesystem::class);
        $unremovable->shouldReceive('getDriver')->andReturnUsing(fn () => $private->getDriver());
        $unremovable->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => $private->exists($path));
        $unremovable->shouldReceive('delete')->andReturn(false);

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaStorage::LEGACY_DISK ? $unremovable : $media,
        );

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('could not delete legacy orphan: mandants/verband-a/logo.jpg', $output);
        $this->assertStringContainsString('1 legacy orphan(s) could not be deleted', $output);
        $this->assertStringNotContainsString('Deleted 1 legacy media file(s)', $output);
        $private->assertExists('mandants/verband-a/logo.jpg');
    }

    /**
     * A managed removal that fails while the legacy pass succeeds still fails
     * the run, and the two reports do not get mixed up.
     */
    public function test_the_two_passes_report_independently(): void
    {
        $media = Storage::disk(MediaPathService::DISK);
        $private = Storage::disk(MediaStorage::LEGACY_DISK);

        $media->put('verband-a.test/teams/gone/logo.png', 'orphan-bytes');
        $private->put('mandants/verband-a/logo.jpg', 'legacy-bytes');

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true, '--include-legacy' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('deleted orphan: verband-a.test/teams/gone/logo.png', $output);
        $this->assertStringContainsString('deleted legacy orphan: mandants/verband-a/logo.jpg', $output);
        $this->assertStringContainsString('Deleted 1 orphaned media file(s).', $output);
        $this->assertStringContainsString('Deleted 1 legacy media file(s) from the private disk.', $output);

        $media->assertMissing('verband-a.test/teams/gone/logo.png');
        $private->assertMissing('mandants/verband-a/logo.jpg');
    }

    private function seedMedia(string $path): void
    {
        Storage::disk(MediaPathService::DISK)->put($path, 'media-bytes');
    }

    private function seedPrivate(string $path): void
    {
        Storage::disk(MediaStorage::LEGACY_DISK)->put($path, 'private-bytes');
    }
}

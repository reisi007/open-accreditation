<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\BadgeImageService;
use App\Services\EventTypeMediaService;
use App\Services\MandantMediaService;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use App\Services\TeamMediaService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * W6-F1 / W6-F2 regression — a failed media write must never destroy the
 * previous file or point the DB at a file that does not exist.
 *
 * The `media` disk is configured with `throw => false`, so a write failure
 * surfaces as a `false` return from `put()`/`putFileAs()`. Both the backfill
 * command (W6-F1) and every upload service (W6-F2) must treat that as fatal:
 * abort before applying the DB path / deleting the previous file / creating a
 * badge row.
 *
 * A write failure is injected by swapping only the `media` disk with a mock
 * that fails `put`/`putFileAs`; the legacy `private` disk and the previous
 * media files stay on the real (faked) disks so the "old file survived"
 * assertions are meaningful.
 */
class MediaWriteFailureTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    /**
     * The real `media` disk captured before the facade is mocked, so the tests
     * can still seed/assert the files that must survive a failed write.
     */
    private FilesystemAdapter $realMedia;

    /**
     * The real legacy `private` disk captured before the facade is mocked.
     */
    private FilesystemAdapter $realPrivate;

    /**
     * The directories whose failing write was attempted, in call order. Lets a
     * test prove the write targeted the DOMAIN layout while the stored previous
     * path is host-neutral — i.e. `$previous !== $path` actually holds.
     *
     * @var list<string>
     */
    private array $failedWriteDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStorage::LEGACY_DISK);
        Storage::fake(MediaPathService::DISK);

        // A configured domain is what makes the service target the domain
        // layout (`verband-a.test/…`) instead of the host-neutral
        // `_tenants/<id>/…` fallback. The store-failure tests seed their
        // previous path as host-neutral, so the delete-order guard
        // (`$previous !== $path`) is only genuinely exercised with a domain.
        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    /* ---------------------------------------------------------------------
     | W6-F1 — backfill command (`media:migrate-to-domain-layout --force`)
     | ------------------------------------------------------------------- */

    public function test_backfill_put_failure_keeps_db_path_and_legacy_source(): void
    {
        $this->realPrivate = Storage::disk(MediaStorage::LEGACY_DISK);
        $this->realMedia = Storage::disk(MediaPathService::DISK);

        $this->realPrivate->put('mandants/verband-a/logo.png', 'logo-bytes');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        // The media disk reports a write failure instead of throwing.
        $mediaDisk = Mockery::mock(Filesystem::class);
        $mediaDisk->shouldReceive('exists')->andReturn(false);
        $mediaDisk->shouldReceive('put')->once()->andReturn(false);
        $mediaDisk->shouldNotReceive('delete');

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaPathService::DISK ? $mediaDisk : $this->realPrivate,
        );

        try {
            Artisan::call('media:migrate-to-domain-layout', ['--force' => true]);
            $this->fail('A failed put() must abort the candidate with a RuntimeException.');
        } catch (RuntimeException) {
            // Expected: the candidate is aborted before apply()/delete().
        }

        $this->assertSame('mandants/verband-a/logo.png', $this->mandant->fresh()->logo_path);
        $this->realPrivate->assertExists('mandants/verband-a/logo.png');
        $this->realMedia->assertMissing('verband-a.test/logo.png');
    }

    /* ---------------------------------------------------------------------
     | W6-F2 — upload services (`putFileAs()` failure)
     | ------------------------------------------------------------------- */

    public function test_mandant_store_failure_keeps_previous_logo(): void
    {
        $service = app(MandantMediaService::class);

        $previous = '_tenants/'.$this->mandant->id.'/logo.png';
        $this->mandant->update(['logo_path' => $previous]);

        $this->mockFailingMediaDisk('putFileAs');

        $this->realMedia->put($previous, 'old-logo');

        $this->expectRuntimeExceptionFrom(
            fn () => $service->store($this->mandant, 'logo', UploadedFile::fake()->image('neu.jpg')),
        );

        // The write targeted the domain layout while the stored previous path
        // is host-neutral — so `$previous !== $path` genuinely held and the
        // mock's `shouldNotReceive('delete')` proves the delete-order guard.
        $this->assertSame(['verband-a.test'], $this->failedWriteDirectories);
        $this->assertSame($previous, $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists($previous);
    }

    public function test_team_store_failure_keeps_previous_logo(): void
    {
        $service = app(TeamMediaService::class);

        $team = Team::factory()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'verein-a',
            'name' => 'Verein A',
        ]);

        $previous = '_tenants/'.$this->mandant->id.'/teams/verein-a/logo.png';
        $team->update(['logo_path' => $previous]);

        $this->mockFailingMediaDisk('putFileAs');

        $this->realMedia->put($previous, 'old-logo');

        $this->expectRuntimeExceptionFrom(
            fn () => $service->store($team, UploadedFile::fake()->image('neu.jpg')),
        );

        $this->assertSame(['verband-a.test/teams/verein-a'], $this->failedWriteDirectories);
        $this->assertSame($previous, $team->fresh()->logo_path);
        $this->realMedia->assertExists($previous);
    }

    public function test_event_type_store_failure_keeps_previous_logo(): void
    {
        $service = app(EventTypeMediaService::class);

        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);

        $previous = '_tenants/'.$this->mandant->id.'/event-types/bundesliga/logo.png';
        $eventType->update(['logo_path' => $previous]);

        $this->mockFailingMediaDisk('putFileAs');

        $this->realMedia->put($previous, 'old-logo');

        $this->expectRuntimeExceptionFrom(
            fn () => $service->store($eventType, UploadedFile::fake()->image('neu.jpg')),
        );

        $this->assertSame(['verband-a.test/event-types/bundesliga'], $this->failedWriteDirectories);
        $this->assertSame($previous, $eventType->fresh()->logo_path);
        $this->realMedia->assertExists($previous);
    }

    public function test_badge_image_store_failure_creates_no_row(): void
    {
        $service = app(BadgeImageService::class);

        $this->mockFailingMediaDisk('putFileAs');

        $this->expectRuntimeExceptionFrom(
            fn () => $service->store($this->mandant, UploadedFile::fake()->image('wappen.png')),
        );

        $this->assertSame(['verband-a.test/badges'], $this->failedWriteDirectories);
        $this->assertSame(0, BadgeImage::query()->count());
        $this->assertSame([], $this->realMedia->allFiles());
    }

    /* ---------------------------------------------------------------------
     | L1 — cross-layout success path (host-neutral previous → domain layout)
     | ------------------------------------------------------------------- */

    public function test_mandant_store_success_deletes_host_neutral_previous_and_persists_domain_path(): void
    {
        $service = app(MandantMediaService::class);

        // A file written before a domain existed (host-neutral fallback).
        $previous = '_tenants/'.$this->mandant->id.'/logo.png';
        $this->mandant->update(['logo_path' => $previous]);

        $this->realMedia = Storage::disk(MediaPathService::DISK);
        $this->realMedia->put($previous, 'old-logo');

        // The mandant now has a domain, so a successful replace must write to
        // the domain layout and delete the cross-layout predecessor.
        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('neu.png'));

        $path = 'verband-a.test/logo.png';

        $this->assertSame($path, $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists($path);
        $this->realMedia->assertMissing($previous);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Swap the `media` disk for a mock whose `$method` fails. The legacy
     * `private` disk and the real media files remain reachable through the
     * captured adapters so the "previous file survived" assertions hold. The
     * directory of the attempted write is recorded in
     * `$this->failedWriteDirectories` so callers can pin the domain layout.
     */
    private function mockFailingMediaDisk(string $method): void
    {
        $this->realMedia = Storage::disk(MediaPathService::DISK);
        $this->realPrivate = Storage::disk(MediaStorage::LEGACY_DISK);
        $this->failedWriteDirectories = [];

        $mediaDisk = Mockery::mock(Filesystem::class);
        $mediaDisk->shouldReceive($method)
            ->once()
            ->andReturnUsing(function (string $directory): bool {
                $this->failedWriteDirectories[] = $directory;

                return false;
            });
        $mediaDisk->shouldReceive('exists')->andReturn(false);
        $mediaDisk->shouldNotReceive('delete');

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaPathService::DISK ? $mediaDisk : $this->realPrivate,
        );
    }

    private function expectRuntimeExceptionFrom(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A failed putFileAs() must abort with a RuntimeException.');
        } catch (RuntimeException) {
            // Expected: the service aborts before delete/update.
        }
    }
}

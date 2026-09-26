<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\BadgeImageService;
use App\Services\EventTypeMediaService;
use App\Services\MandantMediaService;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use App\Services\TeamMediaService;
use App\Services\UserMediaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * WP-4-b / R-D7 regression — a failed unlink must never be reported as a
 * successful delete.
 *
 * The `media` and `private` disks run with `throw => false`, so a failed
 * `unlink` (read-only MEDIA_ROOT, a permissions regression, a detached volume)
 * is a `false` return value that the old `MediaStorage::delete()` discarded.
 * Every caller then reported success and cleared its DB column — while the
 * file stayed on disk and kept being served by Caddy.
 *
 * The contract now is: `delete()` returns `bool` and verifies its own
 * post-condition, and a caller may only drop the reference on `true`. The
 * unremovable volume is simulated by swapping the affected disk for a mock
 * whose `delete()` is a no-op that reports failure, while `exists()` keeps
 * answering from the real (faked) disk — exactly the read-only-volume shape.
 */
class MediaDeleteFailureTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    /**
     * Paths the partial `unremovablePathsDisk()` stub refuses to delete. See the
     * helper for why this is a property.
     *
     * @var list<string>
     */
    private array $unremovablePaths = [];

    /**
     * Optional observer for every delete attempt of the partial disk stub.
     *
     * @var (callable(string): void)|null
     */
    private $onDeleteAttempt = null;

    private FilesystemAdapter $realMedia;

    private FilesystemAdapter $realPrivate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Storage::fake(MediaPathService::DISK);
        Storage::fake(MediaStorage::LEGACY_DISK);

        $this->realMedia = Storage::disk(MediaPathService::DISK);
        $this->realPrivate = Storage::disk(MediaStorage::LEGACY_DISK);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    /* ---------------------------------------------------------------------
     | MediaStorage::delete() — the primitive
     | ------------------------------------------------------------------- */

    public function test_delete_returns_false_when_the_file_survives(): void
    {
        $this->realMedia->put('verband-a.test/logo.png', 'logo-bytes');

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $this->assertFalse(app(MediaStorage::class)->delete('verband-a.test/logo.png'));

        // The file is still there — exactly the state a caller must not forget.
        $this->realMedia->assertExists('verband-a.test/logo.png');
    }

    public function test_delete_returns_true_and_really_removes_the_file(): void
    {
        $this->realMedia->put('verband-a.test/logo.png', 'logo-bytes');

        $this->assertTrue(app(MediaStorage::class)->delete('verband-a.test/logo.png'));
        $this->realMedia->assertMissing('verband-a.test/logo.png');
    }

    public function test_delete_of_an_already_absent_file_is_a_success(): void
    {
        // No file on either disk: "nothing to delete". The pre-check means no
        // delete is even attempted, so a harmless re-run cannot 500.
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldNotReceive('delete');

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaStorage::PUBLIC_DISK ? $disk : $this->realPrivate,
        );

        $this->assertTrue(app(MediaStorage::class)->delete('verband-a.test/logo.png'));
    }

    public function test_delete_of_an_empty_path_is_a_success(): void
    {
        $this->assertTrue(app(MediaStorage::class)->delete(''));
    }

    public function test_delete_covers_the_legacy_disk_twin(): void
    {
        $this->realPrivate->put('verband-a.test/logo.png', 'legacy-logo-bytes');

        $this->assertTrue(app(MediaStorage::class)->delete('verband-a.test/logo.png'));

        $this->realPrivate->assertMissing('verband-a.test/logo.png');
    }

    /* ---------------------------------------------------------------------
     | MediaStorage::deleteAlternateExtensions()
     | ------------------------------------------------------------------- */

    public function test_delete_alternate_extensions_reports_a_surviving_stale_variant(): void
    {
        $this->realMedia->put('verband-a.test/logo.webp', 'stale-sibling');

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        // `logo.png` itself is kept, but the `.webp` variant cannot go.
        $this->assertFalse(app(MediaStorage::class)->deleteAlternateExtensions('verband-a.test/logo.png', ['verband-a.test/logo.png']));
        $this->realMedia->assertExists('verband-a.test/logo.webp');
    }

    public function test_delete_alternate_extensions_is_a_success_when_nothing_is_left(): void
    {
        $this->realMedia->put('verband-a.test/logo.png', 'logo-bytes');
        $this->realMedia->put('verband-a.test/logo.webp', 'sibling-bytes');

        $this->assertTrue(app(MediaStorage::class)->deleteAlternateExtensions('verband-a.test/logo.png', ['verband-a.test/logo.png']));
        $this->realMedia->assertExists('verband-a.test/logo.png');
        $this->realMedia->assertMissing('verband-a.test/logo.webp');
    }

    public function test_delete_alternate_extensions_is_a_success_when_no_variant_exists(): void
    {
        $this->assertTrue(app(MediaStorage::class)->deleteAlternateExtensions('verband-a.test/logo.png'));
    }

    /* ---------------------------------------------------------------------
     | Per-service destroy() — the reference may only be dropped on true
     | ------------------------------------------------------------------- */

    public function test_mandant_brand_destroy_keeps_the_column_when_the_file_survives(): void
    {
        $path = $this->seedMandantLogo();

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $this->expectRemovalFailure(
            fn () => app(MandantMediaService::class)->destroy($this->mandant, 'logo'),
            $path,
        );

        $this->assertSame($path, $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    public function test_team_destroy_keeps_the_column_when_the_file_survives(): void
    {
        $team = Team::factory()->create(['mandant_id' => $this->mandant->id, 'slug' => 'verein-a', 'name' => 'Verein A']);
        $path = 'verband-a.test/teams/verein-a/logo.png';
        $this->realMedia->put($path, 'team-logo');
        $team->update(['logo_path' => $path]);

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $this->expectRemovalFailure(
            fn () => app(TeamMediaService::class)->destroy($team),
            $path,
        );

        $this->assertSame($path, $team->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    public function test_event_type_destroy_keeps_the_column_when_the_file_survives(): void
    {
        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);
        $path = 'verband-a.test/event-types/bundesliga/logo.png';
        $this->realMedia->put($path, 'event-type-logo');
        $eventType->update(['logo_path' => $path]);

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $this->expectRemovalFailure(
            fn () => app(EventTypeMediaService::class)->destroy($eventType),
            $path,
        );

        $this->assertSame($path, $eventType->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    public function test_badge_image_destroy_keeps_the_row_when_the_file_survives(): void
    {
        $path = 'verband-a.test/badges/01j0abc.png';
        $this->realMedia->put($path, 'badge-bytes');

        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $this->expectRemovalFailure(
            fn () => app(BadgeImageService::class)->destroy($image),
            $path,
        );

        $this->assertDatabaseHas('badge_images', ['id' => $image->id]);
        $this->realMedia->assertExists($path);
    }

    public function test_user_media_destroy_keeps_the_row_when_the_file_survives(): void
    {
        $user = User::factory()->create();
        $path = "user-media/verband-a/{$user->id}/portrait/alt.jpg";
        $this->realPrivate->put($path, 'portrait-bytes');

        $media = UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => 1024,
            'original_name' => 'alt.jpg',
        ]);

        $this->unremovableDisk(MediaStorage::LEGACY_DISK);

        $this->expectRemovalFailure(
            fn () => app(UserMediaService::class)->destroy($media),
            $path,
        );

        $this->assertDatabaseHas('user_media', ['id' => $media->id]);
        $this->realPrivate->assertExists($path);
    }

    public function test_user_media_destroy_endpoint_surfaces_a_failed_delete_as_a_server_error(): void
    {
        $user = User::factory()->create();
        $path = "user-media/verband-a/{$user->id}/portrait/alt.jpg";
        $this->realPrivate->put($path, 'portrait-bytes');

        $media = UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => 1024,
            'original_name' => 'alt.jpg',
        ]);

        $this->unremovableDisk(MediaStorage::LEGACY_DISK);

        $this->actingAsApi($user)
            ->deleteJson('/api/user/media/'.$media->id)
            ->assertStatus(500);

        // The applicant still sees their photo and still owns the quota slot.
        $this->assertDatabaseHas('user_media', ['id' => $media->id]);
        $this->realPrivate->assertExists($path);
    }

    /* ---------------------------------------------------------------------
     | Idempotency — an already absent file is a success, never a 500
     | ------------------------------------------------------------------- */

    public function test_user_media_destroy_of_an_already_absent_file_still_drops_the_row(): void
    {
        $user = User::factory()->create();

        $media = UserMedia::create([
            'user_id' => $user->id,
            'type' => 'attachment',
            // A row whose file is already gone (out-of-band cleanup, a
            // re-delivered request, or a hand-edited path).
            'path' => "user-media/verband-a/{$user->id}/attachment/gone.jpg",
            'mime' => 'image/jpeg',
            'size' => 1024,
            'original_name' => 'gone.jpg',
        ]);

        $this->assertFalse($this->realPrivate->exists($media->path));

        app(UserMediaService::class)->destroy($media);

        $this->assertDatabaseMissing('user_media', ['id' => $media->id]);
    }

    public function test_mandant_brand_destroy_of_an_already_absent_file_still_clears_the_column(): void
    {
        $path = $this->seedMandantLogo();

        $this->realMedia->delete($path);

        app(MandantMediaService::class)->destroy($this->mandant, 'logo');

        $this->assertNull($this->mandant->fresh()->logo_path);
    }

    public function test_badge_image_destroy_of_an_already_absent_file_still_drops_the_row(): void
    {
        $path = 'verband-a.test/badges/01j0abc.png';

        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        app(BadgeImageService::class)->destroy($image);

        $this->assertDatabaseMissing('badge_images', ['id' => $image->id]);
    }

    /* ---------------------------------------------------------------------
     | Store paths keep working when a superseded file cannot be removed
     | ------------------------------------------------------------------- */

    public function test_store_replaces_the_path_even_when_the_superseded_file_cannot_be_removed(): void
    {
        // The replacement is already written and referenced at that point, so a
        // failed cleanup is logged, not raised: raising would strand the new
        // file unreferenced and keep serving the old image.
        $service = app(MandantMediaService::class);

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.png'));

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.jpg'));

        $this->assertSame('verband-a.test/logo.jpg', $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists('verband-a.test/logo.jpg');
    }

    /* ---------------------------------------------------------------------
     | The entity-delete cascade fails loudly instead of orphaning a file
     | ------------------------------------------------------------------- */

    public function test_mandant_delete_surfaces_a_failed_logo_removal(): void
    {
        $path = $this->seedMandantLogo();

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandant->id)
            ->assertStatus(500);

        // The mandant row survives with its reference, so the still-served file
        // stays reachable instead of becoming an anonymous leftover.
        $this->assertDatabaseHas('mandants', ['id' => $this->mandant->id]);
        $this->assertSame($path, $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    /**
     * Finding 1 — the half-destroyed tenant. The brand logo is the only file
     * that cannot be removed (a read-only bind mount covering that single file);
     * every event-type and badge file of the mandant IS removable.
     *
     * The cascade must abort before it touches a child file, so the whole tenant
     * — rows AND files — survives intact and the operator can retry.
     */
    public function test_mandant_delete_with_a_stuck_brand_file_destroys_no_child_file(): void
    {
        $logoPath = $this->seedMandantLogo();

        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);
        $eventLogo = 'verband-a.test/event-types/bundesliga/logo.png';
        $this->realMedia->put($eventLogo, 'event-type-logo');
        $eventType->update(['logo_path' => $eventLogo]);

        $badgePath = 'verband-a.test/badges/01j0abc.png';
        $this->realMedia->put($badgePath, 'badge-bytes');
        $badge = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $badgePath,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        // Only the brand file is unremovable — the exact shape of the reported
        // trigger (a read-only mount over `<domain>/logo.png`).
        $this->unremovablePathsDisk(MediaStorage::PUBLIC_DISK, [$logoPath]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandant->id)
            ->assertStatus(500);

        // Every row survives …
        $this->assertDatabaseHas('mandants', ['id' => $this->mandant->id]);
        $this->assertDatabaseHas('event_types', ['id' => $eventType->id]);
        $this->assertDatabaseHas('badge_images', ['id' => $badge->id]);
        $this->assertSame($logoPath, $this->mandant->fresh()->logo_path);
        $this->assertSame($eventLogo, $eventType->fresh()->logo_path);

        // … and, crucially, every file is still there: no reference was left
        // dangling at a still-served path. Pre-fix the event-type and badge
        // files were already unlinked at this point, so the live tenant served
        // broken images and every retry 500'd on the same stuck logo.
        $this->realMedia->assertExists($logoPath);
        $this->realMedia->assertExists($eventLogo);
        $this->realMedia->assertExists($badgePath);
    }

    /**
     * The same trigger, seen from the retry side: the stuck brand file makes the
     * delete fail, but it must not consume the child files either. A retry after
     * the volume is writable completes and leaves nothing behind.
     */
    public function test_mandant_delete_converges_once_the_stuck_brand_file_is_gone(): void
    {
        $logoPath = $this->seedMandantLogo();

        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);
        $eventLogo = 'verband-a.test/event-types/bundesliga/logo.png';
        $this->realMedia->put($eventLogo, 'event-type-logo');
        $eventType->update(['logo_path' => $eventLogo]);

        $this->unremovablePathsDisk(MediaStorage::PUBLIC_DISK, [$logoPath]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandant->id)
            ->assertStatus(500);

        // The mount is gone: every delete really happens again — what a retry in
        // a fixed deployment sees.
        $this->unremovablePaths = [];

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandant->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('mandants', ['id' => $this->mandant->id]);
        $this->assertDatabaseMissing('event_types', ['id' => $eventType->id]);
        $this->realMedia->assertMissing($eventLogo);
    }

    /* ---------------------------------------------------------------------
     | Finding 4 — the column is rewritten BEFORE the best-effort cleanup, so a
     | logged leftover really is an unreferenced orphan
     | ------------------------------------------------------------------- */

    public function test_mandant_store_rewrites_the_column_before_the_best_effort_cleanup(): void
    {
        $service = app(MandantMediaService::class);
        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.png'));

        $previous = (string) $this->mandant->fresh()->logo_path;

        // The predecessor cannot go, so the cleanup pass runs to the end.
        $this->unremovablePathsDisk(MediaStorage::PUBLIC_DISK, [$previous]);

        $columnWhenCleaning = [];
        $this->onDeleteAttempt = function () use (&$columnWhenCleaning): void {
            $columnWhenCleaning[] = $this->mandant->fresh()->logo_path;
        };

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.jpg'));

        // Every delete attempt of the cleanup pass already saw the NEW path in
        // the column: the write precedes the cleanup, so a leftover it could
        // not remove is genuinely unreferenced (the documented rationale).
        // Pre-fix the first attempt still saw the old path.
        $this->assertNotSame([], $columnWhenCleaning);
        $this->assertSame(
            ['verband-a.test/logo.jpg'],
            array_values(array_unique($columnWhenCleaning)),
        );
        $this->assertSame('verband-a.test/logo.jpg', $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists($previous);
    }

    public function test_team_store_rewrites_the_column_before_the_best_effort_cleanup(): void
    {
        $service = app(TeamMediaService::class);
        $team = Team::factory()->create(['mandant_id' => $this->mandant->id, 'slug' => 'verein-a', 'name' => 'Verein A']);

        $service->store($team, UploadedFile::fake()->image('logo.png'));

        $previous = (string) $team->fresh()->logo_path;

        $this->unremovablePathsDisk(MediaStorage::PUBLIC_DISK, [$previous]);

        $columnWhenCleaning = [];
        $this->onDeleteAttempt = function () use (&$columnWhenCleaning, $team): void {
            $columnWhenCleaning[] = $team->fresh()->logo_path;
        };

        $service->store($team, UploadedFile::fake()->image('logo.jpg'));

        $this->assertNotSame([], $columnWhenCleaning);
        $this->assertSame(
            ['verband-a.test/teams/verein-a/logo.jpg'],
            array_values(array_unique($columnWhenCleaning)),
        );
        $this->assertSame('verband-a.test/teams/verein-a/logo.jpg', $team->fresh()->logo_path);
        $this->realMedia->assertExists($previous);
    }

    /* ---------------------------------------------------------------------
     | Finding 2 — the leftover message must not promise a self-healing that
     | does not exist
     | ------------------------------------------------------------------- */

    /**
     * A stuck pre-W6 legacy file lives on the `private` disk in a layout
     * `media:prune-orphans` never scans (see `MediaPruneOrphansTest`). The log
     * message therefore has to name the manual step — an operator who is told
     * "the reaper collects it" will never clean it up.
     */
    public function test_a_stuck_legacy_leftover_is_logged_as_manual_work(): void
    {
        $this->seedMandantLogo();
        $this->realPrivate->put('mandants/verband-a/logo.png', 'legacy-logo');

        // Only the legacy file is unremovable.
        $this->unremovablePathsDisk(MediaStorage::LEGACY_DISK, ['mandants/verband-a/logo.png']);

        Log::spy();

        app(MandantMediaService::class)->destroy($this->mandant, 'logo');

        // The referenced file was removable, so the column is cleared; the
        // legacy leftover is not and says so.
        $this->assertNull($this->mandant->fresh()->logo_path);
        $this->realPrivate->assertExists('mandants/verband-a/logo.png');

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'delete it manually')
                && ($context['path'] ?? '') === 'mandants/verband-a/logo.<ext>'
                && ($context['context'] ?? '') === sprintf('mandant#%d logo (pre-W6 legacy)', $this->mandant->id),
        );
    }

    /**
     * The managed counterpart keeps the short promise: a leftover in the layout
     * the reaper scans really is collected by `media:prune-orphans`.
     */
    public function test_a_stuck_managed_leftover_is_logged_as_reapable(): void
    {
        $service = app(MandantMediaService::class);
        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.png'));

        $previous = (string) $this->mandant->fresh()->logo_path;

        // The predecessor of the new upload is the one thing that cannot go.
        $this->unremovablePathsDisk(MediaStorage::PUBLIC_DISK, [$previous]);

        Log::spy();

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.jpg'));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, '`media:prune-orphans` reaps it')
                && ($context['path'] ?? '') === $previous
                && ($context['context'] ?? '') === sprintf('mandant#%d logo', $this->mandant->id),
        );
    }

    /* ---------------------------------------------------------------------
     | Prune command — a failed delete must not look like success
     | ------------------------------------------------------------------- */

    public function test_prune_orphans_reports_a_failed_delete_and_exits_non_zero(): void
    {
        $orphan = 'verband-a.test/teams/gone/logo.png';
        $this->realMedia->put($orphan, 'orphan-bytes');

        $this->unremovableDisk(MediaStorage::PUBLIC_DISK);

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('could not delete orphan: '.$orphan, $output);
        $this->assertStringNotContainsString('Deleted 1 orphaned media file', $output);
        $this->realMedia->assertExists($orphan);
    }

    public function test_migrate_command_reports_a_legacy_source_it_could_not_remove(): void
    {
        $this->realPrivate->put('mandants/verband-a/logo.png', 'legacy-logo');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        // The legacy source lives on the `private` disk, so THAT is the disk
        // whose unlink must fail.
        $this->unremovableDisk(MediaStorage::LEGACY_DISK);

        $exitCode = Artisan::call('media:migrate-to-domain-layout', ['--force' => true]);
        $output = Artisan::output();

        // The migration itself succeeded (the new file is written, the DB
        // points at it) — only the legacy source is left behind.
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('migrated mandant#'.$this->mandant->id.' logo', $output);
        $this->assertStringContainsString('the legacy source could not be removed', $output);
        $this->assertSame('verband-a.test/logo.png', $this->mandant->fresh()->logo_path);
        $this->realPrivate->assertExists('mandants/verband-a/logo.png');
    }

    /* ---------------------------------------------------------------------
     | Post-condition BEFORE the destructive act: a stuck sibling variant
     | ------------------------------------------------------------------- */

    /**
     * Finding 3 — a directory named `logo.webp` can never be unlinked
     * (`delete()` on a directory always reports failure), while `logo.png` is
     * perfectly removable. Removing the current file first deleted the only
     * served variant and only THEN reported the failure, so the column survived
     * pointing at a deleted file and every retry 500'd on the same directory.
     */
    public function test_mandant_brand_destroy_keeps_the_file_when_a_sibling_variant_is_stuck(): void
    {
        $path = $this->seedMandantLogo();

        // `logo.png` is removable, the sibling variant is not.
        $this->realMedia->makeDirectory('verband-a.test/logo.webp');
        $this->assertFalse($this->realMedia->delete('verband-a.test/logo.webp'));

        $this->expectRemovalFailure(
            fn () => app(MandantMediaService::class)->destroy($this->mandant, 'logo'),
            $path,
        );

        // The referenced file survives, so the tenant keeps serving its logo
        // instead of a 404 at a path the DB still points at.
        $this->assertSame($path, $this->mandant->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    public function test_team_destroy_keeps_the_file_when_a_sibling_variant_is_stuck(): void
    {
        $team = Team::factory()->create(['mandant_id' => $this->mandant->id, 'slug' => 'verein-a', 'name' => 'Verein A']);
        $path = 'verband-a.test/teams/verein-a/logo.png';
        $this->realMedia->put($path, 'team-logo');
        $team->update(['logo_path' => $path]);

        $this->realMedia->makeDirectory('verband-a.test/teams/verein-a/logo.webp');

        $this->expectRemovalFailure(
            fn () => app(TeamMediaService::class)->destroy($team),
            $path,
        );

        $this->assertSame($path, $team->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    public function test_event_type_destroy_keeps_the_file_when_a_sibling_variant_is_stuck(): void
    {
        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);
        $path = 'verband-a.test/event-types/bundesliga/logo.png';
        $this->realMedia->put($path, 'event-type-logo');
        $eventType->update(['logo_path' => $path]);

        $this->realMedia->makeDirectory('verband-a.test/event-types/bundesliga/logo.webp');

        $this->expectRemovalFailure(
            fn () => app(EventTypeMediaService::class)->destroy($eventType),
            $path,
        );

        $this->assertSame($path, $eventType->fresh()->logo_path);
        $this->realMedia->assertExists($path);
    }

    public function test_badge_image_destroy_keeps_the_file_when_a_sibling_variant_is_stuck(): void
    {
        $path = 'verband-a.test/badges/01j0abc.png';
        $this->realMedia->put($path, 'badge-bytes');

        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'wappen.png',
        ]);

        $this->realMedia->makeDirectory('verband-a.test/badges/01j0abc.webp');

        $this->expectRemovalFailure(
            fn () => app(BadgeImageService::class)->destroy($image),
            $path,
        );

        $this->assertDatabaseHas('badge_images', ['id' => $image->id]);
        $this->realMedia->assertExists($path);
    }

    /**
     * The happy path is unchanged: with no stuck variant, the current file AND
     * its `.webp` sibling are gone and the reference is dropped.
     */
    public function test_mandant_brand_destroy_removes_the_file_and_the_sibling_variant(): void
    {
        $path = $this->seedMandantLogo();
        $this->realMedia->put('verband-a.test/logo.webp', 'sibling-bytes');

        app(MandantMediaService::class)->destroy($this->mandant, 'logo');

        $this->assertNull($this->mandant->fresh()->logo_path);
        $this->realMedia->assertMissing($path);
        $this->realMedia->assertMissing('verband-a.test/logo.webp');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function seedMandantLogo(): string
    {
        $path = 'verband-a.test/logo.png';
        $this->realMedia->put($path, 'logo-bytes');
        $this->mandant->update(['logo_path' => $path]);

        return $path;
    }

    /**
     * Replace the disk named `$disk` with one whose `delete()` fails for exactly
     * the paths in `$this->unremovablePaths` and really removes every other one.
     * That is the partial-failure shape a read-only bind mount over a single
     * file produces: the brand logo is stuck, the child media is writable.
     *
     * The block list is a property on purpose: `Storage::shouldReceive()` reuses
     * an already mocked facade, so a second stub would NOT replace the first one
     * — a test that simulates "the mount is gone" empties the list instead.
     *
     * `$this->onDeleteAttempt` (a `callable(string): void`) is invoked for every
     * delete attempt, which lets a test observe DB state at exactly that point
     * in time.
     *
     * @param  list<string>  $paths
     */
    private function unremovablePathsDisk(string $disk, array $paths): void
    {
        $this->unremovablePaths = $paths;

        $real = $disk === MediaStorage::PUBLIC_DISK ? $this->realMedia : $this->realPrivate;
        $other = $disk === MediaStorage::PUBLIC_DISK ? $this->realPrivate : $this->realMedia;

        $partial = Mockery::mock(Filesystem::class);
        $partial->shouldReceive('delete')->andReturnUsing(
            function ($path) use ($real): bool {
                if (is_string($path) && $this->onDeleteAttempt !== null) {
                    ($this->onDeleteAttempt)($path);
                }

                if (is_string($path) && in_array($path, $this->unremovablePaths, true)) {
                    return false;
                }

                return (array) $real->delete((array) $path) !== [];
            },
        );
        $partial->shouldReceive('exists')->andReturnUsing(
            fn (string $path): bool => $real->exists($path),
        );
        $partial->shouldReceive('allFiles')->andReturnUsing(
            fn (): array => $real->allFiles(),
        );
        $partial->shouldReceive('getDriver')->andReturnUsing(
            fn () => $real->getDriver(),
        );
        $partial->shouldReceive('put')->andReturnUsing(
            fn (string $path, $contents): bool => $real->put($path, $contents),
        );
        $partial->shouldReceive('get')->andReturnUsing(
            fn (string $path): string => $real->get($path),
        );
        $partial->shouldReceive('putFileAs')->andReturnUsing(
            fn (string $directory, $file, string $name): string|false => $real->putFileAs($directory, $file, $name),
        );

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === $disk ? $partial : $other,
        );
    }

    /**
     * Replace the disk named `$disk` with one whose `delete()` is a no-op
     * reporting failure — a read-only volume. Everything else (`exists`,
     * `allFiles`, `getDriver`, `put`, `putFileAs`) keeps answering from the
     * real (faked) disk, so every "the file is still there" assertion stays
     * honest and an upload can still write through the same wrapper.
     * `getDriver` is what the prune command's lazy directory walk goes
     * through (WP-10-a replaced the buffered `allFiles()` listing).
     */
    private function unremovableDisk(string $disk): void
    {
        $real = $disk === MediaStorage::PUBLIC_DISK ? $this->realMedia : $this->realPrivate;
        $other = $disk === MediaStorage::PUBLIC_DISK ? $this->realPrivate : $this->realMedia;

        $unremovable = Mockery::mock(Filesystem::class);
        $unremovable->shouldReceive('delete')->andReturn(false);
        $unremovable->shouldReceive('exists')->andReturnUsing(
            fn (string $path): bool => $real->exists($path),
        );
        $unremovable->shouldReceive('allFiles')->andReturnUsing(
            fn (): array => $real->allFiles(),
        );
        $unremovable->shouldReceive('getDriver')->andReturnUsing(
            fn () => $real->getDriver(),
        );
        $unremovable->shouldReceive('put')->andReturnUsing(
            fn (string $path, $contents): bool => $real->put($path, $contents),
        );
        $unremovable->shouldReceive('get')->andReturnUsing(
            fn (string $path): string => $real->get($path),
        );
        $unremovable->shouldReceive('putFileAs')->andReturnUsing(
            fn (string $directory, $file, $name): string|false => $real->putFileAs($directory, $file, $name),
        );

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === $disk ? $unremovable : $other,
        );
    }

    /**
     * Assert the call aborted with a `RuntimeException` naming the file that
     * could not be removed. The message marker keeps an incidental exception
     * (e.g. from a mocked disk) from passing as the expected failure.
     */
    private function expectRemovalFailure(callable $callback, string $path): void
    {
        try {
            $callback();
            $this->fail('A failed delete must abort with a RuntimeException instead of dropping the reference.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Could not remove', $exception->getMessage());
            $this->assertStringContainsString($path, $exception->getMessage());
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }
}

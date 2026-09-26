<?php

namespace Tests\Feature;

use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

        Artisan::call('media:prune-orphans', ['--min-age' => 0]);
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

        $this->reap();

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/gone/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/gone/logo.webp');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');
        Storage::disk(MediaPathService::DISK)->assertExists('logo.svg');
    }

    public function test_force_is_idempotent(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/teams/gone/logo.png', $this->pngBytes());

        $this->reap();
        $this->reap();

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

        $this->reap();

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

        $this->reap();

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
        // under it, so the walk never yields the file.
        Storage::disk(MediaPathService::DISK)->assertMissing($legacy);

        $exitCode = $this->reap();
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

        $this->reap();

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

        $this->reap();

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

        $exitCode = $this->reap();

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
            // W1 admits exactly `<root>/<slug>/<raster leaf>` and nothing else,
            // so a root of the same name under a different shape stays off
            // limits. (`teams/badges/01j0abc.png` is deliberately NOT here: a
            // team whose slug is `badges` writes exactly that, so the shape is
            // indistinguishable from the real thing — and harmless, since the
            // reference set still decides.)
            'a traversal slug below the teams root' => ['teams/../badges/01j0abc.png'],
            'an svg leaf below the teams root' => ['teams/verein-a/logo.svg'],
            'a nested file below the teams root' => ['teams/verein-a/nested/logo.png'],
            'a brand leaf directly below the event-types root' => ['event-types/logo.png'],
            'a single-label root next to a W1 one' => ['sonstiges/verein-a/logo.png'],
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

        $this->reap();

        Storage::disk(MediaPathService::DISK)->assertMissing($path);
    }

    /**
     * A dry run must not even mention an unknown root: the operator reads the
     * list as "files this command would delete", so an entry there is already
     * a wrong promise.
     */
    public function test_the_dry_run_does_not_list_an_unknown_root(): void
    {
        Storage::disk(MediaPathService::DISK)->put('not-a-host/badges/01j0abc.png', $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--min-age' => 0]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('not-a-host', $output);
        $this->assertStringContainsString('No orphaned media files', $output);
        Storage::disk(MediaPathService::DISK)->assertExists('not-a-host/badges/01j0abc.png');
    }

    /* ---------------------------------------------------------------------
     | W1 — the host-less `teams/<slug>/…` / `event-types/<slug>/…` layout
     | ------------------------------------------------------------------- */

    /**
     * W1: those roots are single-label, so the `<domain>/` check (which
     * requires a dot) rejected every one of them and the reaper never reached a
     * file an upload of that era had written. A leftover there was therefore
     * uncollectable: a file only a human removed, in a directory that grows
     * with every team of every mandant.
     */
    #[DataProvider('w1PathProvider')]
    public function test_a_host_less_w1_file_is_managed(string $path): void
    {
        Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

        $exitCode = $this->reap();

        $this->assertSame(0, $exitCode);
        Storage::disk(MediaPathService::DISK)->assertMissing($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function w1PathProvider(): array
    {
        return [
            'a team logo' => ['teams/verein-a/logo.png'],
            'a team logo as webp' => ['teams/verein-a/logo.webp'],
            'an event-type logo' => ['event-types/bundesliga/logo.png'],
            'a team file below an underscore slug' => ['teams/verein_a/logo.png'],
        ];
    }

    /**
     * The reference set still decides below a W1 root: a row that (after a
     * half-finished migration) still points at a `teams/<slug>/…` path keeps its
     * file exactly as long as the row exists.
     */
    public function test_a_referenced_host_less_w1_file_is_kept(): void
    {
        $path = 'teams/verein-a/logo.png';
        Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

        $team = Team::factory()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'verein-a',
            'name' => 'Verein A',
        ]);
        $team->update(['logo_path' => $path]);

        $this->reap();

        Storage::disk(MediaPathService::DISK)->assertExists($path);
    }

    /* ---------------------------------------------------------------------
     | W2 — the recency guard: an upload writes the file before the row points
     | at it
     | ------------------------------------------------------------------- */

    /**
     * The finding: the walk compared the disk against the reference set, and
     * the two are not one instant. An upload writes the file first and stores
     * the path second, so for a moment an unreferenced file is a REQUEST IN
     * FLIGHT, not an orphan — and the reaper deleted it. The row then served a
     * 404 out of a healthy `logo_path` until someone noticed.
     *
     * Both directions are asserted in one test because they are one rule: a
     * file younger than `--min-age` is left alone, and the run says so instead
     * of silently finding nothing. Pre-fix the first file was deleted, so the
     * method fails.
     */
    public function test_only_files_older_than_the_min_age_are_reaped(): void
    {
        $fresh = 'verband-a.test/teams/frisch/logo.png';
        $old = 'verband-a.test/teams/alt/logo.png';

        Storage::disk(MediaPathService::DISK)->put($fresh, $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put($old, $this->pngBytes());

        // Same layout, same run — only the mtime differs: the old file is
        // backdated well beyond the threshold, the fresh one keeps its real
        // age of a few milliseconds.
        $this->ageFile($old, 3600);

        $exitCode = $this->reap(options: ['--min-age' => 300]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // The in-flight file survives…
        Storage::disk(MediaPathService::DISK)->assertExists($fresh);
        // …and the genuinely old orphan is still collected, so the guard is a
        // grace period and not a silent no-op.
        Storage::disk(MediaPathService::DISK)->assertMissing($old);

        $this->assertStringContainsString('1 file(s) younger than 300 s were left alone', $output);
        $this->assertStringNotContainsString($fresh, $output);
    }

    /**
     * The dry run honours the guard too — and it must, because its list is read
     * as "files `--force` would delete". A fresh file that the force run would
     * spare must not appear there.
     */
    public function test_the_dry_run_does_not_list_a_file_younger_than_the_min_age(): void
    {
        $fresh = 'verband-a.test/teams/frisch/logo.png';
        Storage::disk(MediaPathService::DISK)->put($fresh, $this->pngBytes());

        Artisan::call('media:prune-orphans', ['--min-age' => 300]);
        $output = Artisan::output();

        $this->assertStringNotContainsString($fresh, $output);
        $this->assertStringContainsString('1 file(s) younger than 300 s were left alone', $output);
        Storage::disk(MediaPathService::DISK)->assertExists($fresh);
    }

    /**
     * `--min-age=0` is the documented escape hatch for a deliberate one-off
     * sweep, and the tests below rely on it: it has to disable the guard
     * completely, not merely shorten it.
     */
    public function test_a_min_age_of_zero_reaps_a_file_written_seconds_ago(): void
    {
        $path = 'verband-a.test/teams/frisch/logo.png';
        Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

        $this->reap();

        Storage::disk(MediaPathService::DISK)->assertMissing($path);
    }

    /* ---------------------------------------------------------------------
     | W5 — the legacy pass walks the two legacy roots, not the disk root
     | ------------------------------------------------------------------- */

    /**
     * The finding: `--include-legacy` walked `listContents('', true)` on the
     * `private` disk, i.e. EVERYTHING below it — including `user-media/**`,
     * the live person-image store that the legacy allowlist then rejected one
     * file at a time. On a real installation that is every portrait, press ID
     * and attachment of every mandant, enumerated and discarded, on a command
     * whose entire scope is two directories.
     *
     * The recording driver is the assertion: the two legacy roots are walked,
     * and the disk root is never requested.
     */
    public function test_the_legacy_pass_walks_only_the_two_legacy_roots(): void
    {
        Storage::disk(MediaPathService::DISK);
        $private = Storage::disk(MediaStorage::LEGACY_DISK);

        $private->put('mandants/verband-a/logo.jpg', $this->pngBytes());
        $private->put('badge-images/verband-a/01j0abc.png', $this->pngBytes());
        // Live private data that must not even be enumerated.
        $private->put('user-media/verband-a/'.$this->mandant->id.'/portrait/portrait.jpg', $this->pngBytes());

        $walked = [];

        // A driver that records every requested location and delegates the
        // actual listing to the real one, so the scope of the walk is asserted
        // without faking the file set itself.
        $driver = new class($private->getDriver())
        {
            /** @var list<string> */
            public array $walked = [];

            public function __construct(private readonly object $real) {}

            public function listContents(string $location = '', bool $deep = false): iterable
            {
                $this->walked[] = $location;

                return $this->real->listContents($location, $deep);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->real->{$method}(...$arguments);
            }
        };

        $recording = Mockery::mock(Filesystem::class);
        $recording->shouldReceive('getDriver')->andReturn($driver);
        $recording->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => $private->exists($path));
        $recording->shouldReceive('delete')->andReturnUsing(fn (...$paths) => $private->delete(...$paths));

        $media = Storage::disk(MediaPathService::DISK);

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaStorage::LEGACY_DISK ? $recording : $media,
        );

        $exitCode = $this->reap(options: ['--include-legacy' => true]);

        $this->assertSame(0, $exitCode);

        // The two legacy roots, and NOTHING else: no `''` (the whole disk), and
        // no `user-media` (out of scope by definition).
        $this->assertSame(['mandants', 'badge-images'], $driver->walked);
        $this->assertNotContains('', $driver->walked);
        $this->assertNotContains('user-media', $driver->walked);

        $private->assertMissing('mandants/verband-a/logo.jpg');
        $private->assertMissing('badge-images/verband-a/01j0abc.png');
        $private->assertExists('user-media/verband-a/'.$this->mandant->id.'/portrait/portrait.jpg');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Run the reaper with the recency guard switched off.
     *
     * `--min-age` defaults to 300 s precisely because the scenarios in this
     * class contradict it: they create a file and reap it in the same
     * millisecond, which is a grace period for an in-flight upload in
     * production and a bug in a test. The guard itself is covered by
     * `test_only_files_older_than_the_min_age_are_reaped()`.
     *
     * @param  array<string, mixed>  $options
     */
    private function reap(bool $force = true, array $options = []): int
    {
        $options['--min-age'] ??= 0;

        if ($force) {
            $options['--force'] = true;
        }

        return Artisan::call('media:prune-orphans', $options);
    }

    /**
     * Backdate the mtime of a written file, the only way to hand the reaper a
     * file that is "old" without waiting five minutes. Written against the real
     * disk root instead of through the adapter because Flysystem has no API to
     * set a timestamp.
     */
    private function ageFile(string $path, int $seconds): void
    {
        $absolute = Storage::disk(MediaPathService::DISK)->path($path);
        $mtime = time() - $seconds;

        touch($absolute, $mtime);
        clearstatcache(true, $absolute);

        $this->assertSame($mtime, filemtime($absolute), 'Das Backdating selbst ist die Voraussetzung dieses Tests.');
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

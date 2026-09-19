<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * W11 — dual-mode accel delivery and the B1–B10 matrix.
 *
 * With `media.accel_prefix` unset/empty the backend keeps streaming through
 * `Storage::response` (safe default, risk R1). With a configured prefix a
 * public media request is answered by an empty 200 + `X-Accel-Redirect`, and
 * Caddy serves the file from MEDIA_ROOT. Legacy/private and host-neutral
 * (`_tenants/`) files must NEVER receive the header — Caddy cannot reach them.
 */
class MediaAccelRedirectTest extends TestCase
{
    use RefreshDatabase;

    private MediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStorage::PUBLIC_DISK);
        Storage::fake(MediaStorage::LEGACY_DISK);

        $this->storage = app(MediaStorage::class);

        // Safe default: the accel mode is off unless a test opts in.
        config(['media.accel_prefix' => '']);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | B1 — dual mode
     | ------------------------------------------------------------------- */

    public function test_b1_disabled_mode_streams_the_original(): void
    {
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png', 'image/webp');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
    }

    public function test_b2_enabled_mode_answers_empty_200_with_accel_header(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('/__media/verband-a.test/logo.png', $response->headers->get('X-Accel-Redirect'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function test_b3_prefix_is_normalised_to_a_single_leading_and_no_trailing_slash(): void
    {
        config(['media.accel_prefix' => '__media/']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png');

        $this->assertSame('/__media/verband-a.test/logo.png', $response->headers->get('X-Accel-Redirect'));
    }

    /* ---------------------------------------------------------------------
     | B4/B5 — W7 cache classes
     | ------------------------------------------------------------------- */

    public function test_b4_badge_paths_are_immutable(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/badges/01j0abc.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/badges/01j0abc.png');

        $this->assertSame('immutable, max-age=31536000, public', $response->headers->get('Cache-Control'));
    }

    public function test_b5_fixed_name_images_revalidate(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png');

        // Symfony's ResponseHeaderBag ksort()s the directives; semantically
        // this is `public, max-age=3600, must-revalidate`.
        $this->assertSame('max-age=3600, must-revalidate, public', $response->headers->get('Cache-Control'));
    }

    /* ---------------------------------------------------------------------
     | B6/B7 — Accept variant selection
     | ------------------------------------------------------------------- */

    public function test_b6_webp_sibling_is_preferred_when_the_client_accepts_it(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.webp', $this->webpBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png', 'image/avif,image/webp,*/*');

        $this->assertSame('/__media/verband-a.test/logo.webp', $response->headers->get('X-Accel-Redirect'));
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        // Canonical URLs are not a shared content-negotiated cache: no Vary.
        $this->assertFalse($response->headers->has('Vary'));
    }

    public function test_b7_original_is_served_without_a_webp_accept(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.webp', $this->webpBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png', 'image/png');

        $this->assertSame('/__media/verband-a.test/logo.png', $response->headers->get('X-Accel-Redirect'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    /* ---------------------------------------------------------------------
     | B8/B9/B10 — negative guards
     | ------------------------------------------------------------------- */

    public function test_b8_host_neutral_paths_never_get_the_header(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('_tenants/5/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('_tenants/5/logo.png');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
    }

    public function test_b9_legacy_private_only_files_never_get_the_header(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaStorage::LEGACY_DISK)->put('mandants/verband-a/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('mandants/verband-a/logo.png');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
    }

    public function test_b10_traversal_paths_never_get_the_header(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());

        // Flysystem normalises the dotted path, so the stream branch still finds
        // the file; the header guard must reject it before Caddy ever sees it.
        $response = $this->storage->accelResponse('verband-a.test/x/../logo.png');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
    }

    /* ---------------------------------------------------------------------
     | B11/B12 — positive allowlist matrix (W11-F1)
     | ------------------------------------------------------------------- */

    public function test_b11_positive_allowlist_covers_every_public_layout_shape(): void
    {
        config(['media.accel_prefix' => '/__media']);

        $paths = [
            'verband-a.test/logo.png',
            'verband-a.test/logo.webp',
            'verband-a.test/header.jpg',
            'verband-a.test/teams/team-a/logo.jpeg',
            'verband-a.test/event-types/bundesliga/logo.webp',
            'verband-a.test/badges/01j0abc.webp',
        ];

        foreach ($paths as $path) {
            Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

            $response = $this->storage->accelResponse($path);

            $this->assertSame(
                '/__media/'.$path,
                $response->headers->get('X-Accel-Redirect'),
                sprintf('expected accel delivery for %s', $path),
            );
        }
    }

    public function test_b12_unknown_shapes_legacy_roots_and_control_chars_stream(): void
    {
        config(['media.accel_prefix' => '/__media']);

        $paths = [
            'logo.png',                                     // no host segment
            'verband-a.test/favicon.ico',                   // unknown brand leaf
            'verband-a.test/logo.svg',                      // non-raster brand leaf
            'verband-a.test/misc/01j0abc.png',              // unknown segment
            'verband-a.test/teams/team-a/notes.txt',        // non-image leaf
            'verband-a.test/teams/team-a/nested/logo.png',  // too deep
            'verband-a.test/teams/Team-A/logo.png',         // non-canonical slug
            '_tenants/5/logo.png',                          // host-neutral
            'mandants/logo.png',                            // legacy single-label root
            'badge-images/logo.png',                        // legacy single-label root
            'user-media/logo.png',                          // legacy single-label root
        ];

        foreach ($paths as $path) {
            Storage::disk(MediaPathService::DISK)->put($path, $this->pngBytes());

            $response = $this->storage->accelResponse($path);

            $this->assertInstanceOf(StreamedResponse::class, $response);
            $this->assertFalse(
                $response->headers->has('X-Accel-Redirect'),
                sprintf('unexpected accel delivery for %s', $path),
            );
        }
    }

    /**
     * A control character (CR/LF/NUL/TAB) can never be observed through the
     * stream fallback because Flysystem itself rejects such a path as corrupted
     * (`\p{C}`). The guard that keeps a smuggled CR/LF out of the accel header
     * is therefore asserted directly.
     */
    public function test_b12b_control_characters_never_pass_the_eligibility_guard(): void
    {
        $eligible = new \ReflectionMethod($this->storage, 'isAccelEligible');

        foreach ([
            "verband-a.test/logo.png\r\nX-Evil: 1",
            "verband-a.test/logo\n.png",
            "verband-a.test/logo\t.png",
            "verband-a.test/logo\0.png",
        ] as $path) {
            $this->assertFalse(
                $eligible->invoke($this->storage, $path),
                sprintf('control characters must not be accel-eligible: %s', addcslashes($path, "\0..\37")),
            );
        }
    }

    /**
     * W11-F2: a brand leaf only passes the allowlist when the file actually
     * sits on the public `media` disk. Caddy serves from MEDIA_ROOT, so a path
     * that only exists on the legacy `private` disk (or nowhere) must stream
     * through PHP instead of yielding a bodyless 200.
     */
    public function test_b13_brand_leaf_without_a_public_file_streams(): void
    {
        config(['media.accel_prefix' => '/__media']);

        Storage::disk(MediaStorage::LEGACY_DISK)->put('verband-a.test/logo.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/logo.png');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));

        // The pattern matches but the file is missing entirely: the eligibility
        // guard must fail closed (asserted directly — the stream branch cannot
        // represent a file that exists on neither disk).
        $eligible = new \ReflectionMethod($this->storage, 'isAccelEligible');

        $this->assertFalse($eligible->invoke($this->storage, 'verband-a.test/header.jpg'));
    }

    /* ---------------------------------------------------------------------
     | HTTP wiring / isolation / 404 semantics
     | ------------------------------------------------------------------- */

    public function test_public_portal_delivery_uses_the_accel_header_when_enabled(): void
    {
        config(['media.accel_prefix' => '/__media']);

        $mandant = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandant->domains()->create(['hostname' => 'verband-a.test']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());
        $mandant->update(['logo_path' => 'verband-a.test/logo.png']);
        MandantContext::set($mandant);

        $this->get('/api/portal/mandant/logo')
            ->assertOk()
            ->assertHeader('X-Accel-Redirect', '/__media/verband-a.test/logo.png')
            ->assertHeader('Cache-Control', 'max-age=3600, must-revalidate, public');
    }

    public function test_public_portal_delivery_is_isolated_between_mandants(): void
    {
        config(['media.accel_prefix' => '/__media']);

        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantA->domains()->create(['hostname' => 'verband-a.test']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/logo.png', $this->pngBytes());
        $mandantA->update(['logo_path' => 'verband-a.test/logo.png']);

        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);
        $mandantB->domains()->create(['hostname' => 'verband-b.test']);
        Storage::disk(MediaPathService::DISK)->put('verband-b.test/logo.png', $this->pngBytes());
        $mandantB->update(['logo_path' => 'verband-b.test/logo.png']);

        MandantContext::set($mandantB);
        $this->get('/api/portal/mandant/logo')
            ->assertOk()
            ->assertHeader('X-Accel-Redirect', '/__media/verband-b.test/logo.png');
    }

    public function test_public_portal_delivery_is_404_without_a_file(): void
    {
        config(['media.accel_prefix' => '/__media']);

        $mandant = Mandant::factory()->create(['slug' => 'verband-a']);
        MandantContext::set($mandant);

        $this->getJson('/api/portal/mandant/logo')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Kein Bild hinterlegt.');
    }

    public function test_badge_delivery_keeps_the_original_filename_as_inline_disposition(): void
    {
        config(['media.accel_prefix' => '/__media']);
        Storage::disk(MediaPathService::DISK)->put('verband-a.test/badges/01j0abc.png', $this->pngBytes());

        $response = $this->storage->accelResponse('verband-a.test/badges/01j0abc.png', '', 'Wappen.png');

        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Wappen.png', (string) $response->headers->get('Content-Disposition'));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function pngBytes(int $width = 8, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function webpBytes(int $width = 8, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }
}

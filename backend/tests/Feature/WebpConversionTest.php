<?php

namespace Tests\Feature;

use App\Enums\MediaType;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Team;
use App\Models\User;
use App\Services\BadgeImageService;
use App\Services\EventTypeMediaService;
use App\Services\MandantMediaService;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use App\Services\TeamMediaService;
use App\Services\UserMediaService;
use App\Services\WebpConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * W11 — synchronous WebP derivatives.
 *
 * The original upload stays authoritative; a `.webp` sibling is written next to
 * it (extension swap). The converter must preserve PNG alpha, apply JPEG EXIF
 * orientation and reject animated WebP. A replace that changes the extension
 * must not leave stale variants behind.
 */
class WebpConversionTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private WebpConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaPathService::DISK);
        Storage::fake(MediaStorage::LEGACY_DISK);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        $this->converter = app(WebpConverter::class);
    }

    /* ---------------------------------------------------------------------
     | Converter — alpha, EXIF, formats, animated
     | ------------------------------------------------------------------- */

    public function test_converter_preserves_png_alpha(): void
    {
        $image = imagecreatetruecolor(10, 10);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagefilledrectangle($image, 0, 0, 4, 9, imagecolorallocate($image, 255, 0, 0));

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $decoded = imagecreatefromstring($this->converter->convertContents($png, WebpConverter::PRESET_LOGO));

        $this->assertNotFalse($decoded);
        $transparentPixel = imagecolorsforindex($decoded, imagecolorat($decoded, 9, 9));
        $opaquePixel = imagecolorsforindex($decoded, imagecolorat($decoded, 1, 9));

        $this->assertSame(127, $transparentPixel['alpha'], 'transparent PNG pixel must stay transparent in WebP');
        $this->assertSame(255, $opaquePixel['red']);
    }

    public function test_converter_applies_exif_orientation(): void
    {
        // Orientation 6 = "rotate 90 CW": a 40×20 source must come out 20×40.
        $rotated = $this->converter->convertContents($this->jpegWithOrientation(6, 40, 20), WebpConverter::PRESET_PHOTO);
        $rotatedInfo = getimagesizefromstring($rotated);

        $this->assertSame([20, 40], [$rotatedInfo[0], $rotatedInfo[1]]);

        // Orientation 1 (normal) keeps the dimensions untouched.
        $normal = $this->converter->convertContents($this->jpegWithOrientation(1, 40, 20), WebpConverter::PRESET_PHOTO);
        $normalInfo = getimagesizefromstring($normal);

        $this->assertSame([40, 20], [$normalInfo[0], $normalInfo[1]]);
    }

    public function test_converter_supports_png_jpeg_and_webp_input(): void
    {
        foreach (['png', 'jpg', 'webp'] as $format) {
            $source = $this->imageBytes($format);

            $this->assertSame(
                'image/webp',
                getimagesizefromstring($this->converter->convertContents($source, WebpConverter::PRESET_LOGO))['mime'],
                "conversion from {$format} must yield WebP",
            );
        }
    }

    public function test_converter_rejects_animated_webp(): void
    {
        $animated = (string) file_get_contents(base_path('tests/Fixtures/animated.webp'));

        $this->assertTrue($this->converter->isAnimatedWebp($animated));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Animated WebP is not converted.');

        $this->converter->convertContents($animated, WebpConverter::PRESET_LOGO);
    }

    public function test_converter_rejects_non_images(): void
    {
        $this->expectException(RuntimeException::class);

        $this->converter->convertContents('this is not an image', WebpConverter::PRESET_LOGO);
    }

    /* ---------------------------------------------------------------------
     | Service integration — every brand service writes the sibling
     | ------------------------------------------------------------------- */

    public function test_every_brand_service_writes_a_webp_sibling(): void
    {
        app(MandantMediaService::class)->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');

        $team = Team::factory()->create(['mandant_id' => $this->mandant->id, 'slug' => 'team-a', 'name' => 'Team A']);
        app(TeamMediaService::class)->store($team, UploadedFile::fake()->image('logo.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-a/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-a/logo.webp');

        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);
        app(EventTypeMediaService::class)->store($eventType, UploadedFile::fake()->image('logo.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/event-types/bundesliga/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/event-types/bundesliga/logo.webp');

        $image = app(BadgeImageService::class)->store($this->mandant, UploadedFile::fake()->image('wappen.png'));
        Storage::disk(MediaPathService::DISK)->assertExists($image->path);
        Storage::disk(MediaPathService::DISK)->assertExists((string) app(MediaStorage::class)->webpSiblingPath($image->path));
    }

    public function test_replacing_with_another_extension_leaves_no_stale_variant(): void
    {
        $service = app(MandantMediaService::class);

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('first.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('second.jpg'));

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.jpg');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.webp');
        $this->assertSame('verband-a.test/logo.jpg', $this->mandant->fresh()->logo_path);
    }

    public function test_slug_change_moves_the_webp_sibling(): void
    {
        $service = app(TeamMediaService::class);
        $team = Team::factory()->create(['mandant_id' => $this->mandant->id, 'slug' => 'team-a', 'name' => 'Team A']);

        $service->store($team, UploadedFile::fake()->image('logo.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-a/logo.webp');

        $team->update(['slug' => 'team-neu']);
        $service->moveForSlugChange($team->fresh(), 'team-a');

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/team-a/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/team-a/logo.webp');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-neu/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-neu/logo.webp');
        $this->assertSame('verband-a.test/teams/team-neu/logo.png', $team->fresh()->logo_path);
    }

    public function test_user_media_extension_derives_from_the_mime_not_the_client_name(): void
    {
        $user = User::factory()->create();

        // PNG content under a misleading `.jpg` client name.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'oa_png_');
        file_put_contents($tmp, $this->imageBytes('png'));
        $file = new UploadedFile($tmp, 'evil.jpg', 'image/png', null, true);

        $this->assertSame('image/png', $file->getMimeType());
        $this->assertSame('jpg', $file->getClientOriginalExtension());

        try {
            $media = app(UserMediaService::class)->store($user, MediaType::PORTRAIT, $file, 'verband-a');
        } finally {
            @unlink($tmp);
        }

        $this->assertStringEndsWith('.png', $media->path);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function imageBytes(string $format): string
    {
        $image = imagecreatetruecolor(24, 16);

        ob_start();

        match ($format) {
            'png' => imagepng($image),
            'jpg' => imagejpeg($image),
            'webp' => imagewebp($image),
            default => throw new RuntimeException('Unsupported test format '.$format),
        };

        return (string) ob_get_clean();
    }

    /**
     * A 40×20 JPEG carrying an EXIF orientation tag (APP1/TIFF, little endian).
     */
    private function jpegWithOrientation(int $orientation, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 128, 255));

        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $tiff = 'II'.pack('v', 42).pack('V', 8);
        $ifd = pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);
        $payload = "Exif\x00\x00".$tiff.$ifd;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }
}

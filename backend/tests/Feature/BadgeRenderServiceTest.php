<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BadgeImage;
use App\Models\BadgeTemplate;
use App\Models\Mandant;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\BadgeRenderService;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P4 / badge-template-editor Etappe 1 — PDF render contract.
 *
 * Asserted on the exact card HTML (`BadgeRenderService::cardHtml`) that dompdf
 * prints — CSS mm/pt are physical units, so the markup IS the print contract:
 *
 * - Regression: a legacy template without a `qr` entry renders exactly as
 *   before — fields at their absolute positions, QR at the historical fixed
 *   spot bottom-right (5 mm margin, 20 × 20 mm).
 * - Schema v2: a `qr` entry moves the QR to its coordinates (size/align are
 *   ignored) and is not rendered as a data field.
 * - New data fields: `team` (accreditation team name) and `vest_number`
 *   (user) render their source value or an empty string when absent,
 *   escaped like every interpolated value.
 * - Freely placed `image` entries render as absolutely positioned,
 *   Base64-embedded `<img>` blocks: `brand` sources resolve through the
 *   mandant's brand media, `upload` sources through the mandant-scoped
 *   `badge_images` row; `fit` defaults to `contain`, a missing source
 *   renders an empty box at the layout position.
 */
class BadgeRenderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private BadgeRenderService $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        MandantContext::set($this->mandant);
        $this->renderer = app(BadgeRenderService::class);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Legacy regression — templates without a qr entry render unchanged
     | ------------------------------------------------------------------- */

    public function test_legacy_layout_renders_fields_and_fixed_bottom_right_qr_unchanged(): void
    {
        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        // Golden markup: identical to the pre-v2 output (position + escaped
        // value + fixed QR block built from the fallback constants).
        $this->assertStringContainsString(
            '<div style="position:absolute;left:10.00mm;top:10.00mm;width:80.00mm;height:10.00mm;'
            .'font-size:14pt;text-align:left;">Jane Doe</div>',
            $html,
        );

        $this->assertStringContainsString(
            '<div style="position:absolute;right:5mm;bottom:5mm;width:20mm;height:20mm;">'
            .'<img src="data:image/png;base64,',
            $html,
        );
    }

    public function test_legacy_layout_renders_the_qr_exactly_once(): void
    {
        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'photo', 'x' => 5, 'y' => 25, 'w' => 25, 'h' => 30, 'size' => 12, 'align' => 'left'],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        // No portrait stored: the photo stays an empty box at its position.
        $this->assertStringContainsString(
            '<div style="position:absolute;left:5.00mm;top:25.00mm;width:25.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;"></div>',
            $html,
        );

        $this->assertSame(1, substr_count($html, '<img src="data:image/png;base64,'));
    }

    /* ---------------------------------------------------------------------
     | Schema v2 — qr entry positioning
     | ------------------------------------------------------------------- */

    public function test_qr_entry_positions_the_qr_and_is_not_rendered_as_a_data_field(): void
    {
        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'qr', 'x' => 78, 'y' => 121, 'w' => 22, 'h' => 22],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        // The QR sits at its entry coordinates …
        $this->assertStringContainsString(
            '<div style="position:absolute;left:78.00mm;top:121.00mm;width:22.00mm;height:22.00mm;">'
            .'<img src="data:image/png;base64,',
            $html,
        );

        // … the historical fixed position is gone …
        $this->assertStringNotContainsString('right:5mm', $html);

        // … size/align are ignored (no font styling on the qr block) …
        $this->assertSame(1, substr_count($html, 'font-size'), 'only the data field carries font-size');

        // … the entry produced exactly ONE qr image (not an extra empty div).
        $this->assertSame(1, substr_count($html, '<img src="data:image/png;base64,'));
    }

    public function test_full_pdf_still_renders_with_a_coordinated_template(): void
    {
        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'team', 'x' => 10, 'y' => 22, 'w' => 60, 'h' => 6, 'size' => 10, 'align' => 'left'],
            ['field' => 'vest_number', 'x' => 10, 'y' => 30, 'w' => 30, 'h' => 5, 'size' => 9, 'align' => 'left'],
            ['field' => 'qr', 'x' => 78, 'y' => 121, 'w' => 22, 'h' => 22],
        ]);

        $pdf = $this->renderer->renderPdf(
            new Collection([$this->approvedApplication(['with_team' => true], ['vest_number' => 'W12'])]),
            $template,
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('Jane Doe', $text);
        $this->assertStringContainsString('Team A', $text);
        $this->assertStringContainsString('W12', $text);
    }

    /* ---------------------------------------------------------------------
     | New data fields — team & vest_number
     | ------------------------------------------------------------------- */

    public function test_team_and_vest_number_render_from_their_sources(): void
    {
        $application = $this->approvedApplication(['with_team' => true], ['vest_number' => 'W12']);
        $html = $this->renderer->cardHtml($application, $this->makeTemplate([
            ['field' => 'team', 'x' => 10, 'y' => 22, 'w' => 60, 'h' => 6, 'size' => 10, 'align' => 'left'],
            ['field' => 'vest_number', 'x' => 10, 'y' => 30, 'w' => 30, 'h' => 5, 'size' => 9, 'align' => 'center'],
        ]));

        $this->assertStringContainsString(
            '<div style="position:absolute;left:10.00mm;top:22.00mm;width:60.00mm;height:6.00mm;'
            .'font-size:10pt;text-align:left;">Team A</div>',
            $html,
        );

        $this->assertStringContainsString(
            '<div style="position:absolute;left:10.00mm;top:30.00mm;width:30.00mm;height:5.00mm;'
            .'font-size:9pt;text-align:center;">W12</div>',
            $html,
        );
    }

    public function test_missing_team_or_vest_number_render_as_empty_strings(): void
    {
        // No team_id on the accreditation, no vest_number on the user.
        $application = $this->approvedApplication();
        $html = $this->renderer->cardHtml($application, $this->makeTemplate([
            ['field' => 'team', 'x' => 10, 'y' => 22, 'w' => 60, 'h' => 6, 'size' => 10, 'align' => 'left'],
            ['field' => 'vest_number', 'x' => 10, 'y' => 30, 'w' => 30, 'h' => 5, 'size' => 9, 'align' => 'left'],
        ]));

        $this->assertStringNotContainsString('Team A', $html);
        $this->assertStringContainsString(
            '<div style="position:absolute;left:10.00mm;top:22.00mm;width:60.00mm;height:6.00mm;'
            .'font-size:10pt;text-align:left;"></div>',
            $html,
        );

        $this->assertStringContainsString(
            '<div style="position:absolute;left:10.00mm;top:30.00mm;width:30.00mm;height:5.00mm;'
            .'font-size:9pt;text-align:left;"></div>',
            $html,
        );
    }

    public function test_team_value_is_escaped_like_every_interpolated_value(): void
    {
        $application = $this->approvedApplication(['with_team' => true, 'team_name' => '<b>EV & Co</b>']);
        $html = $this->renderer->cardHtml($application, $this->makeTemplate([
            ['field' => 'team', 'x' => 10, 'y' => 22, 'w' => 60, 'h' => 6, 'size' => 10, 'align' => 'left'],
        ]));

        $this->assertStringContainsString('&lt;b&gt;EV &amp; Co&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    /* ---------------------------------------------------------------------
     | Portrait embedding — real decodable bytes reach the markup & PDF
     | ------------------------------------------------------------------- */

    public function test_portrait_bytes_are_embedded_intact_as_data_uri(): void
    {
        $application = $this->approvedApplication();
        $bytes = $this->storeRealPngPortrait($application->user);

        $html = $this->renderer->cardHtml($application, $this->makeTemplate([
            ['field' => 'photo', 'x' => 8, 'y' => 30, 'w' => 25, 'h' => 30, 'size' => 12, 'align' => 'left'],
        ]));

        // The exact generated PNG survives base64 round-trip into the markup
        // (private disk → data URI, object-fit preserved).
        $this->assertStringContainsString(
            '<div style="position:absolute;left:8.00mm;top:30.00mm;width:25.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;overflow:hidden;">'
            .'<img src="data:image/png;base64,'.base64_encode($bytes).'" style="width:100%;height:100%;object-fit:cover;"></div>',
            $html,
        );

        // End-to-end: dompdf turns the data URI into a drawn image XObject.
        $pdf = $this->renderer->renderPdf(new Collection([$application]), $this->makeTemplate([
            ['field' => 'photo', 'x' => 8, 'y' => 30, 'w' => 25, 'h' => 30, 'size' => 12, 'align' => 'left'],
        ]));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString(' Do', $this->pdfText($pdf));
    }

    /* ---------------------------------------------------------------------
     | Freely placed `image` entries — brand & upload sources, fit, fallback
     | ------------------------------------------------------------------- */

    public function test_brand_image_renders_from_mandant_logo(): void
    {
        $bytes = $this->storeRealPng('mandants/verband-a/logo.png');
        $this->mandant->update(['logo_path' => 'mandants/verband-a/logo.png']);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ]));

        // Base64 data URI of the brand logo, object-fit defaults to contain.
        $this->assertStringContainsString(
            '<div style="position:absolute;left:5.00mm;top:130.00mm;width:20.00mm;height:12.00mm;overflow:hidden;">'
            .'<img src="data:image/png;base64,'.base64_encode($bytes).'" style="width:100%;height:100%;object-fit:contain;"></div>',
            $html,
        );
    }

    public function test_brand_image_with_cover_fit_renders_object_fit_cover(): void
    {
        $bytes = $this->storeRealPng('mandants/verband-a/header.png');
        $this->mandant->update(['header_path' => 'mandants/verband-a/header.png']);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            [
                'field' => 'image',
                'x' => 0,
                'y' => 0,
                'w' => 105,
                'h' => 20,
                'src' => ['kind' => 'brand', 'ref' => 'header'],
                'fit' => 'cover',
            ],
        ]));

        $this->assertStringContainsString('object-fit:cover', $html);
        $this->assertStringContainsString(base64_encode($bytes), $html);
    }

    public function test_brand_image_without_stored_file_renders_empty_box(): void
    {
        // No logo_path set — the brand source resolves to an empty box at its
        // layout position. The QR still renders its own <img> below, so we
        // assert the image box specifically carries no <img>.
        $html = $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ]));

        $this->assertStringContainsString(
            '<div style="position:absolute;left:5.00mm;top:130.00mm;width:20.00mm;height:12.00mm;overflow:hidden;"></div>',
            $html,
        );
        // The image box is empty — no data: URI inside it (the QR further down
        // is the only <img> on the card).
        $this->assertStringNotContainsString('left:5.00mm;top:130.00mm;width:20.00mm;height:12.00mm;overflow:hidden;"><img', $html);
    }

    public function test_upload_image_renders_from_badge_images_row(): void
    {
        $bytes = $this->storeRealPng('badge-images/verband-a/upload.png');
        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'badge-images/verband-a/upload.png',
            'mime' => 'image/png',
            'original_name' => 'upload.png',
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            ['field' => 'image', 'x' => 40, 'y' => 130, 'w' => 15, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $image->id], 'fit' => 'cover'],
        ]));

        $this->assertStringContainsString(
            '<div style="position:absolute;left:40.00mm;top:130.00mm;width:15.00mm;height:12.00mm;overflow:hidden;">'
            .'<img src="data:image/png;base64,'.base64_encode($bytes).'" style="width:100%;height:100%;object-fit:cover;"></div>',
            $html,
        );
    }

    public function test_upload_image_from_foreign_mandant_renders_empty_box(): void
    {
        $bytes = $this->storeRealPng('badge-images/other/foreign.png');
        $otherMandant = Mandant::factory()->create(['slug' => 'other', 'name' => 'Other']);
        $foreignImage = BadgeImage::create([
            'mandant_id' => $otherMandant->id,
            'path' => 'badge-images/other/foreign.png',
            'mime' => 'image/png',
            'original_name' => 'foreign.png',
        ]);

        // The current mandant may not resolve another tenant's upload — empty
        // box, the foreign bytes never leak into the markup. The QR still
        // renders its own <img>, so we assert the foreign bytes specifically
        // are absent and the image box carries no <img>.
        $html = $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $foreignImage->id]],
        ]));

        $this->assertStringNotContainsString(base64_encode($bytes), $html);
        $this->assertStringNotContainsString('left:5.00mm;top:130.00mm;width:20.00mm;height:12.00mm;overflow:hidden;"><img', $html);
    }

    public function test_image_entry_is_not_rendered_as_a_data_field(): void
    {
        $html = $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ]));

        // The image entry carries no font-size (it is NOT a data field).
        $this->assertSame(1, substr_count($html, 'font-size'), 'only the data field carries font-size');
    }

    public function test_legacy_layout_without_image_entry_renders_unchanged(): void
    {
        // Regression: a template without any `image` entry renders exactly as
        // before — the image branch is purely additive.
        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        $this->assertStringNotContainsString('overflow:hidden', $html);
        $this->assertSame(1, substr_count($html, '<img'), 'only the QR renders an image');
    }

    /* ---------------------------------------------------------------------
     | FE1-F4 — host() resolves the mandant domain only once per render run
     | ------------------------------------------------------------------- */

    public function test_verify_url_host_is_resolved_once_across_many_cards(): void
    {
        $this->mandant->domains()->create(['hostname' => 'verband-a.test', 'is_primary' => true]);

        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'qr', 'x' => 78, 'y' => 121, 'w' => 22, 'h' => 22],
        ]);

        $applications = new Collection([
            $this->approvedApplication(),
            $this->approvedApplication(),
            $this->approvedApplication(),
        ]);

        // Query-count spy: count how many queries hit the `domains` table
        // during the multi-card render. Without the host cache (FE1-F4) each
        // card would re-issue the same domains query (N+1 on export).
        DB::enableQueryLog();
        $pdf = $this->renderer->renderPdf($applications, $template);
        $domainQueries = count(array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'domains'),
        ));
        DB::disableQueryLog();

        $this->assertStringStartsWith('%PDF-', $pdf);
        // Three cards sharing one mandant → exactly one domains lookup, the
        // second and third card hit the in-memory host cache (FE1-F4).
        $this->assertSame(1, $domainQueries, 'domain query must run once, not once per card');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private static int $mediaCount = 0;

    /**
     * @param  array{with_team?: bool, team_name?: string}  $options
     */
    private function approvedApplication(array $options = [], array $userAttributes = []): Application
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        $attributes = [
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ];

        if ($options['with_team'] ?? false) {
            $team = $this->mandant->teams()->create(['name' => $options['team_name'] ?? 'Team A', 'slug' => 'team-a']);

            $attributes['team_id'] = $team->id;
        }

        $accreditation = $this->mandant->accreditations()->create($attributes);
        $jane = User::factory()->create(['name' => 'Jane Doe', ...$userAttributes]);

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $jane->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    private function makeTemplate(array $layout): BadgeTemplate
    {
        return BadgeTemplate::create([
            'mandant_id' => $this->mandant->id,
            'name' => 'Presseausweis',
            'layout' => $layout,
            'is_default' => false,
        ]);
    }

    /**
     * Store a REAL decodable PNG portrait on the private disk (GD-generated)
     * and register it as the user's `portrait` media row.
     *
     * @return string the exact PNG bytes that must survive into the markup
     */
    private function storeRealPngPortrait(User $user): string
    {
        $image = imagecreatetruecolor(60, 80);
        $background = imagecolorallocate($image, 40, 90, 160);
        $face = imagecolorallocate($image, 230, 200, 150);

        imagefilledrectangle($image, 0, 0, 59, 79, $background);
        imagefilledellipse($image, 30, 28, 28, 28, $face);
        imagefilledrectangle($image, 12, 50, 48, 80, $face);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = "user-media/verband-a/{$user->id}/portrait/portrait-".(++self::$mediaCount).'.png';

        Storage::disk('private')->put($path, $bytes);

        UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => $path,
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'original_name' => 'portrait.png',
        ]);

        return $bytes;
    }

    /**
     * Store a REAL decodable PNG at an arbitrary private-disk path (brand
     * media, badge image) without registering a media row — returns the
     * exact bytes that must survive into the markup.
     *
     * @return string the exact PNG bytes written to the private disk
     */
    private function storeRealPng(string $path): string
    {
        $image = imagecreatetruecolor(60, 60);
        $background = imagecolorallocate($image, 40, 90, 160);
        $accent = imagecolorallocate($image, 230, 200, 150);

        imagefilledrectangle($image, 0, 0, 59, 59, $background);
        imagefilledellipse($image, 30, 30, 28, 28, $accent);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('private')->put($path, $bytes);

        return $bytes;
    }

    /**
     * Extract the inflated dompdf content-stream text (see BadgeTest::pdfText).
     */
    private function pdfText(string $pdf): string
    {
        $text = '';
        $offset = 0;

        while (($start = strpos($pdf, 'stream', $offset)) !== false) {
            $dataStart = strpos($pdf, "\n", $start) + 1;
            $dataEnd = strpos($pdf, 'endstream', $dataStart);

            if ($dataEnd === false) {
                break;
            }

            $inflated = @gzuncompress(rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart)));

            if ($inflated === false) {
                $inflated = @gzinflate(rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart)));
            }

            if ($inflated !== false) {
                $text .= $inflated;
            }

            $offset = $dataEnd;
        }

        return str_replace("\x00", '', $text);
    }
}

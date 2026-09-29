<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BadgeImage;
use App\Models\BadgeTemplate;
use App\Models\Mandant;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\BadgePhotoPlaceholder;
use App\Services\BadgeRenderService;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExtractsPdfContentStream;
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
    use ExtractsPdfContentStream;
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

        // The BOX keeps the historical fixed geometry verbatim (right/bottom 5 mm,
        // 20 × 20 mm). The `<img>` inside it is now drawn in millimetres too — the
        // P7 `cover` geometry — and for a 20 × 20 mm box holding the square 300 px
        // QR the drawn rectangle is the whole box, so a square fallback still
        // prints pixel-identically apart from the `object-fit`/`position`
        // bookkeeping. `object-fit` stays in the inline style as documentation and
        // for a renderer that honours it; dompdf does not (see `fittedImage`).
        $this->assertStringContainsString(
            '<div style="position:absolute;right:5mm;bottom:5mm;width:20mm;height:20mm;overflow:hidden;">'
            .'<img src="data:image/png;base64,',
            $html,
        );
        $this->assertStringContainsString(
            'style="position:absolute;left:0.00mm;top:0.00mm;width:20.00mm;height:20.00mm;object-fit:cover;"',
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

        // No portrait stored: the photo box keeps its position and now carries the
        // person silhouette. The empty box it replaced is the last resort only —
        // for a missing bundled asset (see BadgePhotoPlaceholderTest).
        $placeholder = app(BadgePhotoPlaceholder::class)->dataUri();
        $this->assertStringContainsString(
            '<div style="position:absolute;left:5.00mm;top:25.00mm;width:25.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;overflow:hidden;"><img src="'.$placeholder.'"'
            .' style="position:absolute;left:0.00mm;top:2.50mm;width:25.00mm;height:25.00mm;object-fit:contain;"></div>',
            $html,
        );

        $this->assertSame(1, $this->qrImageCount($html));
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

        // The QR sits at its entry coordinates. The box carries `overflow:hidden`
        // like every other picture box, and the `<img>` is drawn in millimetres
        // (P7): a 22 × 22 mm box with the square 300 px QR source is the degenerate
        // case where `cover` fills the box exactly — see
        // `test_non_square_qr_box_uses_cover_geometry_and_never_stretches`.
        $this->assertStringContainsString(
            '<div style="position:absolute;left:78.00mm;top:121.00mm;width:22.00mm;height:22.00mm;overflow:hidden;">'
            .'<img src="data:image/png;base64,',
            $html,
        );
        $this->assertStringContainsString(
            'style="position:absolute;left:0.00mm;top:0.00mm;width:22.00mm;height:22.00mm;object-fit:cover;"',
            $html,
        );

        // … the historical fixed position is gone …
        $this->assertStringNotContainsString('right:5mm', $html);

        // … size/align are ignored (no font styling on the qr block) …
        $this->assertSame(1, substr_count($html, 'font-size'), 'only the data field carries font-size');

        // … the entry produced exactly ONE qr image (not an extra empty div).
        $this->assertSame(1, substr_count($html, '<img src="data:image/png;base64,'));
    }

    public function test_qr_renders_after_overlapping_fields_top_z_order(): void
    {
        // A data field overlaps the QR position. The QR must render AFTER it in
        // the DOM (top z-order) so the verification code stays scannable — it
        // must never be hidden beneath an overlapping field.
        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            // Photo box deliberately overlaps the QR position (78/121 + 22×22).
            ['field' => 'photo', 'x' => 70, 'y' => 115, 'w' => 30, 'h' => 30, 'size' => 12, 'align' => 'left'],
            ['field' => 'qr', 'x' => 78, 'y' => 121, 'w' => 22, 'h' => 22],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        // The QR div (left:78.00mm;top:121.00mm) MUST appear AFTER the
        // overlapping photo div (left:70.00mm;top:115.00mm) in the DOM — later
        // = top z-order for absolutely positioned siblings.
        $photoPos = strpos($html, 'left:70.00mm;top:115.00mm');
        $qrPos = strpos($html, 'left:78.00mm;top:121.00mm');

        $this->assertNotFalse($photoPos, 'overlapping photo field must be present');
        $this->assertNotFalse($qrPos, 'qr block must be present');
        $this->assertGreaterThan(
            $photoPos,
            $qrPos,
            'QR must render after overlapping fields (top z-order) so it stays scannable.',
        );

        // Sanity: exactly one QR image on the card (counted by its 300 px
        // intrinsic size, so the photo placeholder does not inflate the number).
        $this->assertSame(1, $this->qrImageCount($html));
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
        // (private disk → data URI). The 60 × 80 portrait in a 25 × 30 mm box is
        // `cover` (the photo default): it fills the 25 mm width and overhangs
        // vertically to 33.33 mm, centered, so 1.67 mm is cropped top and bottom.
        // Before the fit geometry existed this was `width:100%;height:100%` —
        // i.e. the portrait was STRETCHED into the box, because dompdf silently
        // drops `object-fit` (see BadgeImageFitGeometryTest).
        $this->assertStringContainsString(
            '<div style="position:absolute;left:8.00mm;top:30.00mm;width:25.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;overflow:hidden;">'
            .'<img src="data:image/png;base64,'.base64_encode($bytes).'"'
            .' style="position:absolute;left:0.00mm;top:-1.67mm;width:25.00mm;height:33.33mm;object-fit:cover;"></div>',
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

        // Base64 data URI of the brand logo, `fit` defaults to contain: the
        // square 60 × 60 logo lands whole in the 20 × 12 mm box at 12 × 12 mm,
        // centered on the leftover axis (4.00 mm of empty space per side).
        $this->assertStringContainsString(
            '<div style="position:absolute;left:5.00mm;top:130.00mm;width:20.00mm;height:12.00mm;overflow:hidden;">'
            .'<img src="data:image/png;base64,'.base64_encode($bytes).'"'
            .' style="position:absolute;left:4.00mm;top:0.00mm;width:12.00mm;height:12.00mm;object-fit:contain;"></div>',
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

        // `fit: cover` on a square 60 × 60 source in a 15 × 12 mm box: the box is
        // filled on the width, the height overhangs to 15 mm and is cropped
        // 1.50 mm top and bottom. Nothing is letterboxed and nothing is stretched.
        $this->assertStringContainsString(
            '<div style="position:absolute;left:40.00mm;top:130.00mm;width:15.00mm;height:12.00mm;overflow:hidden;">'
            .'<img src="data:image/png;base64,'.base64_encode($bytes).'"'
            .' style="position:absolute;left:0.00mm;top:-1.50mm;width:15.00mm;height:15.00mm;object-fit:cover;"></div>',
            $html,
        );
    }

    public function test_upload_image_renders_from_a_webp_badge_images_row(): void
    {
        // W11: after the WebP backfill (or with `--prune-originals`) a badge
        // image row points at a `.webp` file. DomPDF/GD must still render it.
        $bytes = $this->storeRealWebp('verband-a.test/badges/upload.webp');
        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'verband-a.test/badges/upload.webp',
            'mime' => 'image/webp',
            'original_name' => 'upload.webp',
        ]);

        $template = $this->makeTemplate([
            ['field' => 'image', 'x' => 40, 'y' => 130, 'w' => 15, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $image->id]],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);
        $this->assertStringContainsString('data:image/webp;base64,'.base64_encode($bytes), $html);

        $pdf = $this->renderer->renderPdf(new Collection([$this->approvedApplication()]), $template);
        $this->assertStringStartsWith('%PDF-', $pdf);
        // dompdf decoded the WebP data URI into a drawn image XObject.
        $this->assertStringContainsString(' Do', $this->pdfText($pdf));
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

        // No IMAGE box on the card: the only `overflow:hidden` belongs to the QR,
        // which is a picture box by contract and has carried one since P7. The
        // original assertion (`assertStringNotContainsString('overflow:hidden')`)
        // predates that and would now pass for the wrong reason, so the image
        // branch's absence is pinned on the `<img>` count instead — the `src`
        // discriminator is resolved server-side and never reaches the markup, so
        // exactly ONE image proves only the QR rendered.
        $this->assertSame(1, substr_count($html, '<img'), 'only the QR renders an image');
        $this->assertSame(1, substr_count($html, 'overflow:hidden'), 'only the QR box clips');
    }

    /* ---------------------------------------------------------------------
     | P7 — the QR branch is geometry, like every other picture on the card
     | ------------------------------------------------------------------- */

    public function test_non_square_qr_box_uses_cover_geometry_and_never_stretches(): void
    {
        // A `qr` box is one of the box fields, minimum 10 × 10 mm, so a NON-SQUARE
        // one is legal tenant input (`w:30, h:20`). Before P7 this branch emitted
        // `<img style="width:100%;height:100%">`, which dompdf renders as a STRETCH
        // (it implements no `object-fit`), i.e. a 3:2-distorted code — and a
        // distorted QR code does not scan, so verification of that badge fails.
        $template = $this->makeTemplate([
            ['field' => 'qr', 'x' => 10, 'y' => 20, 'w' => 30, 'h' => 20],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        // The box is the tenant's 30 × 20 mm, unchanged.
        $this->assertStringContainsString(
            '<div style="position:absolute;left:10.00mm;top:20.00mm;width:30.00mm;height:20.00mm;overflow:hidden;">',
            $html,
        );

        // `cover` on a 300 px SQUARE source in a 30 × 20 box: scale by the larger
        // axis factor (30/300 = 0.1 vs 20/300 = 0.0667), so the code is drawn
        // 30 × 30 mm and the box's `overflow:hidden` crops 5 mm top and bottom.
        // left = (30 - 30) / 2 = 0, top = (20 - 30) / 2 = -5.
        $this->assertStringContainsString(
            'style="position:absolute;left:0.00mm;top:-5.00mm;width:30.00mm;height:30.00mm;object-fit:cover;"',
            $html,
        );

        // The non-vacuity guard for this whole finding: a STRETCH would be
        // `width:30.00mm;height:20.00mm`, filling the box edge to edge. Asserting
        // the exact drawn rectangle is what makes a regression to `100%`/`100%`
        // (or to `contain`) fail loudly instead of silently shipping a code that
        // cannot be scanned.
        $this->assertStringNotContainsString('width:100%;height:100%', $html);
        $this->assertStringNotContainsString('object-fit:contain', $html);
    }

    public function test_portrait_qr_box_crops_horizontally_instead_of_stretching(): void
    {
        // The transposed case, and the one that proves the geometry is COMPUTED
        // rather than hard-coded for landscape: a 20 × 30 mm box draws the square
        // source at 30 × 30 mm with left = (20 - 30) / 2 = -5 and top = 0.
        $template = $this->makeTemplate([
            ['field' => 'qr', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 30],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        $this->assertStringContainsString(
            'style="position:absolute;left:-5.00mm;top:0.00mm;width:30.00mm;height:30.00mm;object-fit:cover;"',
            $html,
        );
    }

    public function test_square_qr_box_fills_the_box_exactly(): void
    {
        // The degenerate case `cover` and `contain` must agree on, pinned because
        // it is the one a wrong implementation hides in: a square box holding the
        // square source has equal axis factors, so the drawn rectangle IS the box
        // (no negative offset, no crop).
        $template = $this->makeTemplate([
            ['field' => 'qr', 'x' => 70, 'y' => 110, 'w' => 25, 'h' => 25],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        $this->assertStringContainsString(
            'style="position:absolute;left:0.00mm;top:0.00mm;width:25.00mm;height:25.00mm;object-fit:cover;"',
            $html,
        );
    }

    public function test_qr_box_ignores_a_fit_key_and_always_prints_cover(): void
    {
        // The wire format has no `fit` key for `qr` (the controller validates
        // `layout.*.fit` only for `image`/`photo` entries). A hand-built layout
        // that carries one anyway must NOT flip the code to `contain` — a
        // letterboxed verification code wastes the area the tenant reserved, and
        // `cover` is the deliberate choice for a code (see `renderQr`).
        $template = $this->makeTemplate([
            ['field' => 'qr', 'x' => 10, 'y' => 20, 'w' => 30, 'h' => 20, 'fit' => 'contain'],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        $this->assertStringContainsString('object-fit:cover', $html);
        $this->assertStringNotContainsString('object-fit:contain', $html);
        // `contain` would draw 20 × 20 mm centred; `cover` draws 30 × 30 mm.
        $this->assertStringContainsString('width:30.00mm;height:30.00mm', $html);
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
     | W6-F3 — host resolution via the shared MediaHostResolver
     | ------------------------------------------------------------------- */

    public function test_verify_url_uses_the_first_domain_so_a_later_alias_never_wins(): void
    {
        // W6-F3: `host()` resolves through the shared MediaHostResolver, so the
        // "first domain = primary" convention applies to the verify URL exactly
        // as it does to every media path (W6 host assumption).
        $this->mandant->domains()->create(['hostname' => 'primary.test']);
        $this->mandant->domains()->create(['hostname' => 'alias.test']);

        $this->assertStringStartsWith(
            $this->expectedVerifyUrlPrefix('primary.test'),
            $this->renderer->verifyUrl($this->approvedApplication()),
        );
    }

    public function test_verify_url_falls_back_to_app_url_host_without_a_domain(): void
    {
        // No mandant domain -> the configured app host (documented W6 fallback,
        // unchanged by the MediaHostResolver switch).
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->assertStringStartsWith(
            $this->expectedVerifyUrlPrefix($appHost),
            $this->renderer->verifyUrl($this->approvedApplication()),
        );
    }

    /* ---------------------------------------------------------------------
     | WP-2-d — badge image lookup: one query per distinct id, tenancy fail-closed
     | ------------------------------------------------------------------- */

    public function test_badge_image_is_looked_up_once_per_distinct_id_across_many_cards(): void
    {
        $this->storeRealPng('badge-images/verband-a/upload.png');
        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'badge-images/verband-a/upload.png',
            'mime' => 'image/png',
            'original_name' => 'upload.png',
        ]);

        $template = $this->makeTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $image->id]],
        ]);

        $applications = new Collection([
            $this->approvedApplication(),
            $this->approvedApplication(),
            $this->approvedApplication(),
        ]);

        // Without the per-run image cache every card re-ran the same
        // `badge_images` lookup (N×M queries per export, M = layout entries).
        DB::enableQueryLog();
        $pdf = $this->renderer->renderPdf($applications, $template);
        $imageQueries = count(array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'badge_images'),
        ));
        DB::disableQueryLog();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $imageQueries, 'one distinct image id must cost exactly one query, not one per card');
    }

    public function test_badge_image_is_never_embedded_without_a_resolved_mandant(): void
    {
        $bytes = $this->storeRealPng('badge-images/verband-a/upload.png');
        $image = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'badge-images/verband-a/upload.png',
            'mime' => 'image/png',
            'original_name' => 'upload.png',
        ]);

        $template = $this->makeTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $image->id]],
        ]);

        // No mandant context (console/seed/test context): the tenancy filter
        // used to be dropped (`->when(hasCurrent(), …)`) and the file was
        // embedded fail-open. Without a mandant the render path is fail-closed.
        MandantContext::reset();

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        $this->assertStringNotContainsString(base64_encode($bytes), $html);
        $this->assertStringNotContainsString('left:5.00mm;top:130.00mm;width:20.00mm;height:12.00mm;overflow:hidden;"><img', $html);
    }

    public function test_badge_image_cache_does_not_leak_across_mandant_contexts(): void
    {
        $bytesA = $this->storeRealPng('badge-images/verband-a/upload.png');
        $imageA = BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => 'badge-images/verband-a/upload.png',
            'mime' => 'image/png',
            'original_name' => 'upload.png',
        ]);

        $other = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);
        $bytesB = $this->storeRealPng('badge-images/verband-b/upload.png', [10, 200, 40]);
        $imageB = BadgeImage::create([
            'mandant_id' => $other->id,
            'path' => 'badge-images/verband-b/upload.png',
            'mime' => 'image/png',
            'original_name' => 'upload.png',
        ]);

        $templateA = $this->makeTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $imageA->id]],
        ]);
        $templateB = BadgeTemplate::create([
            'mandant_id' => $other->id,
            'name' => 'Fremd',
            'layout' => [
                ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => $imageB->id]],
            ],
            'is_default' => false,
        ]);

        $application = $this->approvedApplication();

        $htmlA = $this->renderer->cardHtml($application, $templateA);
        $this->assertStringContainsString(base64_encode($bytesA), $htmlA);

        // Same service instance, other mandant: the cache is keyed by
        // `mandantId:imageId`, so mandant B resolves its own row and mandant A's
        // bytes are never served to the other tenant.
        MandantContext::set($other);
        $htmlB = $this->renderer->cardHtml($application, $templateB);
        $this->assertStringContainsString(base64_encode($bytesB), $htmlB);
        $this->assertStringNotContainsString(base64_encode($bytesA), $htmlB);
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
     * exact bytes that must survive into the markup. `$fill` makes the bytes
     * distinguishable between two images of identical dimensions (the encoder
     * is deterministic, so two calls with the same colours yield the same file).
     *
     * `$width`/`$height` default to a SQUARE 60 × 60 source; the `fit` geometry
     * cases pass a non-square source explicitly (see BadgeImageFitGeometryTest,
     * which is the spec for those).
     *
     * @param  array{int, int, int}  $fill
     * @return string the exact PNG bytes written to the private disk
     */
    private function storeRealPng(string $path, array $fill = [40, 90, 160], int $width = 60, int $height = 60): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, $fill[0], $fill[1], $fill[2]);
        $accent = imagecolorallocate($image, 230, 200, 150);

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), min(28, $width - 2), min(28, $height - 2), $accent);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('private')->put($path, $bytes);

        return $bytes;
    }

    /**
     * Store a REAL decodable WebP on the public media disk (GD-generated) at an
     * arbitrary path — returns the exact bytes that must survive into the
     * markup (W11 badge-pipeline check).
     *
     * @return string the exact WebP bytes written to the media disk
     */
    private function storeRealWebp(string $path): string
    {
        $image = imagecreatetruecolor(60, 60);
        imagefilledrectangle($image, 0, 0, 59, 59, imagecolorallocate($image, 40, 90, 160));

        ob_start();
        imagewebp($image);
        $bytes = (string) ob_get_clean();

        Storage::disk('media')->put($path, $bytes);

        return $bytes;
    }

    /**
     * How many QR images the card carries. The QR is the only 300 × 300 PNG in
     * the markup (the Endroid builder is pinned to `size: 300`); counting by
     * intrinsic size instead of by `<img` keeps the assertion honest now that a
     * portrait-less `photo` entry also emits an image (the placeholder).
     */
    private function qrImageCount(string $html): int
    {
        preg_match_all('/src="data:image\/png;base64,([A-Za-z0-9+\/=]+)"/', $html, $matches);

        $count = 0;
        foreach ($matches[1] as $payload) {
            $size = @getimagesizefromstring((string) base64_decode($payload, true));

            if ($size !== false && $size[0] === 300 && $size[1] === 300) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The inflated dompdf content stream now comes from
     * {@see ExtractsPdfContentStream} — one implementation for
     * the four badge suites, because four copies of it drifted into the same
     * `rtrim()` payload bug.
     */
}

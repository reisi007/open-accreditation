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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The `fit` contract of a badge picture — the one that was a dead key until
 * this suite existed: declared in the wire format, validated by zod AND by
 * `BadgeTemplateController`, defaulted to `contain` … and evaluated by nobody,
 * because the only thing the renderer emitted was an `object-fit` DECLARATION.
 *
 * dompdf implements no `object-fit` (measured: a card rendered with
 * `object-fit: contain` and one without it are byte-identical PDFs), so the
 * declaration fell through the cascade and `width:100%;height:100%` meant
 * STRETCH. The renderer now computes the drawn rectangle in millimetres.
 *
 * **What is pinned here, and why each case is load-bearing:**
 *
 * 1. `contain` on a non-square source in a non-square box — the whole image
 *    inside, centered, nothing cropped.
 * 2. `cover` on the SAME source and box — the box filled on the binding axis,
 *    the other axis cropped symmetrically.
 * 3. Both differ measurably, so (1) and (2) cannot be one branch in disguise.
 * 4. A SQUARE source in a SQUARE box makes them identical. This is the
 *    degenerate case a wrong implementation hides in — and the reason (5)
 *    exists: without it, (4) alone would also pass for an implementation that
 *    simply ignored `fit` altogether.
 * 5. A square source in a NON-square box still separates them. The guard that
 *    keeps (4) honest.
 * 6. Regression: a portrait WITHOUT a `fit` key (existing stored templates)
 *    still renders, is not empty, and gets the branch default.
 * 7. The placeholder goes through the same rule and comes out byte-identical to
 *    its own historical `min(w, h)` square geometry — the refactor consolidated
 *    two implementations into one without moving a single printed pixel.
 * 8. A degenerate box degrades to the plain image; the card always prints.
 *
 * Assertions are on MEASURED properties (is the rect inside the box? is the
 * aspect ratio preserved? is the leftover split evenly?), not only on literal
 * markup: {@see drawnRect()} fails loudly when the computed geometry is absent,
 * and {@see storePng()} asserts that the fixture really is the shape the test
 * claims — the two ways this kind of test silently passes while testing nothing.
 */
class BadgeImageFitGeometryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The renderer's print resolution: `BadgeRenderService::mm()` formats every
     * coordinate with `number_format(…, 2)`, so a value can never be expressed
     * finer than 0.01 mm on paper.
     */
    private const PRINT_STEP_MM = 0.01;

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
     | 1 — contain
     | ------------------------------------------------------------------- */

    public function test_contain_places_the_whole_image_inside_the_box_and_centers_the_leftover_axis(): void
    {
        // 60 × 80 portrait, 25 × 30 mm box. The HEIGHT binds (30/80 = 0.375 <
        // 25/60 = 0.4167), so the image lands 22.50 × 30.00 mm and the
        // leftover 2.50 mm of width is split 1.25 mm per side.
        $bytes = $this->storePng(60, 80, 'badge-images/verband-a/portrait.png');
        $rect = $this->drawnRect($this->renderImageEntry($bytes, 25, 30, 'contain'));

        $this->assertSame(
            ['left' => 1.25, 'top' => 0.0, 'width' => 22.5, 'height' => 30.0],
            $rect,
        );

        // Nothing cropped: the rectangle lies wholly inside the box …
        $this->assertGreaterThanOrEqual(0.0, $rect['left']);
        $this->assertGreaterThanOrEqual(0.0, $rect['top']);
        $this->assertLessThanOrEqual(25.0, $rect['left'] + $rect['width']);
        $this->assertLessThanOrEqual(30.0, $rect['top'] + $rect['height']);

        // … nothing stretched: the drawn aspect ratio is still the source's …
        $this->assertAspectPreserved($rect, 60, 80);

        // … and centered: the leftover axis is split evenly.
        $this->assertEqualsWithDelta(1.25, $rect['left'], 0.005);
        $this->assertEqualsWithDelta((25.0 - $rect['width']) / 2, $rect['left'], 0.005);
        $this->assertEqualsWithDelta((30.0 - $rect['height']) / 2, $rect['top'], 0.005);
    }

    /* ---------------------------------------------------------------------
     | 2 — cover
     | ------------------------------------------------------------------- */

    public function test_cover_fills_the_box_on_the_binding_axis_and_crops_the_other_symmetrically(): void
    {
        // Same source, same box, opposite decision. The WIDTH binds now
        // (0.4167 > 0.375), so the image is 25.00 × 33.33 mm and overhangs
        // vertically by 3.33 mm — centered, so 1.67 mm is cropped top and
        // bottom by the box's `overflow:hidden`. No letterbox anywhere.
        $bytes = $this->storePng(60, 80, 'badge-images/verband-a/portrait.png');
        $html = $this->renderImageEntry($bytes, 25, 30, 'cover');

        $rect = $this->drawnRect($html);

        $this->assertSame(
            ['left' => 0.0, 'top' => -1.67, 'width' => 25.0, 'height' => 33.33],
            $rect,
        );

        // The box is filled exactly on the binding axis …
        $this->assertEqualsWithDelta(25.0, $rect['width'], 0.005);
        // … the other axis genuinely overflows (that is the crop) …
        $cropTop = -$rect['top'];
        $cropBottom = $rect['top'] + $rect['height'] - 30.0;
        $this->assertGreaterThan(0.0, $cropTop);
        $this->assertGreaterThan(0.0, $cropBottom);
        // … the overflow is split evenly, so the crop is not off-center.
        //
        // Tolerance: TWO PRINT STEPS. `top` and `height` are each rounded to
        // 0.01 mm independently, so the true 1.6667 mm overhang is printed as
        // 1.67 above and 1.66 below, and their difference is then re-expressed
        // in float — where `1.67 - 1.66` is `0.0100000000000002`. A one-step
        // tolerance would fail on that float boundary rather than on geometry.
        // 0.02 mm is still 80× tighter than the 1.67 mm crop itself, so a real
        // off-center crop (which would be off by centimetres) cannot slip past.
        $this->assertEqualsWithDelta(1.6667, $cropTop, self::PRINT_STEP_MM);
        $this->assertEqualsWithDelta(1.6667, $cropBottom, self::PRINT_STEP_MM);
        $this->assertEqualsWithDelta(
            $cropTop,
            $cropBottom,
            2 * self::PRINT_STEP_MM,
            'the crop must be symmetric',
        );
        // … and still not stretched.
        $this->assertAspectPreserved($rect, 60, 80);

        // The box that crops it must actually clip — without `overflow:hidden`
        // the overhang would print over the rest of the card.
        $this->assertStringContainsString('overflow:hidden;"', $html);
    }

    /* ---------------------------------------------------------------------
     | 3 — the two decisions are really two
     | ------------------------------------------------------------------- */

    public function test_contain_and_cover_differ_measurably_on_the_same_source_and_box(): void
    {
        // Without this, cases 1 and 2 could both be satisfied by ONE branch and
        // the suite would still be green. The distinguishing observable: the
        // letterboxed axis. `contain` leaves empty space on one axis, `cover`
        // overhangs on one axis — never both, never neither.
        $bytes = $this->storePng(60, 80, 'badge-images/verband-a/portrait.png');

        $contain = $this->drawnRect($this->renderImageEntry($bytes, 25, 30, 'contain'));
        $cover = $this->drawnRect($this->renderImageEntry($bytes, 25, 30, 'cover'));

        $this->assertNotSame($contain, $cover, 'contain and cover drew the same rectangle');

        $containEmptyAxis = $contain['width'] < 25.0 - 0.005 || $contain['height'] < 30.0 - 0.005;
        $coverOverhangs = $cover['width'] > 25.0 + 0.005 || $cover['height'] > 30.0 + 0.005;

        $this->assertTrue($containEmptyAxis, 'contain must leave an axis empty (letterboxed)');
        $this->assertTrue($coverOverhangs, 'cover must overhang an axis (cropped)');

        // The two never both letterbox and never both overhang.
        $this->assertFalse(
            $cover['width'] < 25.0 - 0.005 || $cover['height'] < 30.0 - 0.005,
            'cover must not letterbox',
        );
        $this->assertFalse(
            $contain['width'] > 25.0 + 0.005 || $contain['height'] > 30.0 + 0.005,
            'contain must not overhang',
        );
    }

    /* ---------------------------------------------------------------------
     | 4 + 5 — the square degeneracy, and the guard that keeps it honest
     | ------------------------------------------------------------------- */

    public function test_a_square_source_in_a_square_box_makes_contain_and_cover_identical(): void
    {
        // There is nothing to decide: both axis factors are equal, so both
        // branches must draw the box exactly.
        $bytes = $this->storePng(60, 60, 'badge-images/verband-a/square.png');

        $containHtml = $this->renderImageEntry($bytes, 20, 20, 'contain');
        $coverHtml = $this->renderImageEntry($bytes, 20, 20, 'cover');

        $this->assertSame(
            ['left' => 0.0, 'top' => 0.0, 'width' => 20.0, 'height' => 20.0],
            $this->drawnRect($containHtml),
        );
        $this->assertSame(
            $this->drawnRect($coverHtml),
            $this->drawnRect($containHtml),
        );

        // The drawn ELEMENTS are identical apart from the retained `object-fit`
        // keyword — that keyword is a label of intent (idempotent on a renderer
        // that honours it), not a difference in what gets drawn, so demanding
        // byte equality of the whole tag would demand the renderer lie about the
        // decision. Normalising the keyword away, nothing may differ.
        //
        // Scoped to the picture on purpose: two `cardHtml` calls mint two
        // different QR tokens, so comparing whole cards would compare volatile
        // data and could not fail for the reason this test exists.
        $this->assertSame(
            $this->withoutFitKeyword($this->drawnImgTag($coverHtml)),
            $this->withoutFitKeyword($this->drawnImgTag($containHtml)),
            'contain/cover must draw the identical image element for a square source in a square box',
        );

        // No stretch either: 1.0 is both the source's and the box's aspect.
        $this->assertAspectPreserved($this->drawnRect($containHtml), 60, 60);
    }

    public function test_a_square_source_in_a_non_square_box_still_separates_contain_from_cover(): void
    {
        // The guard for the case above. If `fit` were ignored altogether, a
        // square source in a square box would "pass" case 4 while the feature
        // did not exist. Here the two MUST differ — which they only can if the
        // `fit` value actually reaches the geometry.
        $bytes = $this->storePng(60, 60, 'badge-images/verband-a/square.png');

        // 20 × 12 mm box: contain leaves the square whole at 12 × 12 with 4 mm
        // of empty width per side; cover fills the width and crops 4 mm top and
        // bottom.
        $contain = $this->drawnRect($this->renderImageEntry($bytes, 20, 12, 'contain'));
        $cover = $this->drawnRect($this->renderImageEntry($bytes, 20, 12, 'cover'));

        $this->assertSame(['left' => 4.0, 'top' => 0.0, 'width' => 12.0, 'height' => 12.0], $contain);
        $this->assertSame(['left' => 0.0, 'top' => -4.0, 'width' => 20.0, 'height' => 20.0], $cover);
        $this->assertNotSame($contain, $cover);
    }

    /* ---------------------------------------------------------------------
     | 6 — regression: existing stored templates (no `fit` key)
     | ------------------------------------------------------------------- */

    public function test_a_portrait_without_a_fit_key_still_renders_cover_geometry_and_is_never_empty(): void
    {
        // Existing templates predate `fit` entirely: the key is simply absent
        // from the stored layout. The branch default is `cover` for a portrait
        // (the historical markup declared exactly that), and such a card must
        // keep printing a portrait — an empty box here would silently blank
        // every already-configured badge.
        $application = $this->approvedApplication();
        $bytes = $this->storePortrait($application->user, 60, 80);

        $entry = ['field' => 'photo', 'x' => 5, 'y' => 25, 'w' => 25, 'h' => 30, 'size' => 12, 'align' => 'left'];
        $this->assertArrayNotHasKey('fit', $entry, 'the regression fixture must NOT carry a fit key');

        $html = $this->renderer->cardHtml($application, $this->makeTemplate([$entry]));

        // The portrait is embedded — not empty, and the box is still there.
        $this->assertStringContainsString('base64,'.base64_encode($bytes), $html);
        $this->assertStringContainsString(
            '<div style="position:absolute;left:5.00mm;top:25.00mm;width:25.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;overflow:hidden;">',
            $html,
        );

        // …and it is the cover geometry, undistorted.
        $this->assertSame(
            ['left' => 0.0, 'top' => -1.67, 'width' => 25.0, 'height' => 33.33],
            $this->drawnRect($html),
        );

        // The whole document still renders — a card must never fail to print.
        $pdf = $this->renderer->renderPdf(new Collection([$application]), $this->makeTemplate([$entry]));
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_a_photo_entry_honours_an_explicit_fit_from_the_layout(): void
    {
        // `layout.*.fit` is a wildcard in the controller, so a stored `photo`
        // entry may carry one even though the editor does not offer it. The
        // contract is uniform: a `fit` on the entry is the decision.
        $application = $this->approvedApplication();
        $this->storePortrait($application->user, 60, 80);

        $geometry = ['x' => 5, 'y' => 25, 'w' => 25, 'h' => 30, 'size' => 12, 'align' => 'left'];

        $this->assertSame(
            ['left' => 1.25, 'top' => 0.0, 'width' => 22.5, 'height' => 30.0],
            $this->drawnRect($this->renderer->cardHtml(
                $application,
                $this->makeTemplate([['field' => 'photo', ...$geometry, 'fit' => 'contain']]),
            )),
        );

        $this->assertSame(
            ['left' => 0.0, 'top' => -1.67, 'width' => 25.0, 'height' => 33.33],
            $this->drawnRect($this->renderer->cardHtml(
                $application,
                $this->makeTemplate([['field' => 'photo', ...$geometry, 'fit' => 'cover']]),
            )),
        );
    }

    public function test_an_image_entry_without_a_fit_key_defaults_to_contain(): void
    {
        // The mirror image of case 6: an `image` entry predates `fit` too, and
        // its default is `contain` (logos are not cropped). The two branches
        // defaulting DIFFERENTLY is intentional and is what keeps a re-saved
        // portrait box looking like a portrait box.
        $bytes = $this->storePng(60, 80, 'badge-images/verband-a/portrait.png');
        $image = $this->badgeImage($bytes, 'badge-images/verband-a/portrait.png');

        $entry = [
            'field' => 'image', 'x' => 5, 'y' => 130, 'w' => 25, 'h' => 30,
            'src' => ['kind' => 'upload', 'image_id' => $image->id],
        ];
        $this->assertArrayNotHasKey('fit', $entry, 'the regression fixture must NOT carry a fit key');

        $rect = $this->drawnRect($this->renderer->cardHtml(
            $this->approvedApplication(),
            $this->makeTemplate([$entry]),
        ));

        $this->assertSame(
            ['left' => 1.25, 'top' => 0.0, 'width' => 22.5, 'height' => 30.0],
            $rect,
            'an image entry without fit must contain, not cover',
        );
    }

    /* ---------------------------------------------------------------------
     | 7 — the placeholder rides the same rule, unmoved
     | ------------------------------------------------------------------- */

    public function test_the_placeholder_uses_the_same_rule_and_keeps_its_historical_geometry(): void
    {
        // The silhouette is a 512 × 512 SQUARE source, and the rule is general.
        // In a 25 × 30 mm box it must therefore land at 25 × 25 mm, centered —
        // which is exactly what the old square-only `min(w, h)` rule produced.
        // Byte-identical output is the point: consolidating the placeholder's
        // private geometry into the shared one must not move a printed pixel.
        $placeholder = app(BadgePhotoPlaceholder::class);
        $size = $placeholder->intrinsicSize();

        $this->assertNotNull($size, 'the bundled placeholder must be measurable');
        $this->assertSame($size[0], $size[1], 'the bundled placeholder is square — a precondition of this test');

        $application = $this->approvedApplication();
        $this->assertNull(
            $application->user->media()->where('type', 'portrait')->first(),
            'the placeholder branch requires an application WITHOUT a portrait',
        );

        $html = $this->renderer->cardHtml($application, $this->makeTemplate([
            ['field' => 'photo', 'x' => 5, 'y' => 25, 'w' => 25, 'h' => 30, 'size' => 12, 'align' => 'left'],
        ]));

        $this->assertSame(
            ['left' => 0.0, 'top' => 2.5, 'width' => 25.0, 'height' => 25.0],
            $this->drawnRect($html),
        );

        // …and in a square box the same rule degenerates to the box itself.
        $this->assertSame(
            ['left' => 0.0, 'top' => 0.0, 'width' => 30.0, 'height' => 30.0],
            $this->drawnRect($this->renderer->cardHtml($application, $this->makeTemplate([
                ['field' => 'photo', 'x' => 8, 'y' => 30, 'w' => 30, 'h' => 30, 'size' => 12, 'align' => 'left'],
            ]))),
        );
    }

    /* ---------------------------------------------------------------------
     | 8 — a degenerate box degrades instead of breaking the card
     | ------------------------------------------------------------------- */

    public function test_a_degenerate_box_falls_back_to_the_plain_image_and_the_card_still_prints(): void
    {
        // A 0 mm box is only reachable by bypassing the controller's 10 × 10 mm
        // minimum, but "the card always prints" outranks "the geometry is
        // correct": the fallback is the historical full-size image, and the
        // test asserts the DOCUMENT still renders.
        $bytes = $this->storePng(60, 80, 'badge-images/verband-a/portrait.png');
        $image = $this->badgeImage($bytes, 'badge-images/verband-a/portrait.png');

        $template = $this->makeTemplate([
            [
                'field' => 'image', 'x' => 5, 'y' => 130, 'w' => 0, 'h' => 0, 'fit' => 'cover',
                'src' => ['kind' => 'upload', 'image_id' => $image->id],
            ],
        ]);

        $html = $this->renderer->cardHtml($this->approvedApplication(), $template);

        $this->assertStringContainsString('width:100%;height:100%;object-fit:cover;', $html);
        $this->assertStringStartsWith('%PDF-', $this->renderer->renderPdf(
            new Collection([$this->approvedApplication()]),
            $template,
        ));
    }

    /* ---------------------------------------------------------------------
     | End-to-end: dompdf accepts the computed geometry
     | ------------------------------------------------------------------- */

    public function test_both_fits_render_through_dompdf(): void
    {
        $containBytes = $this->storePng(60, 80, 'badge-images/verband-a/contain.png');
        $coverBytes = $this->storePng(60, 80, 'badge-images/verband-a/cover.png');

        $template = $this->makeTemplate([
            [
                'field' => 'image', 'x' => 5, 'y' => 100, 'w' => 25, 'h' => 30, 'fit' => 'contain',
                'src' => ['kind' => 'upload', 'image_id' => $this->badgeImage($containBytes, 'badge-images/verband-a/contain.png')->id],
            ],
            [
                'field' => 'image', 'x' => 40, 'y' => 100, 'w' => 25, 'h' => 30, 'fit' => 'cover',
                'src' => ['kind' => 'upload', 'image_id' => $this->badgeImage($coverBytes, 'badge-images/verband-a/cover.png')->id],
            ],
        ]);

        $pdf = $this->renderer->renderPdf(new Collection([$this->approvedApplication()]), $template);

        $this->assertStringStartsWith('%PDF-', $pdf);
        // Two drawn image XObjects plus the QR: dompdf resolved all three data
        // URIs, i.e. the absolute mm geometry is valid CSS for it.
        $this->assertSame(3, substr_count($this->pdfText($pdf), ' Do'));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * The card markup of one `image` entry with the given fit.
     */
    private function renderImageEntry(string $bytes, float $w, float $h, string $fit): string
    {
        $path = 'badge-images/verband-a/fit-'.md5($fit.'-'.$w.'-'.$h).'.png';
        $image = $this->badgeImage($bytes, $path);

        return $this->renderer->cardHtml($this->approvedApplication(), $this->makeTemplate([
            [
                'field' => 'image',
                'x' => 5,
                'y' => 130,
                'w' => $w,
                'h' => $h,
                'fit' => $fit,
                'src' => ['kind' => 'upload', 'image_id' => $image->id],
            ],
        ]));
    }

    /**
     * The rectangle the renderer actually DREW, in mm, parsed back out of the
     * card markup.
     *
     * It fails loudly when no computed rectangle is present — i.e. when the
     * renderer fell back to `width:100%;height:100%`. A helper that returned
     * zeros for "nothing found" would let every case in this class pass while
     * the feature was absent, which is the specific way a fit test goes vacuous.
     *
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function drawnRect(string $html): array
    {
        // The tag now spans box + `<img>`, and the BOX repeats the same four
        // `left/top/width/height` numbers. The `<img>`'s own values are the
        // drawn rectangle, so the regex is anchored on `object-fit` — which only
        // the `<img>` carries — and the numbers are read from the LAST
        // occurrence, which is the one that follows it.
        $tag = $this->drawnImgTag($html);

        preg_match_all(
            '/left:(-?[\d.]+)mm;top:(-?[\d.]+)mm;width:([\d.]+)mm;height:([\d.]+)mm;/',
            $tag,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'the drawn image carries no millimetre geometry: '.$tag);

        $image = end($matches);
        $this->assertIsArray($image);

        return [
            'left' => (float) $image[1],
            'top' => (float) $image[2],
            'width' => (float) $image[3],
            'height' => (float) $image[4],
        ];
    }

    /**
     * The single `<img>` the renderer positioned by millimetres — the picture
     * under test.
     *
     * The QR is excluded BY BEING EXCLUDED FROM THE FIXTURE, not by a clever
     * regex: every template here renders a `photo`/`image` entry only, and
     * `makeTemplate()` does not add a fallback QR (see
     * `BadgeRenderServiceTest` for the card WITH a QR). The distinction matters
     * because P7 moved the QR onto the same mm geometry as every other picture:
     * before that change the QR's `width:100%;height:100%` was what kept it out
     * of this regex, and the docblock's claim that it "never matches" would have
     * become false while still reading as a guarantee.
     *
     * `assertCount(1)` is therefore now a real precondition — the card carries
     * exactly one picture — and not an accident of which branch happened to
     * render.
     */
    private function drawnImgTag(string $html): string
    {
        // The picture is located by its BOX, not by the `<img>` alone: the regex
        // requires the `left/top` box form that every `photo`/`image` entry
        // uses, and the capture group spans box + image together.
        //
        // This is what keeps the QR out, and it is a structural exclusion rather
        // than a lucky one. `BadgeRenderService::cardHtml` ALWAYS renders a QR —
        // a layout without a `qr` entry gets the historical bottom-right
        // fallback, whose box is `right:…;bottom:…` and therefore cannot match.
        // Since P7 that QR also carries mm geometry, so a regex matching the
        // `<img>` alone would find two geometries on every card; the old
        // docblock's claim that the QR "never matches" was true only while the
        // QR still stretched, and reading as a guarantee it was not.
        preg_match_all(
            '/<div style="position:absolute;left:-?[\d.]+mm;top:-?[\d.]+mm;width:[\d.]+mm;'
            .'height:[\d.]+mm;[^"]*overflow:hidden;"><img src="[^"]*" style="position:absolute;'
            .'left:(-?[\d.]+)mm;top:(-?[\d.]+)mm;width:([\d.]+)mm;height:([\d.]+)mm;'
            .'object-fit:(contain|cover);">/',
            $html,
            $matches,
        );

        $this->assertNotEmpty(
            $matches[0],
            'the card carries no computed fit geometry — the renderer used the stretch fallback, '
            .'so the fit was not applied at all. Markup: '.substr($html, 0, 400),
        );

        $this->assertCount(
            1,
            $matches[0],
            'expected exactly one computed picture geometry inside a left/top picture box. A second '
            .'one means a picture beyond the fixture leaked in — the QR shares this geometry since '
            .'P7 and must stay on its right/bottom fallback box.',
        );

        // Group 0 is the whole box+image match; the geometry the tests read is
        // in groups 1–4, so hand back the box+image pair for tag comparison and
        // let `drawnRect()` parse the numbers out of it.
        return (string) $matches[0][0];
    }

    /**
     * The drawn image tag with the retained `object-fit` keyword normalised
     * away, so two tags can be compared for what is actually DRAWN rather than
     * for which intent the markup documents.
     */
    private function withoutFitKeyword(string $imgTag): string
    {
        return (string) preg_replace('/object-fit:(contain|cover);/', 'object-fit:<fit>;', $imgTag);
    }

    /**
     * The drawn rectangle has the SOURCE's aspect ratio — i.e. the image was
     * scaled, not stretched. This is the assertion that separates "fitted" from
     * "squashed into the box", and the two decimal places the markup is printed
     * with are the tolerance.
     */
    private function assertAspectPreserved(array $rect, int $sourceW, int $sourceH): void
    {
        $this->assertGreaterThan(0.0, $rect['height'], 'a zero-height image is not a fit');
        $this->assertEqualsWithDelta(
            $sourceW / $sourceH,
            $rect['width'] / $rect['height'],
            0.01,
            'the drawn aspect ratio must be the source aspect ratio (no stretch)',
        );
    }

    /**
     * A real decodable PNG of EXACTLY `$width` × `$height` on the private disk.
     *
     * The size is asserted here, not assumed: a fixture that silently came out
     * square would make every "non-square" case in this class pass for the wrong
     * reason — and the square/non-square distinction is the whole subject.
     */
    private function storePng(int $width, int $height, string $path): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 40, 90, 160));
        imagefilledellipse(
            $image,
            intdiv($width, 2),
            intdiv($height, 2),
            max(1, min(28, $width - 2)),
            max(1, min(28, $height - 2)),
            imagecolorallocate($image, 230, 200, 150),
        );

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $measured = @getimagesizefromstring($bytes);
        $this->assertIsArray($measured, 'the fixture must be a decodable image');
        $this->assertSame(
            [$width, $height],
            [(int) $measured[0], (int) $measured[1]],
            'the fixture must have exactly the source size the test claims',
        );

        Storage::disk('private')->put($path, $bytes);

        return $bytes;
    }

    /**
     * A REAL decodable PNG registered as the user's `portrait` media row.
     */
    private function storePortrait(User $user, int $width, int $height): string
    {
        $bytes = $this->storePng($width, $height, "user-media/verband-a/{$user->id}/portrait-{$width}x{$height}.png");

        UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => "user-media/verband-a/{$user->id}/portrait-{$width}x{$height}.png",
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'original_name' => 'portrait.png',
        ]);

        return $bytes;
    }

    private function badgeImage(string $bytes, string $path): BadgeImage
    {
        Storage::disk('private')->put($path, $bytes);

        return BadgeImage::create([
            'mandant_id' => $this->mandant->id,
            'path' => $path,
            'mime' => 'image/png',
            'original_name' => 'badge.png',
        ]);
    }

    /**
     * The card under test, from the given layout.
     *
     * It deliberately carries **no** `qr` entry AND no `photo`/`image` entry
     * beyond the one the caller added, so the card holds exactly one picture.
     * That is what keeps `drawnImgTag()`'s `assertCount(1)` meaningful: since
     * P7 the QR shares the same mm geometry as every other picture, so a
     * fallback QR (which `BadgeRenderService` renders at the historical
     * bottom-right spot whenever the layout omits a `qr` entry) WOULD match the
     * regex and turn every case here into a two-geometry card.
     */
    private function makeTemplate(array $layout): BadgeTemplate
    {
        return BadgeTemplate::create([
            'mandant_id' => $this->mandant->id,
            'name' => 'Presseausweis',
            'layout' => $layout,
            'is_default' => false,
        ]);
    }

    private function approvedApplication(): Application
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.Application::query()->count(),
        ]);

        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $user = User::factory()->create(['name' => 'Jane Doe']);

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    /**
     * The inflated dompdf content stream (see BadgeRenderServiceTest::pdfText).
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

            $inflated = @gzuncompress(rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart)))
                ?: @gzinflate(rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart)));

            if ($inflated !== false) {
                $text .= $inflated;
            }

            $offset = $dataEnd;
        }

        return str_replace("\x00", '', $text);
    }
}

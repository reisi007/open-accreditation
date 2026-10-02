<?php

namespace Tests\Feature;

use App\Exceptions\BadgeTextFontUnresolvedException;
use App\Models\Application;
use App\Models\BadgeTemplate;
use App\Models\Mandant;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\BadgeRenderService;
use App\Services\BadgeTextFitter;
use App\Support\MandantContext;
use Dompdf\Css\Style;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExtractsPdfContentStream;
use Tests\TestCase;

/**
 * Position 9 — a badge field's text WRAPS instead of overflowing, and the font
 * shrinks until the WRAPPED text fits its box.
 *
 * The defect this pins: `renderField()` used to emit `font-size:%dpt` verbatim
 * from the template (only clamped by `max(1, …)`), so a 14 pt name in a
 * 40 × 8 mm box wrapped to two lines of 43.02 pt inside a 22.68 pt box and
 * overlapped the field below it. Measured, printed, not a hunch.
 *
 * **The decision under test (Nutzerentscheidung 2026-09-28): never truncate.**
 * A truncated name on a badge does not identify the person unambiguously, and
 * printed is printed — so `overflow:hidden`, an ellipsis and `text-overflow` are
 * rejected on a text field, and the tests below pin their ABSENCE.
 *
 * ## Why the tests measure for themselves
 *
 * Every "does it fit" assertion here measures with its OWN `Dompdf` and
 * `FontMetrics`, never with `BadgeTextFitter`. Re-using the production fitter to
 * check the production fitter would pass for the wrong reason: a bug in the
 * height model would cancel itself out. The metrics are deliberately the same
 * ones dompdf prints with — that is the design — but the CODE PATH is
 * independent, so a wrong number cannot validate itself.
 */
class BadgeTextAutoFitTest extends TestCase
{
    use ExtractsPdfContentStream;
    use RefreshDatabase;

    private Mandant $mandant;

    private BadgeRenderService $renderer;

    private Dompdf $dompdf;

    /**
     * The board's own reproduction: 14 pt in a 40 × 8 mm box.
     */
    private const OVERFLOWING = ['x' => 10.0, 'y' => 10.0, 'w' => 40.0, 'h' => 8.0, 'size' => 14];

    /** Comfortably wide and tall: "Jane Doe" at 14 pt fits on one line. */
    private const COMFORTABLE = ['x' => 10.0, 'y' => 10.0, 'w' => 80.0, 'h' => 10.0, 'size' => 14];

    /**
     * A name too long for one line at any size that still fits the box height —
     * so the multi-line path is unavoidable and cannot be avoided by shrinking.
     */
    private const LONG_NAME = 'Konstantin Alexandrowitsch Wassiljewitsch von Testhausen-Müller';

    /**
     * The same shape, ASCII only — for the assertions that compare against the
     * PDF content stream.
     *
     * dompdf's subset font writes text as GLYPH CODES, not UTF-8: MEASURED, the
     * `ü` of `Müller` reaches the page as the single byte `0xFC`, where UTF-8
     * would have written `0xC3 0xBC`. Comparing a UTF-8 expectation against the
     * stream would therefore fail on a correct PDF, so the geometry tests below
     * stay ASCII and the non-ASCII case has its own, byte-level test.
     */
    private const LONG_NAME_ASCII = 'Konstantin Alexandrowitsch Wassiljewitsch von Testhausen';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        MandantContext::set($this->mandant);
        $this->renderer = app(BadgeRenderService::class);
        $this->dompdf = new Dompdf;
        $this->dompdf->loadHtml('<html><body>x</body></html>', 'UTF-8');
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | 1. Wrapping, not truncation
     | ------------------------------------------------------------------- */

    public function test_a_long_name_wraps_into_multiple_lines_and_is_never_truncated(): void
    {
        $html = $this->renderCard(self::LONG_NAME, self::OVERFLOWING);
        $field = $this->fieldDiv($html, self::OVERFLOWING);

        // Multiple lines, and they are REAL lines: `<br>` is dompdf's own
        // `display:-dompdf-br` frame (FrameReflower\Inline::reflow), which adds
        // a line box — not a marker some consumer might ignore. N lines carry
        // N-1 breaks, and the two are tied to each other so neither can drift.
        $breaks = substr_count($field, '<br>');
        $lines = $this->printedLines($field);

        $this->assertGreaterThan(1, count($lines), 'an overflowing field must WRAP into several lines');
        $this->assertSame(
            count($lines) - 1,
            $breaks,
            'the printed lines and the <br>s that separate them must agree',
        );

        // **The rejection, pinned as an absence.** `overflow:hidden` would crop
        // the last line, an ellipsis or `text-overflow` would cut it; all three
        // are rejected (Nutzerentscheidung 2026-09-28). The card's QR box does
        // legitimately carry `overflow:hidden`, so this is scoped to the field's
        // own div.
        $this->assertStringNotContainsString('overflow:hidden', $field, 'a text field must never clip its text');
        $this->assertStringNotContainsString('text-overflow', $field);
        $this->assertStringNotContainsString('…', $field, 'no ellipsis — a truncated name identifies nobody');
        $this->assertStringNotContainsString('...', $field);

        // Nothing was dropped. A line break REPLACES the separator it breaks at —
        // that is what a line break is, and it is why the concatenation of the
        // lines is compared with the name's whitespace removed rather than
        // verbatim. What must survive is every letter, and every line must be a
        // contiguous part of the name in its original order (no reordering, no
        // invented text). The separator-at-a-hyphen case has its own test.
        $printed = $this->printedText($field);
        $withoutSpace = static fn (string $value): string => (string) preg_replace('/\s+/u', '', $value);

        $this->assertSame(
            $withoutSpace(self::LONG_NAME),
            $withoutSpace($printed),
            'wrapping must lose no characters',
        );
        foreach ($this->printedLines($field) as $line) {
            $this->assertStringContainsString(
                html_entity_decode($line, ENT_QUOTES, 'UTF-8'),
                self::LONG_NAME,
                'every printed line must be a contiguous part of the name',
            );
        }
    }

    public function test_every_wrapped_line_is_narrower_than_the_box_and_all_of_them_fit_its_height(): void
    {
        $field = $this->fieldDiv($this->renderCard(self::LONG_NAME, self::OVERFLOWING), self::OVERFLOWING);
        $size = (float) $this->fontSizeOf($field);
        $lines = $this->printedLines($field);
        $font = $this->resolvedFont();

        $this->assertGreaterThan(1, count($lines));

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(
                self::OVERFLOWING['w'] * BadgeTextFitter::MM_TO_PT,
                $this->width($line, $font, $size),
                sprintf('line "%s" is wider than the box', $line),
            );
        }

        $this->assertLessThanOrEqual(
            self::OVERFLOWING['h'] * BadgeTextFitter::MM_TO_PT,
            count($lines) * $this->lineHeight($font, $size),
            sprintf('%d wrapped lines do not fit the box height', count($lines)),
        );
    }

    /* ---------------------------------------------------------------------
     | 2. Shrink-to-fit
     | ------------------------------------------------------------------- */

    public function test_text_that_does_not_fit_is_shrunk_until_the_wrapped_text_fits(): void
    {
        $field = $this->fieldDiv($this->renderCard('Max Mustermann', self::OVERFLOWING), self::OVERFLOWING);
        $size = (float) $this->fontSizeOf($field);
        $font = $this->resolvedFont();

        // The precondition, measured rather than assumed: at the CONFIGURED
        // 14 pt the name does NOT fit — that is the reported defect.
        // MEASURED: 121.478 pt wide against 113.386 pt of box, so two lines;
        // 2 × 21.511 = 43.021 pt tall against 22.677 pt of box.
        $this->assertGreaterThan(
            self::OVERFLOWING['w'] * BadgeTextFitter::MM_TO_PT,
            $this->width('Max Mustermann', $font, (float) self::OVERFLOWING['size']),
            'precondition: the name is wider than the box at the configured size',
        );
        $this->assertGreaterThan(
            self::OVERFLOWING['h'] * BadgeTextFitter::MM_TO_PT,
            2 * $this->lineHeight($font, (float) self::OVERFLOWING['size']),
            'precondition: two lines at the configured size are taller than the box',
        );

        // The shrink happened …
        $this->assertLessThan(
            (float) self::OVERFLOWING['size'],
            $size,
            'a field that does not fit must be shrunk, not printed at its configured size',
        );

        // … and the wrapped text now fits, which is the whole promise.
        $lines = $this->printedLines($field);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(
                self::OVERFLOWING['w'] * BadgeTextFitter::MM_TO_PT,
                $this->width($line, $font, $size),
            );
        }
        $this->assertLessThanOrEqual(
            self::OVERFLOWING['h'] * BadgeTextFitter::MM_TO_PT,
            count($lines) * $this->lineHeight($font, $size),
        );
    }

    public function test_text_that_already_fits_keeps_its_configured_size(): void
    {
        // The other half of the contract, and the one that protects every badge
        // printed before this position existed: no overflow means no change.
        // MEASURED: "Jane Doe" at 14 pt is 62.608 pt wide and one 21.511 pt line
        // tall, inside 226.772 × 28.346 pt.
        $field = $this->fieldDiv($this->renderCard('Jane Doe', self::COMFORTABLE), self::COMFORTABLE);

        $this->assertSame('14', $this->fontSizeOf($field));
        $this->assertSame(['Jane Doe'], $this->printedLines($field));
        $this->assertStringNotContainsString('<br>', $field);
    }

    public function test_the_measured_geometry_limit_is_reported_instead_of_hidden(): void
    {
        // Position 9's open remainder: below a lower bound even the WRAPPED text
        // cannot fit, and an absolutely positioned field can then only overlap.
        // That is a limit of the geometry, not an open question — so the fitter
        // stops at MIN_FONT_PT and reports `fits === false` instead of claiming
        // a fit it cannot deliver.
        $name = str_repeat('Unbreakable', 12);
        $fit = (new BadgeTextFitter)->fit($name, 4.0, 4.0, 14.0);

        $this->assertSame(BadgeTextFitter::MIN_FONT_PT, $fit->fontSizePt, 'the shrink stops at the floor');
        $this->assertFalse($fit->fits, 'the geometry limit must be reported, not papered over');
        $this->assertGreaterThan(1, count($fit->lines), 'the text still wraps — nothing is truncated');
        $this->assertSame($name, implode('', $fit->lines), 'even at the floor the name is complete');
    }

    public function test_a_hyphenated_name_breaks_at_the_hyphen_and_not_mid_word(): void
    {
        // The board's named trap: character-wise FIRST would shatter
        // "Müller-Schmidt" into "Müller-Sc" / "hmidt". dompdf's own break
        // pattern treats a run of hyphens as a break opportunity
        // (FrameReflower\Text::$_wordbreak_pattern), so the fit breaks there and
        // the hyphen stays on the first line.
        $fit = (new BadgeTextFitter)->fit('Müller-Schmidt', 12.0, 8.0, 14.0);

        $this->assertTrue($fit->isMultiLine(), 'the name does not fit a 12 mm box on one line');
        $this->assertSame(['Müller-', 'Schmidt'], $fit->lines);
    }

    public function test_an_unbreakable_token_is_broken_character_wise_only_as_a_last_resort(): void
    {
        // Character-wise breaking still exists — a single token with no break
        // opportunity left has to go somewhere, and the position requires it
        // rather than an overflow — but it is reached only after words and
        // hyphens have been tried.
        $fit = (new BadgeTextFitter)->fit('UnbreakableSupercalifragilistic', 25.0, 8.0, 14.0);

        $this->assertTrue($fit->isMultiLine());
        $this->assertSame('UnbreakableSupercalifragilistic', implode('', $fit->lines), 'lossless');
        foreach ($fit->lines as $line) {
            $this->assertStringStartsNotWith(' ', $line);
        }
    }

    /* ---------------------------------------------------------------------
     | 3. The image path is untouched
     | ------------------------------------------------------------------- */

    public function test_the_photo_field_is_not_auto_fitted(): void
    {
        $bytes = $this->portraitPng();
        $entry = ['x' => 8.0, 'y' => 30.0, 'w' => 25.0, 'h' => 30.0, 'size' => 12];
        $application = $this->application('Fotograf:in', withPortrait: true);

        $field = $this->fieldDiv($this->renderCardOn($application, $entry, 'photo'), $entry);

        // The `photo` branch has no font and no line box: it keeps its
        // configured size, keeps its `<img>` and its `cover` geometry, and the
        // auto-fit never touched it.
        $this->assertSame('12', $this->fontSizeOf($field));
        $this->assertSame(1, substr_count($field, '<img'));
        $this->assertStringContainsString('object-fit:cover', $field);
        $this->assertStringContainsString(base64_encode($bytes), $field);
        $this->assertStringNotContainsString('<br>', $field);
    }

    public function test_a_photo_box_too_short_for_any_line_still_renders_its_image(): void
    {
        // The non-vacuity guard for the test above: a 4 × 2 mm box cannot hold a
        // single line of text at 14 pt, so had the photo path gone through the
        // auto-fit its image would have been replaced by a tower of
        // one-character lines. The image must survive verbatim.
        $this->portraitPng();
        $entry = ['x' => 8.0, 'y' => 30.0, 'w' => 4.0, 'h' => 2.0, 'size' => 14];
        $application = $this->application('Fotograf:in', withPortrait: true);

        $field = $this->fieldDiv($this->renderCardOn($application, $entry, 'photo'), $entry);

        $this->assertSame('14', $this->fontSizeOf($field), 'the photo box keeps its configured size');
        $this->assertSame(1, substr_count($field, '<img'));
        $this->assertStringNotContainsString('<br>', $field);
        $this->assertStringContainsString('<img src="data:image/png;base64,', $field);
    }

    /* ---------------------------------------------------------------------
     | 4. An unresolvable font family fails loudly
     | ------------------------------------------------------------------- */

    public function test_an_unresolvable_family_fails_loudly_instead_of_measuring_a_different_font(): void
    {
        // **The board statement this corrects.** Position 9's row says
        // `FontMetrics::getFont()` THROWS on an unknown family. It does not:
        // `FontMetrics.php:457-527` returns NULL on the miss. Nor does
        // `getTextWidth()` validate its `$font` — it forwards to
        // `Cpdf::selectFont()`, which returns the CURRENTLY SELECTED font for an
        // empty name, so a null font yields a plausible, wrong width.
        $this->assertNull($this->metrics()->getFont('Definitely Not A Font'), 'getFont() returns null, it does not throw');

        $nullWidth = $this->metrics()->getTextWidth('Max', null, 12.0);
        $this->assertGreaterThan(0.0, $nullWidth, 'a null font yields a plausible number, not a failure');
        $this->assertNotEqualsWithDelta(
            $this->width('Max', $this->resolvedFont(), 12.0),
            $nullWidth,
            1e-6,
            'the wrong number differs from the printed face — which is why it must never be used silently',
        );

        // So the fitter raises the failure ITSELF, rather than inheriting a
        // vendor behaviour that does not exist.
        $this->expectException(BadgeTextFontUnresolvedException::class);

        (new BadgeTextFitter('Definitely Not A Font, Also Not A Font'))->fit(
            'Max Mustermann',
            40.0,
            8.0,
            14.0,
            'Presseausweis',
        );
    }

    public function test_the_loud_failure_names_the_family_list_and_the_template(): void
    {
        try {
            (new BadgeTextFitter('Definitely Not A Font'))->fit('Max', 40.0, 8.0, 14.0, 'Presseausweis');
            $this->fail('an unresolvable family must not return a width');
        } catch (BadgeTextFontUnresolvedException $e) {
            $this->assertSame('Definitely Not A Font', $e->families);
            $this->assertSame('Presseausweis', $e->template);
            $this->assertStringContainsString('Definitely Not A Font', $e->getMessage());
            $this->assertStringContainsString('Presseausweis', $e->getMessage());
        }
    }

    public function test_the_card_measures_the_family_it_actually_prints(): void
    {
        // The `sans-serif` trap: the card's CSS is `DejaVu Sans, sans-serif` and
        // BOTH resolve — to different faces. Measuring `sans-serif` would size a
        // font nobody prints. The fitter resolves the FIRST family, exactly as
        // dompdf's `Style::_get_font_family()` does.
        $fitter = new BadgeTextFitter;

        $this->assertSame(['DejaVu Sans', 'sans-serif'], $fitter->families());
        $this->assertStringEndsWith('DejaVuSans', $fitter->resolvedFont());
        $this->assertStringEndsWith('Helvetica', (new BadgeTextFitter('sans-serif'))->resolvedFont());

        // And the PRINTED card really embeds that face — the whole point of
        // walking the list instead of guessing one entry. dompdf subsets the
        // font, so the embedded name carries a tag prefix ("SUBAAB+DejaVuSans"
        // on this run); the face behind the prefix is what is asserted.
        $pdf = $this->renderer->renderPdf(
            new Collection([$this->application('Jane Doe')]),
            $this->template(self::COMFORTABLE),
        );

        $this->assertMatchesRegularExpression(
            '/BaseFont\s*\/\w*\+?DejaVuSans/',
            $pdf,
            'the card must print the family the auto-fit measures',
        );
        $this->assertStringNotContainsString('Helvetica', $pdf);
    }

    /* ---------------------------------------------------------------------
     | End-to-end: the printed PDF, not just the markup
     | ------------------------------------------------------------------- */

    public function test_the_printed_pdf_draws_every_wrapped_line_at_its_own_y(): void
    {
        // "the `<br>` is present" is not "the PDF has two lines". Measured in the
        // content stream: two text-showing operators, at two DIFFERENT y
        // positions, top to bottom.
        $application = $this->application(self::LONG_NAME_ASCII);
        $template = $this->template(self::OVERFLOWING);

        $pdf = $this->renderer->renderPdf(new Collection([$application]), $template);
        $this->assertStringStartsWith('%PDF-', $pdf);

        $lines = $this->printedLines($this->fieldDiv(
            $this->renderer->cardHtml($application, $template),
            self::OVERFLOWING,
        ));
        $this->assertGreaterThan(1, count($lines), 'precondition: the field wrapped');

        $runs = $this->shownTextRuns($pdf);
        $ys = [];

        foreach ($lines as $line) {
            $y = null;
            foreach ($runs as [$runY, $runText]) {
                if ($runText === $line) {
                    $y = $runY;
                    break;
                }
            }

            $this->assertNotNull($y, sprintf('line "%s" is not drawn on the page', $line));
            $ys[] = $y;
        }

        // Distinct y per line, top to bottom (PDF y grows upwards, so the later
        // line carries the SMALLER number).
        $this->assertCount(count($lines), array_unique($ys), 'every line is drawn at its own y');

        $topToBottom = $ys;
        rsort($topToBottom);
        $this->assertSame($topToBottom, $ys, 'the lines are printed top to bottom');
    }

    public function test_a_long_name_survives_the_whole_export_path(): void
    {
        // The name must arrive on the page COMPLETE, in full, across its line
        // breaks. Asserted over the drawn text runs rather than the raw stream:
        // the extractor concatenates every stream in the PDF, font programs
        // included, so a substring assertion against it reports binary noise
        // instead of the actual cause.
        $application = $this->application(self::LONG_NAME_ASCII);
        $pdf = $this->renderer->renderPdf(new Collection([$application]), $this->template(self::OVERFLOWING));

        $this->assertStringStartsWith('%PDF-', $pdf);

        $drawn = array_column($this->shownTextRuns($pdf), 1);

        $this->assertGreaterThan(1, count($drawn), 'the name is drawn on more than one line');

        // Whitespace-insensitive, because a line break REPLACES the separator it
        // breaks at — that is what a line break is. The letters must all be there.
        $withoutSpace = static fn (string $value): string => (string) preg_replace('/\s+/', '', $value);

        $this->assertSame(
            $withoutSpace(self::LONG_NAME_ASCII),
            $withoutSpace(implode('', $drawn)),
            'nothing may be lost between the wrap and the page',
        );
    }

    public function test_an_umlaut_reaches_the_page_as_its_glyph_code(): void
    {
        // The non-ASCII counterpart of the test above, at the level where it can
        // actually be asserted: dompdf's subset font writes GLYPH CODES, so the
        // `ü` of `Müller` is the single byte 0xFC on the page (measured), where
        // UTF-8 would have written 0xC3 0xBC. The byte is what the printer
        // receives, so the byte is what is asserted — a decoded-"ü" comparison
        // would fail on a perfectly correct PDF.
        $application = $this->application('Müller-Schmidt');
        $pdf = $this->renderer->renderPdf(new Collection([$application]), $this->template(self::COMFORTABLE));

        $text = $this->pdfText($pdf);

        $this->assertTrue(
            str_contains($text, "M\xFCller-Schmidt"),
            'the umlaut must reach the page encoded, not dropped or replaced',
        );
        $this->assertTrue(
            str_contains($text, 'Testhausen') === false,
            'sanity: only the application\'s own text belongs on this card',
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers — rendering
     | ------------------------------------------------------------------- */

    /**
     * @param  array{x: float, y: float, w: float, h: float, size: int}  $entry
     */
    private function renderCard(string $name, array $entry, string $field = 'name'): string
    {
        return $this->renderCardOn($this->application($name), $entry, $field);
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float, size: int}  $entry
     */
    private function renderCardOn(Application $application, array $entry, string $field = 'name'): string
    {
        return $this->renderer->cardHtml($application, $this->template($entry, $field));
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float, size: int}  $entry
     */
    private function template(array $entry, string $field = 'name'): BadgeTemplate
    {
        return BadgeTemplate::create([
            'mandant_id' => $this->mandant->id,
            'name' => 'Presseausweis',
            'layout' => [[
                'field' => $field,
                'x' => $entry['x'],
                'y' => $entry['y'],
                'w' => $entry['w'],
                'h' => $entry['h'],
                'size' => $entry['size'],
                'align' => 'left',
            ]],
            'is_default' => false,
        ]);
    }

    private function application(string $name, bool $withPortrait = false): Application
    {
        $category = $this->mandant->categories()->create(['name' => 'Presse', 'slug' => 'presse-'.uniqid()]);
        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);
        $user = User::factory()->create(['name' => $name]);

        if ($withPortrait) {
            UserMedia::create([
                'user_id' => $user->id,
                'type' => 'portrait',
                'path' => 'user-media/verband-a/portrait.png',
                'mime' => 'image/png',
                'size' => strlen($this->portraitPng()),
                'original_name' => 'portrait.png',
            ]);
        }

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    /**
     * A REAL decodable PNG on the private disk — so the photo branch takes its
     * portrait branch, not the placeholder fallback, and the bytes can be
     * matched in the markup.
     */
    private function portraitPng(): string
    {
        $image = imagecreatetruecolor(60, 80);
        imagefilledrectangle($image, 0, 0, 59, 79, imagecolorallocate($image, 40, 90, 160));
        imagefilledellipse($image, 30, 28, 28, 28, imagecolorallocate($image, 230, 200, 150));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('private')->put('user-media/verband-a/portrait.png', $bytes);

        return $bytes;
    }

    /* ---------------------------------------------------------------------
     | Helpers — markup
     | ------------------------------------------------------------------- */

    /**
     * The rendered box div of one layout entry, matched on its geometry.
     *
     * @param  array{x: float, y: float, w: float, h: float, size?: int}  $entry
     */
    private function fieldDiv(string $html, array $entry): string
    {
        $needle = sprintf(
            'left:%smm;top:%smm;width:%smm;height:%smm;',
            number_format($entry['x'], 2, '.', ''),
            number_format($entry['y'], 2, '.', ''),
            number_format($entry['w'], 2, '.', ''),
            number_format($entry['h'], 2, '.', ''),
        );

        $styleAt = strpos($html, $needle);
        $this->assertNotFalse($styleAt, 'the layout entry is not present on the card');

        // The needle sits INSIDE the div's own style attribute, so the opening
        // tag has to be searched BEHIND it — a forward search would find the QR
        // box, which also carries `left:`/`top:`.
        $open = strrpos(substr($html, 0, $styleAt + 1), '<div style="');
        $this->assertNotFalse($open);

        $end = strpos($html, '</div>', $styleAt);
        $this->assertNotFalse($end);

        return substr($html, $open, $end - $open);
    }

    /**
     * The lines a text field prints, split on the `<br>`s the fit emitted.
     *
     * @return list<string>
     */
    private function printedLines(string $field): array
    {
        $matched = preg_match('#^<div style="[^"]*">(.*)$#s', $field, $matches);
        $this->assertSame(1, $matched, 'the field is not one div with an inline style');

        $lines = array_map('trim', explode('<br>', $matches[1]));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * The field's printed lines, unescaped and concatenated — the check that
     * wrapping lost nothing.
     */
    private function printedText(string $field): string
    {
        return implode('', array_map(
            static fn (string $line): string => html_entity_decode($line, ENT_QUOTES, 'UTF-8'),
            $this->printedLines($field),
        ));
    }

    private function fontSizeOf(string $field): string
    {
        preg_match('/font-size:([\d.]+)pt/', $field, $matches);
        $this->assertArrayHasKey(1, $matches, 'the field carries no font-size');

        return $matches[1];
    }

    /* ---------------------------------------------------------------------
     | Helpers — independent measurement (never the production fitter)
     | ------------------------------------------------------------------- */

    private function metrics(): FontMetrics
    {
        return $this->dompdf->getFontMetrics();
    }

    private function width(string $text, string $font, float $size): float
    {
        return $this->metrics()->getTextWidth($text, $font, $size);
    }

    /**
     * The printed height of one line: dompdf's `line-height: normal` times the
     * canvas font height. `Style::$default_line_height` is READ from the vendor
     * property rather than copied into this test, so the expectation cannot
     * drift from the renderer that will actually print.
     */
    private function lineHeight(string $font, float $size): float
    {
        return Style::$default_line_height * $this->metrics()->getFontHeight($font, $size);
    }

    private function resolvedFont(): string
    {
        $font = $this->metrics()->getFont('DejaVu Sans');
        $this->assertNotNull($font);

        return $font;
    }

    /**
     * Every text-showing operator of the PDF as `[y, text]`, in draw order.
     *
     * dompdf writes each run as `x y Td /Fn <size> Tf [(text)] TJ`, so the
     * font operator sits BETWEEN the placement and the text and has to be part
     * of the pattern.
     *
     * The captured text is bounded by a CHARACTER CLASS rather than a lazy
     * quantifier on purpose: {@see ExtractsPdfContentStream} concatenates every
     * stream in the document — the embedded font programs come along, so the
     * haystack is ~100 kB of binary. A lazy `.*?` runs into
     * `pcre.backtrack_limit` on that, `preg_match_all` returns `false`, and the
     * test would silently see ZERO text runs — a green failure mode rather than
     * a red one.
     *
     * @return list<array{0: float, 1: string}>
     */
    private function shownTextRuns(string $pdf): array
    {
        $matched = preg_match_all(
            '/([\d.]+) ([\d.]+) Td\s*\/F\d+ [\d.]+ Tf\s*\[\(([^)\]]{0,400})\)\]\s*TJ/',
            $this->pdfText($pdf),
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotFalse($matched, 'the PDF content stream could not be scanned');

        return array_map(
            static fn (array $match): array => [(float) $match[2], $match[3]],
            $matches,
        );
    }
}

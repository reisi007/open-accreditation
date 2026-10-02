<?php

namespace App\Services;

use App\Exceptions\BadgeTextFontUnresolvedException;
use Dompdf\Css\Style;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * Auto-fit for the TEXT fields of a badge card (Position 9).
 *
 * ## The contract
 *
 * A field's text is wrapped to the box WIDTH and, if the wrapped text does not
 * fit the box HEIGHT, the font is shrunk until it does. Text is NEVER truncated:
 * there is no `overflow:hidden`, no ellipsis and no `text-overflow` anywhere in
 * this class or in the markup {@see BadgeRenderService} emits for it, because a
 * truncated name on a badge does not identify the person unambiguously — and
 * printed is printed (Nutzerentscheidung 2026-09-28).
 *
 * ## Why this lives in the PRINT path and not in the editor
 *
 * The editor approximates the same promise with CSS `min()` against container
 * units (`badgeCanvasFontSizeCss`) — but a template has no person: it shows
 * `SAMPLE_TEXT.name = "Max Mustermann"`. The editor is structurally optimistic
 * and cannot be made honest about a real name. So the robustness belongs here.
 *
 * ## Why the measurement is dompdf's own, not an estimate
 *
 * Every width and every line height comes from `Dompdf\FontMetrics`, which
 * delegates to `$this->canvas->get_text_width()` — the same canvas class, the
 * same font directory and the same `Options` that {@see BadgeRenderService}
 * renders with (it constructs `new Dompdf` with NO options, and this class
 * does the same, so both see dompdf's bundled `lib/fonts`). Preview and print
 * therefore agree BY CONSTRUCTION rather than by luck.
 *
 * ## The height model, and why it is dompdf's model
 *
 * The height of one rendered line is `LINE_HEIGHT_FACTOR × getFontHeight(font,
 * size)`:
 *
 * - `LINE_HEIGHT_FACTOR` is read from `Style::$default_line_height` (1.2),
 *   which is what `line-height: normal` resolves to — dompdf's
 *   `_get_line_height()` multiplies it with the font size, and
 *   `FrameDecorator\Text::get_margin_height()` (`Text.php:136-144`) divides the
 *   result back by the font size before scaling `getFontHeight()`. The two
 *   cancel, so the rendered line box is exactly this product.
 * - `getFontHeight()` already carries dompdf's `Options::$fontHeightRatio`
 *   (1.1), because `Adapter\CPDF::get_font_height()` multiplies by it. That
 *   ratio is therefore NOT duplicated as a constant here — changing dompdf's
 *   option moves the fit with it.
 *
 * MEASURED against the rendered PDF, not derived: a 5 pt two-line field prints
 * its two text runs at y 385.038 and 377.355 → an advance of 7.683 pt, which is
 * `1.2 × getFontHeight(5 pt) = 1.2 × 6.402 = 7.6824` to within the 3-decimal
 * resolution of the PDF operator. At 3 pt the measured advance is 4.609 pt
 * against a modelled 4.6094. The box-height check and the printed line height
 * are the same model — which is the only reason the check can be trusted.
 *
 * ## The measured limit of the geometry (Position 9's open remainder)
 *
 * `MM_TO_PT` is 72/25.4 = 2.834645669, so a **40 × 8 mm box is 113.3858 ×
 * 22.6772 pt** and one line of DejaVu Sans at size `s` costs `1.53648 × s` pt.
 * One line therefore needs `s ≤ 22.6772 / 1.53648 = 14.7599 pt` — the box
 * cannot print 14 pt, let alone two lines of it (2 × 21.5107 = 43.0214 pt).
 * A 63-character name in that box first fits at **5 pt** (2 lines). For a
 * text of unbounded length there is no size at which wrapping helps; that is a
 * limit of the geometry, not an open question, and {@see fit()} reports it as
 * `fits === false` instead of pretending otherwise.
 */
final class BadgeTextFitter
{
    /**
     * The CSS `font-family` a badge card declares. Lives here, not in the CSS
     * string it is spliced into, because the fit MEASURES this list: the
     * renderer and the fitter must name the same family or the fit sizes a
     * different font than the one printed.
     *
     * The list itself is walked rather than shortened to its first entry — see
     * {@see resolvedFont()} for why `sans-serif` may not be substituted for
     * `DejaVu Sans`.
     */
    public const FONT_FAMILY_CSS = 'DejaVu Sans, sans-serif';

    /**
     * Millimetres → points, exactly as dompdf converts them:
     * `Style::single_length_in_pt()`'s `"mm"` branch is `$v * 72 / 25.4`
     * (`Style.php:1160`). dpi plays no part in it — only in `px`.
     */
    public const MM_TO_PT = 72 / 25.4;

    /**
     * The floor of the shrink. Below it the fitter stops and reports
     * `fits === false`; it does NOT keep shrinking, because below 1 pt the
     * glyphs are no longer a name a human can read, and an unreadable name is
     * not better than an overlapping one.
     */
    public const MIN_FONT_PT = 1.0;

    /**
     * Sub-pixel reserve (pt) kept free on the WIDTH before a line is declared
     * to fit. It exists so dompdf's own line breaker
     * (`FrameReflower\Text::line_break`, which compares with
     * `Helpers::lengthGreater` at an epsilon of 1e-8) can never disagree with
     * our decision about the same line. Mirrors the editor's
     * `BADGE_FIT_SLACK_PX` (0.5) — same number, same purpose, different unit
     * system.
     */
    private const WIDTH_SLACK_PT = 0.5;

    /**
     * The matching reserve on the HEIGHT, and it is deliberately an order of
     * magnitude smaller.
     *
     * The width has a second opinion to disagree with — the reflower's own
     * breaker — and 0.5 pt buys a wide margin against it. The height has NONE:
     * the line box is exactly what {@see lineHeightPt()} computes, and nothing
     * in dompdf re-flows vertically around our `<br>`s. Spending 0.5 pt of box
     * height here is not free: it shrinks text that fits. Measured, it cost the
     * existing 9 pt / 30 × 5 mm `W12` field of the badge templates
     * (`13.8283 pt` line height against `13.6732 pt` of usable box) its authored
     * size — a change to printed badges of templates that were never broken.
     * What remains is the rounding of the PDF's own text-placement operator.
     */
    private const HEIGHT_SLACK_PT = 0.01;

    /**
     * dompdf's word-break pattern, verbatim: `FrameReflower\Text::$_wordbreak_pattern`
     * (`Text.php:44`). Its alternatives are runs of non-breaking-space
     * characters, line breaks, a RUN OF HYPHENS, and soft hyphens.
     *
     * **The `\-+` alternative is load-bearing here.** `Müller-Schmidt` is one
     * token to a naive whitespace split, so a fitter that breaks on spaces
     * alone would shatter it into `Müller-Sc` / `hmidt` — precisely the outcome
     * Position 9 forbids ("sonst zerbricht Müller-Schmidt als Einzelwort").
     * dompdf itself breaks at the hyphen; mirroring its pattern means the lines
     * this class computes are the lines the printer produces, hyphen included
     * on the first part.
     */
    private const WORD_BREAK_PATTERN = '/([^\S\xA0\x{202F}\x{2007}\n]+|\R|\-+|\xAD+)/u';

    /**
     * Bisection steps for the shrink. The interval is halved each step, so 20
     * steps resolve a 14 pt range to better than 1e-5 pt; the loop also stops
     * early once the interval is below {@see SIZE_EPSILON_PT}, because there is
     * nothing to gain from a more precise answer than the single decimal the
     * emitted `font-size` carries anyway.
     */
    private const SEARCH_STEPS = 20;

    private const SIZE_EPSILON_PT = 0.005;

    /**
     * The size is quantised to this many decimals because that is the
     * resolution dompdf PRINTS it at: `Cpdf::text()` emits
     * `sprintf('%.1F Tf ', $size)` (`Cpdf.php:5460`). MEASURED: a field computed
     * at 5.94 pt is drawn as `5.9 Tf`.
     *
     * Quantising the measurement instead of padding around the gap is what
     * keeps the markup and the page in agreement — the number in the CSS and
     * the number in the content stream are then the SAME number, and the size
     * the fit verified is the size the printer drew. Padding by the worst-case
     * rounding error instead would have cost up to 0.05 pt of size per line,
     * which for DejaVu Sans' 1.5365 pt of line height per pt is 0.077 pt per
     * line — the 0.5 pt problem above in smaller doses, and it would again have
     * shrunk templates that fit.
     *
     * The chosen size is rounded DOWN to this resolution, never up: a smaller
     * font is never taller and never wider, so rounding cannot break the fit.
     */
    private const PRINTED_SIZE_DECIMALS = 1;

    /**
     * Upper bound on {@see $widthMemo}, above which it is dropped wholesale.
     *
     * The memo is an accelerator, not a cache with a contract: losing it costs
     * measurements, never correctness. Measured, a 300-card export with four
     * wrapping text fields each (1200 fits) costs 0.33 s — 0.28 ms per fit —
     * and fills the memo with 18 644 entries, about 6 MB. A 500-card export
     * would carry that past 10 MB for no benefit, on top of a renderer whose
     * memory profile is already the documented residual limit of the export
     * (features/badges-qr.md, "Export — Speicherprofil"). Clearing wholesale at
     * a fixed ceiling keeps that growth flat; the only cost is that a later fit
     * re-measures prefixes it has already seen.
     */
    private const MEMO_LIMIT = 20000;

    /**
     * `@var Dompdf|null` — built on first use, never per card.
     */
    private ?Dompdf $dompdf = null;

    /**
     * The font file the card's family list resolves to, resolved once per
     * instance (the resolution is a font-directory scan, and one service
     * instance serves a whole export).
     */
    private ?string $font = null;

    /**
     * Width memo for {@see widthOf()}, keyed by `size|text`.
     *
     * @var array<string, float>
     */
    private array $widthMemo = [];

    /**
     * @param  string  $fontFamilyCss  the CSS `font-family` list to measure in
     */
    public function __construct(private readonly string $fontFamilyCss = self::FONT_FAMILY_CSS) {}

    /**
     * The CSS family list this fitter measures, split the way dompdf splits it.
     *
     * `Style::_compute_font_family()` (`Style.php:3097-3107`) splits the list
     * on a comma with optional surrounding whitespace and trims `' "` from
     * every name. Mirrored rather than reused: it is a `protected` method on a
     * vendor class.
     *
     * @return list<string>
     */
    public function families(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $name): string => trim($name, " '\""), explode(',', $this->fontFamilyCss)),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * The font file the family list resolves to — the FIRST family in list
     * order that resolves, which is the rule dompdf itself applies in
     * `Style::_get_font_family()` (`Style.php:2060-2069`).
     *
     * **The `sans-serif` trap.** The card declares `DejaVu Sans, sans-serif`
     * and both families DO resolve here (to the bundled `DejaVuSans` and to
     * `Helvetica`), but they are DIFFERENT FONTS with different advance widths.
     * Measuring `sans-serif` while the card prints `DejaVu Sans` would size a
     * font nobody prints, and the fit would then be wrong on every field of
     * every card — in the direction that looks plausible. So the list is walked
     * in order and the first hit wins, exactly as the printer resolves it.
     *
     * @throws BadgeTextFontUnresolvedException when NO family resolves — never a
     *                                          silently wrong number; see the
     *                                          exception's own docblock
     */
    public function resolvedFont(?string $template = null): string
    {
        if ($this->font !== null) {
            return $this->font;
        }

        $metrics = $this->fontMetrics();

        foreach ($this->families() as $family) {
            $font = $metrics->getFont($family);

            if ($font !== null) {
                return $this->font = $font;
            }
        }

        $where = $template === null ? '' : sprintf(' of template "%s"', $template);

        throw new BadgeTextFontUnresolvedException(
            $this->fontFamilyCss,
            $template,
            sprintf(
                'Badge text auto-fit: none of the declared font families (%s) resolves to a font file%s.',
                $this->fontFamilyCss,
                $where,
            ),
        );
    }

    /**
     * Wrap `$text` into `$boxWMm` and shrink it until the WRAPPED text fits
     * `$boxHMm`.
     *
     * - An EMPTY text, or a box with no width/height to fit into, is returned
     *   unchanged at the configured size. A box of zero extent is already
     *   broken by whatever authored it (the controller enforces a minimum, so
     *   this is only reachable from a hand-built layout), and auto-fitting text
     *   into it would produce a tower of one-character lines.
     * - Otherwise the font is shrunk only as far as it must be: a text that
     *   already fits keeps its configured size, which is what keeps every
     *   pre-existing template's printed badge byte-identical.
     *
     * The font is resolved FIRST, before any of the short-circuits above, so an
     * unresolvable family fails loudly for every field of every card rather than
     * only for the ones that happen to carry text — a template nobody can print
     * must say so on the first field, not on the one that overflows.
     *
     * The returned size is quantised to what dompdf actually prints
     * ({@see PRINTED_SIZE_DECIMALS}) and the fit is re-measured at that value,
     * so the size emitted is the size verified.
     */
    public function fit(string $text, float $boxWMm, float $boxHMm, float $sizePt, ?string $template = null): BadgeTextFit
    {
        $this->resolvedFont($template);

        if (trim($text) === '' || $boxWMm <= 0.0 || $boxHMm <= 0.0) {
            return new BadgeTextFit(
                $sizePt,
                trim($text) === '' ? [] : [$text],
                true,
                $this->lineHeightPt($sizePt),
                max(0.0, $boxHMm * self::MM_TO_PT - self::HEIGHT_SLACK_PT),
            );
        }

        $availableWidthPt = $boxWMm * self::MM_TO_PT - self::WIDTH_SLACK_PT;
        $availableHeightPt = $boxHMm * self::MM_TO_PT - self::HEIGHT_SLACK_PT;

        $size = $this->shrunkSize($text, $availableWidthPt, $availableHeightPt, $sizePt);
        $lines = $this->wrap($text, $availableWidthPt, $size);
        $lineHeightPt = $this->lineHeightPt($size);

        return new BadgeTextFit(
            $size,
            $lines,
            count($lines) * $lineHeightPt <= $availableHeightPt,
            $lineHeightPt,
            $availableHeightPt,
        );
    }

    /**
     * The printed height of one line at `$sizePt`.
     *
     * @see self for why this is dompdf's model and not a second opinion of it
     */
    public function lineHeightPt(float $sizePt): float
    {
        return Style::$default_line_height * $this->fontMetrics()->getFontHeight($this->resolvedFont(), $sizePt);
    }

    /**
     * The largest font size in `[MIN_FONT_PT, $sizePt]` whose WRAPPED text fits
     * the available height, quantised down to {@see PRINTED_SIZE_DECIMALS}.
     *
     * "Fits" is monotone in the size — a smaller font is never taller and never
     * wider, so the line count never grows — which is what licenses the
     * bisection. The first probe is analytic rather than a bisection step: one
     * line always fits once `size ≤ availableHeight / (factor × heightPerPt)`,
     * and that bound is reached with a SINGLE `getFontHeight()` call, so the
     * common "two lines are too tall, one line fits" case costs two measurements
     * instead of twenty.
     */
    private function shrunkSize(string $text, float $availableWidthPt, float $availableHeightPt, float $sizePt): float
    {
        $fits = fn (float $size): bool => $this->fitsAt($text, $availableWidthPt, $availableHeightPt, $size);

        if ($fits($sizePt)) {
            return $sizePt;
        }

        $perPtLineHeight = $this->lineHeightPt(1.0);
        $oneLineCeiling = $perPtLineHeight > 0.0 ? $availableHeightPt / $perPtLineHeight : self::MIN_FONT_PT;

        $high = min($sizePt, $oneLineCeiling);
        if ($fits($high)) {
            return $this->roundDown($high);
        }

        $low = self::MIN_FONT_PT;

        for ($step = 0; $step < self::SEARCH_STEPS && $high - $low > self::SIZE_EPSILON_PT; $step++) {
            $mid = ($low + $high) / 2;

            if ($fits($mid)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        // The floor is the last word: if even MIN_FONT_PT does not fit, the
        // geometry has run out and `fit()` reports `fits === false` rather than
        // claiming a fit it cannot deliver.
        return $low > self::MIN_FONT_PT ? $this->roundDown($low) : self::MIN_FONT_PT;
    }

    /**
     * Whether the text, wrapped at `$size`, fits `$availableHeightPt`.
     *
     * The wrap is thrown away: the caller re-wraps once at the final size, so
     * the lines that ship are measured at the size that ships.
     */
    private function fitsAt(string $text, float $availableWidthPt, float $availableHeightPt, float $size): bool
    {
        $lines = $this->wrap($text, $availableWidthPt, $size);

        return count($lines) * $this->lineHeightPt($size) <= $availableHeightPt;
    }

    /**
     * Wrap `$text` to `$availableWidthPt` at `$size`: dompdf's own break
     * opportunities first, CHARACTER-wise only where a single token is still
     * too wide.
     *
     * The tokenisation is dompdf's ({@see WORD_BREAK_PATTERN}), and the walk
     * mirrors `FrameReflower\Text::line_break()`'s loop over the split, so the
     * lines computed here are the lines the printer produces — same break
     * points, same hyphen placement.
     *
     * Character-wise is the LAST resort and only where the token survives the
     * first two rounds: a 30-character single word in a narrow box has no break
     * opportunity left, and the position requires it rather than an overflow.
     * The order is the board's decision (Position 9) and not a free choice —
     * character-wise first would shatter names and give a stranger a worse
     * card than a smaller font would.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $availableWidthPt, float $size): array
    {
        $tokens = preg_split(self::WORD_BREAK_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $count = $tokens === false ? 0 : count($tokens);
        $lines = [];
        $current = '';
        $glue = ' ';
        $hasContent = false;

        for ($i = 0; $i < $count; $i += 2) {
            $word = $tokens[$i];
            $following = $tokens[$i + 1] ?? '';

            // The separator FOLLOWS its word, and that is where it belongs: a
            // plain space is an ordinary break opportunity, while a hyphen or a
            // soft hyphen terminates the word it follows and is therefore part
            // of that token — the same attachment dompdf performs with its
            // `$word = $sep === " " ? $words[$i] : $words[$i] . $sep`. A hyphen
            // therefore stays on the first line instead of starting the next
            // one with it.
            $token = ($following === ' ' || $following === '') ? $word : $word.$following;
            $candidate = $hasContent ? $current.$glue.$token : $token;

            if ($hasContent && $this->widthOf($candidate, $size) > $availableWidthPt) {
                $lines[] = rtrim($current);
                // A separator consumed by the break is gone, exactly as dompdf
                // drops it: the new line starts flush with its first word.
                $current = $token;
                $glue = ' ';
            } else {
                $current = $candidate;
                // A hyphen absorbed into this token joins the NEXT one without a
                // space ("Testhausen-Müller"); every other separator needs one.
                $glue = ($following !== '' && $following !== ' ') ? '' : ' ';
            }

            $hasContent = true;
        }

        if ($hasContent && trim($current) !== '') {
            $lines[] = rtrim($current);
        }

        $wrapped = [];

        foreach ($lines as $line) {
            foreach ($this->breakToken($line, $availableWidthPt, $size) as $part) {
                $wrapped[] = $part;
            }
        }

        return $wrapped;
    }

    /**
     * Split one over-long token character-wise, always placing at least one
     * character per line (so the loop terminates on a degenerate width, where
     * not even one glyph fits).
     *
     * The accumulation loop is dompdf's own `overflow-wrap: break-word` walk
     * (`FrameReflower\Text.php:227-240`), written out here rather than
     * configured there — the same place, but decided by this class instead of
     * by a vendor heuristic.
     *
     * @return list<string>
     */
    private function breakToken(string $token, float $availableWidthPt, float $size): array
    {
        if ($this->widthOf($token, $size) <= $availableWidthPt) {
            return [$token];
        }

        $lines = [];
        $current = '';
        $length = mb_strlen($token, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $character = mb_substr($token, $i, 1, 'UTF-8');
            $candidate = $current.$character;

            if ($current !== '' && $this->widthOf($candidate, $size) > $availableWidthPt) {
                $lines[] = $current;
                $current = $character;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * The measured width of one line, in points.
     *
     * `FontMetrics::getTextWidth()` delegates to `$this->canvas->get_text_width()`
     * — the canvas that PRINTS. `word_spacing`/`char_spacing` are 0 because the
     * card's CSS sets neither (dompdf's `Style` defaults both to `0`).
     *
     * **The vendor trap, commented at the call site as the position requires.**
     * `getTextWidth()` keeps a `static $cache` whose KEY is
     * `canvasClass/font/size/wordSpacing/charSpacing` (`FontMetrics.php:294-320`);
     * the text is only a SECOND level of that key, and the cache is skipped for
     * strings of 50 characters or more. Two consequences for this class:
     * (1) our field values are arbitrary tenant data and routinely exceed 50
     * characters, so dompdf's cache does nothing for them — the memo below is
     * what keeps the bisection affordable; (2) the cache is `static`, so it is
     * shared by EVERY `FontMetrics` in the process and is only sound while the
     * canvas class and the font PATH agree — which is exactly why the font is
     * resolved once per instance and never measured against another canvas.
     */
    private function widthOf(string $text, float $size): float
    {
        if ($text === '') {
            return 0.0;
        }

        $key = $size.'|'.$text;

        if (isset($this->widthMemo[$key])) {
            return $this->widthMemo[$key];
        }

        // @see MEMO_LIMIT — dropping the whole memo costs measurements, not
        // correctness, so this is the one place that may reset it.
        if (count($this->widthMemo) >= self::MEMO_LIMIT) {
            $this->widthMemo = [];
        }

        return $this->widthMemo[$key] = $this->fontMetrics()->getTextWidth(
            $text,
            $this->resolvedFont(),
            $size,
            0.0,
            0.0,
        );
    }

    private function roundDown(float $size): float
    {
        $factor = 10 ** self::PRINTED_SIZE_DECIMALS;

        return (float) sprintf('%.'.self::PRINTED_SIZE_DECIMALS.'F', floor($size * $factor) / $factor);
    }

    private function fontMetrics(): FontMetrics
    {
        // `new Dompdf` with NO options — the same construction
        // `BadgeRenderService::renderPdf()` uses, so both this measurement and
        // the print share the font directory, the default font and the
        // fontHeightRatio. Built once per instance: a 500-card export must not
        // construct 500 renderers.
        $this->dompdf ??= new Dompdf;

        return $this->dompdf->getFontMetrics();
    }
}

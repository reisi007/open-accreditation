<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The font family a badge card declares could not be resolved to a font file,
 * so the text auto-fit has nothing to measure with (Position 9).
 *
 * **Why this exists at all, when the obvious reading says dompdf already
 * throws.** `Dompdf\FontMetrics::getFont()` does NOT throw on an unknown
 * family: it `return null`s (`FontMetrics.php:457-527` — the early
 * `return $cache[...] = $families[$family][$subtype]` on a hit, then a bare
 * `return null` on the miss). The board row for Position 9 claimed it throws,
 * which is wrong; measured on the installed dompdf **v3.1.6** and reproduced by
 * `BadgeTextAutoFitTest::test_an_unresolvable_family_fails_loudly_instead_of_measuring_a_different_font`.
 *
 * The consequence is worse than an exception: `FontMetrics::getTextWidth()`
 * does not validate its `$font` argument either, it hands it straight to
 * `Cpdf::selectFont()`, which returns the CURRENTLY SELECTED font for an empty
 * name. A `null` font therefore yields a perfectly plausible width instead of an
 * error — measured on this vendor tree for the string `Max` at 12 pt: **22.6680
 * pt** with a `null` font, against 21.9960 pt in Times-Roman (the option
 * default) and 24.8160 pt in DejaVu Sans (what the card prints). It is not a
 * crash and not a zero; it is a third number, belonging to whichever font that
 * canvas happened to have selected last. An unresolved family would therefore
 * have produced a WRONG FIT, silently, on every field of every card — the exact
 * failure this position exists to remove ("ein nicht auflösbares Template soll
 * laut sein, nicht still eine plausible falsche Zahl liefern").
 *
 * So the loud failure is raised HERE, deliberately, and the family list plus the
 * template it came from travel on the exception so a failure report names the
 * cause instead of parsing the message.
 */
class BadgeTextFontUnresolvedException extends RuntimeException
{
    /**
     * @param  string  $families  the CSS font-family list that did not resolve
     * @param  string|null  $template  the badge template being rendered, when known
     */
    public function __construct(
        public readonly string $families,
        public readonly ?string $template,
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

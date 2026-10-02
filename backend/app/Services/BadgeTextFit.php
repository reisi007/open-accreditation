<?php

namespace App\Services;

/**
 * One wrapped, fitted text field of a badge card (Position 9).
 *
 * `lines` are the lines the card prints, in order, WITHOUT the `<br>`
 * separators — those are added by the renderer that turns them into markup.
 * `fits` is false only when the geometry ran out: even {@see
 * BadgeTextFitter::MIN_FONT_PT} does not bring the wrapped text into the box.
 */
final class BadgeTextFit
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly float $fontSizePt,
        public readonly array $lines,
        public readonly bool $fits,
        public readonly float $lineHeightPt,
        public readonly float $availableHeightPt,
    ) {}

    public function isMultiLine(): bool
    {
        return count($this->lines) > 1;
    }
}

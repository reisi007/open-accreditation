<?php

namespace Tests\Support;

/**
 * One PDF content-stream extractor for the badge suites.
 *
 * Four test classes needed "what does dompdf actually draw on this card?", and
 * each grew its own copy. They drifted into the same defect, so the copies live
 * here now: ONE implementation, and a fix that has to be made once.
 *
 * ## What the copies got wrong (measured, 2026-09-29)
 *
 * Every copy located a payload with
 * `rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart))` between `stream\n`
 * and `endstream`. `rtrim()` also strips `\r`, ` ` and `\0` — and the payload
 * is COMPRESSED bytes whose last byte may legitimately be any of them. When it
 * was, one byte was eaten, `gzuncompress()` failed, `@` swallowed the warning,
 * and the helper returned an EMPTY STRING: the test then reported a missing
 * field (`Expected: … To contain: Jane Doe`) instead of a broken extractor. The
 * badge card's content stream ends in `0d 0a`, so this fired on the very first
 * run after the portrait fixture started producing a different (shorter) stream.
 *
 * The boundary is `/Length N` — dompdf writes it on every compressed object —
 * so that is what is used now, with `\nendstream` only as a fallback for a
 * stream whose dictionary has no `/Length`.
 */
trait ExtractsPdfContentStream
{
    /**
     * The inflated text of every stream in a dompdf PDF, concatenated.
     *
     * dompdf encodes text as UTF-16BE (interleaved `\x00` bytes), so the null
     * bytes are stripped: assertions are written against readable text.
     */
    private function pdfText(string $pdf): string
    {
        $text = '';
        $offset = 0;

        while (($start = strpos($pdf, 'stream', $offset)) !== false) {
            // `endstream` contains `stream` — step over the terminator.
            if ($start === 0 || substr($pdf, $start - 3, 3) === 'end') {
                $offset = $start + 6;

                continue;
            }

            $dataStart = strpos($pdf, "\n", $start);

            if ($dataStart === false) {
                break;
            }

            $dataStart++;

            $payload = $this->pdfStreamPayload($pdf, $start, $dataStart);

            if ($payload !== null) {
                $inflated = @gzuncompress($payload);

                if ($inflated === false) {
                    $inflated = @gzinflate($payload);
                }

                if ($inflated !== false) {
                    $text .= $inflated;
                }
            }

            $offset = $dataStart + ($payload === null ? 0 : strlen($payload));
        }

        return str_replace("\x00", '', $text);
    }

    /**
     * The raw bytes of one stream object, or `null` when it cannot be located.
     *
     * @param  int  $start  offset of the `stream` keyword
     * @param  int  $dataStart  offset of the first payload byte
     */
    private function pdfStreamPayload(string $pdf, int $start, int $dataStart): ?string
    {
        // The dictionary is the text between the last `obj` and the `stream`
        // keyword. Bounding there — rather than at a fixed byte count — matters
        // as soon as two stream objects sit close together: a 200-byte window
        // reaches back into the PREVIOUS object's `/Length`, and a first-match
        // regex would then read this payload with the previous length.
        $objectStart = strrpos(substr($pdf, 0, $start), ' obj');
        $from = $objectStart === false ? 0 : $objectStart;
        $dictionary = substr($pdf, $from, $start - $from);

        if (preg_match('#/Length\s+(\d+)#', $dictionary, $matches) === 1) {
            $length = (int) $matches[1];

            if ($length > 0 && strlen($pdf) >= $dataStart + $length) {
                return substr($pdf, $dataStart, $length);
            }
        }

        $end = strpos($pdf, "\nendstream", $dataStart);

        return $end === false ? null : substr($pdf, $dataStart, $end - $dataStart);
    }
}

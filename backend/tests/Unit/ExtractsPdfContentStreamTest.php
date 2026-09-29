<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExtractsPdfContentStream;

/**
 * The shared PDF stream extractor, on PDFs that are built for the PURPOSE
 * instead of produced by dompdf.
 *
 * Why a synthetic fixture: the badge suites exercise the `/Length` path on
 * every card, but its FALLBACK is only reached by a stream whose dictionary
 * carries no `/Length` — and dompdf always writes one, so no badge card can
 * reach it. A branch that no card can reach is a branch nobody tests; these
 * fixtures build both shapes by hand so it is tested rather than assumed.
 *
 * ## The `rtrim()` guard, and why the fixtures look like nonsense
 *
 * The extractor this replaced read a payload with
 * `rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart))`. `rtrim()` with its
 * default charlist strips six bytes (`" \t\n\r\0\x0B"`), the payload is
 * COMPRESSED, and a stripped byte is a byte gone: `gzuncompress()` fails, the
 * `@` swallows the warning, and the helper returns an EMPTY STRING, so the test
 * reports a missing field instead of a broken extractor.
 *
 * The first version of this file asserted that the payload "ends in a byte
 * `rtrim()` would eat" and then chose its fixtures on the PLAIN text
 * (`"Jane Doe\r"`, `"Jane Doe "`, `"Jane Doe\0"`, `"Jane Doe\n"`). That is
 * false, and measured: the plain text's last byte has no bearing on the
 * payload's, because the payload is a zlib (RFC 1950) stream — two header
 * bytes, the deflate data, then a four-byte big-endian **Adler-32** whose LOW
 * byte is the last byte of the payload.
 *
 * | plain          | compressed last byte | does `rtrim()` eat it? |
 * |----------------|----------------------|-------------------------|
 * | `"Jane Doe\r"` | `0xC4`               | no                      |
 * | `"Jane Doe "`  | `0xD7`               | no                      |
 * | `"Jane Doe\0"` | `0xB7`               | no                      |
 * | `"Jane Doe\n"` | `0xC1`               | no                      |
 *
 * No compression level (−1…9) changes any of that. All four data sets therefore
 * passed with the broken extractor fully restored — 8/8 green, 0 red. The defect
 * was being caught elsewhere, by accident, for an unrelated reason.
 *
 * The fixtures below are chosen by the last byte of their COMPRESSED payload,
 * and every data set PINS that byte, so a zlib change fails the premise loudly
 * instead of quietly turning a fixture into one that no longer reproduces the
 * defect. The four old fixtures are kept as a NEGATIVE control, so the table
 * above stays a measured fact instead of a claim; and one of the six charlist
 * bytes cannot be covered at all — measured, and pinned in its own test so
 * nobody adds it back believing it works.
 */
class ExtractsPdfContentStreamTest extends TestCase
{
    use ExtractsPdfContentStream;

    /**
     * The six bytes `rtrim()` strips with its default charlist.
     */
    private const RTRIM_DEFAULT_CHARS = [' ', "\t", "\n", "\r", "\0", "\x0B"];

    /**
     * A PDF whose only stream is the given deflate payload, written the way
     * dompdf/Cpdf writes it (`stream\n` + data + `\nendstream`).
     */
    private function pdfWithStream(string $deflated, ?int $length): string
    {
        $dictionary = $length === null
            ? "<< /Filter /FlateDecode >>\n"
            : "<< /Filter /FlateDecode /Length {$length} >>\n";

        return "%PDF-1.7\n1 0 obj\n".$dictionary."stream\n".$deflated."\nendstream\nendobj\n%%EOF\n";
    }

    /**
     * One data set per byte of `rtrim()`'s default charlist that a zlib payload
     * can actually be made to end on — five of the six. The sixth, `0x00`, is
     * reachable but unguardable; see
     * {@see self::test_the_nul_byte_of_the_adler_trailer_is_not_guardable()}.
     *
     * The plaintexts are PDF-content-stream-shaped fragments found by brute
     * force over their checksum byte. Nothing about THEM is under test: the
     * subject is the last byte of the COMPRESSED payload, which is what the
     * first parameter pins.
     *
     * @return array<string, array{int, string}>
     */
    public static function compressedTrailingByteProvider(): array
    {
        return [
            'CR  (0x0D)' => [0x0D, '8dp'],
            'TAB (0x09)' => [0x09, 'aaF'],
            'LF  (0x0A)' => [0x0A, 'aaG'],
            'VT  (0x0B)' => [0x0B, 'mo.'],
            'SP  (0x20)' => [0x20, 'tfE'],
        ];
    }

    #[DataProvider('compressedTrailingByteProvider')]
    public function test_it_inflates_a_stream_whose_compressed_payload_ends_in_a_byte_rtrim_strips(
        int $expectedLastByte,
        string $plain,
    ): void {
        $deflated = (string) gzcompress($plain);
        $lastByte = ord($deflated[strlen($deflated) - 1]);

        $this->assertSame(
            $expectedLastByte,
            $lastByte,
            'PREMISE: this fixture exists because its COMPRESSED payload ends on a byte '
            .'`rtrim()` strips. Which byte that is follows from zlib\'s encoding (the low byte '
            .'of the Adler-32 trailer), NOT from the plaintext — so a zlib change means a new '
            .'plaintext for this data set, never a deleted assertion.',
        );

        $this->assertContains(
            $lastByte,
            array_map('ord', self::RTRIM_DEFAULT_CHARS),
            'PREMISE: the pinned byte is not one `rtrim()` strips by default, so the defect this '
            .'fixture reproduces cannot happen with it.',
        );

        $this->assertNotSame(
            $deflated,
            rtrim($deflated),
            'PREMISE: `rtrim()` has to actually shorten this payload. Without that, the fixture '
            .'does not exercise the defect at all — the failure mode this test exists to prevent.',
        );

        $pdf = $this->pdfWithStream($deflated, strlen($deflated));

        $this->assertSame(
            $plain,
            $this->pdfText($pdf),
            'A trailing byte in the COMPRESSED payload must not be stripped before inflating.',
        );
    }

    /**
     * The four fixtures the FIRST version of this file used, kept as a negative
     * control.
     *
     * They end in whitespace in the PLAIN text, and `features/badges-qr.md`
     * said the same kind of thing about the real badge card ("der Content-Stream
     * des Ausweises endet auf `0d 0a`"). Both statements are false, and this is
     * where the false half is pinned: the plain text's last byte has no bearing
     * on the payload's, because the payload is a zlib stream whose last byte is
     * the low byte of the Adler-32 trailer.
     *
     * So these are not weaker versions of the data sets above — they are the
     * ones that do NOT reproduce the defect, and the assertion is exactly that:
     * `rtrim()` leaves every one of them untouched, so they inflate correctly
     * even through the old extractor. Choosing fixtures this way is what
     * produced a guard that could not fail.
     *
     * @return array<string, array{int, string}>
     */
    public static function plainTextTrailingWhitespaceProvider(): array
    {
        return [
            'plain ends in CR' => [0xC4, "Jane Doe\r"],
            'plain ends in space' => [0xD7, 'Jane Doe '],
            'plain ends in NUL' => [0xB7, "Jane Doe\0"],
            'plain ends in LF' => [0xC1, "Jane Doe\n"],
        ];
    }

    #[DataProvider('plainTextTrailingWhitespaceProvider')]
    public function test_a_payload_whose_plain_text_ends_in_whitespace_does_not_reproduce_the_defect(
        int $expectedLastByte,
        string $plain,
    ): void {
        $deflated = (string) gzcompress($plain);

        $this->assertSame(
            $expectedLastByte,
            ord($deflated[strlen($deflated) - 1]),
            'PREMISE: the pinned byte is what this plaintext compresses to today. A zlib change '
            .'moves it, and the assertion below is then re-measured rather than assumed.',
        );

        $this->assertSame(
            $deflated,
            rtrim($deflated),
            'Whitespace at the end of the PLAIN text is not the condition this file guards: the '
            .'compressed payload\'s last byte is a checksum byte, `rtrim()` does not touch it, '
            .'and the payload inflates fine even with the extractor this file replaced. A guard '
            .'built from fixtures like these is green against the very defect it claims to catch.',
        );
    }

    /**
     * The one charlist byte this class cannot cover, and the measurement for it.
     *
     * `0x00` IS reachable as a compressed payload's last byte (it is the low
     * byte of the Adler-32 trailer) — but zlib does not fail when that byte is
     * missing: `gzuncompress()` of the stripped payload still returns the
     * correct plain text. A data set for it would therefore be GREEN with the
     * broken extractor restored, which is the exact failure mode this file was
     * rewritten for: a test that cannot fail while reading as coverage.
     *
     * A raw-deflate payload does not rescue it either — there the last byte is
     * deflate DATA rather than a checksum, but measured `gzdeflate('2gtj')` is
     * 6 bytes ending in `0x00` and `gzinflate()` of the stripped payload still
     * succeeds. So the byte is dropped from the provider, deliberately.
     *
     * This test exists so the drop is reversible on evidence: if zlib ever
     * becomes strict here, the assertion fails and `0x00` goes back into the
     * provider.
     */
    public function test_the_nul_byte_of_the_adler_trailer_is_not_guardable(): void
    {
        $deflated = (string) gzcompress('IHn');

        $this->assertSame(
            0x00,
            ord($deflated[strlen($deflated) - 1]),
            'PREMISE: this payload really does end on the byte rtrim() strips, so the relaxed '
            .'zlib behaviour asserted below — not an accidental different byte — is the reason '
            .'0x00 is absent from the provider.',
        );

        $this->assertNotSame($deflated, rtrim($deflated), 'PREMISE: `rtrim()` eats that byte.');

        $this->assertSame(
            'IHn',
            gzuncompress(rtrim($deflated)),
            'MEASURED reason for the missing data set: zlib returns the inflated data even with '
            .'the last byte of the Adler-32 trailer removed, so stripping it costs nothing and '
            .'no fixture could catch the defect through it. If this assertion starts failing, '
            .'zlib got strict — put 0x00 back into the provider.',
        );
    }

    public function test_it_falls_back_to_the_endstream_marker_when_the_dictionary_has_no_length(): void
    {
        $plain = 'Jane Doe';
        $deflated = (string) gzcompress($plain);

        // No `/Length` — the fallback has to find the boundary itself, and it
        // must NOT stop at the "stream" inside "endstream".
        $pdf = $this->pdfWithStream($deflated, null);

        $this->assertSame($plain, $this->pdfText($pdf));
    }

    public function test_it_concatenates_every_stream_in_the_document(): void
    {
        $first = (string) gzcompress('Jane Doe');
        $second = (string) gzcompress('Presse');

        $pdf = "%PDF-1.7\n"
            .$this->pdfWithStream($first, strlen($first))
            .$this->pdfWithStream($second, strlen($second));

        $this->assertStringContainsString('Jane Doe', $this->pdfText($pdf));
        $this->assertStringContainsString('Presse', $this->pdfText($pdf));
    }

    public function test_it_does_not_mistake_the_endstream_terminator_for_a_stream_keyword(): void
    {
        // Two streams back to back: a scan that jumped from `endstream` into the
        // payload would find a "stream" substring there and start a bogus read.
        $first = (string) gzcompress('a');
        $second = (string) gzcompress('Jane Doe');

        $pdf = "%PDF-1.7\n"
            .$this->pdfWithStream($first, strlen($first))
            .$this->pdfWithStream($second, strlen($second));

        $this->assertSame('aJane Doe', $this->pdfText($pdf));
    }

    /**
     * The same two-streams-back-to-back shape, but with the SECOND payload
     * longer than the first — which is what makes a fixed-byte dictionary
     * window fail.
     *
     * With a 200-byte window the second object's dictionary region reaches back
     * into the FIRST object's `/Length`, and a first-match regex reads the
     * second payload with the first one's length: it inflates to a truncated
     * prefix, and the tail (`Jane Doe`) is gone. The dictionary therefore has to
     * be bounded by the last `obj`, not by a byte count.
     */
    public function test_the_second_of_two_adjacent_streams_uses_its_own_length(): void
    {
        $first = (string) gzcompress('a');
        $second = (string) gzcompress(str_repeat('Jane Doe ', 40));

        $this->assertGreaterThan(
            strlen($first),
            strlen($second),
            'PREMISE: the second payload is longer, so the two /Length values differ.',
        );

        $pdf = "%PDF-1.7\n"
            .$this->pdfWithStream($first, strlen($first))
            .$this->pdfWithStream($second, strlen($second));

        $this->assertSame('a'.str_repeat('Jane Doe ', 40), $this->pdfText($pdf));
    }
}

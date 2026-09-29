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
 * The payload in every case ends in a byte that `rtrim()` would eat (`0x0d`,
 * `0x20`, `0x00`). That is the whole point of the defect this replaced: a
 * deflate stream may legitimately end in whitespace, and stripping it silently
 * truncates the data. Without this fixture the bug is invisible, because a
 * payload whose last byte happens to be `0x8e` inflates fine.
 */
class ExtractsPdfContentStreamTest extends TestCase
{
    use ExtractsPdfContentStream;

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

    public static function trailingByteProvider(): array
    {
        return [
            'payload ends in CR' => [1, "Jane Doe\r"],
            'payload ends in space' => [1, 'Jane Doe '],
            'payload ends in NUL' => [1, "Jane Doe\0"],
            'payload ends in LF' => [1, "Jane Doe\n"],
        ];
    }

    #[DataProvider('trailingByteProvider')]
    public function test_it_inflates_a_stream_whose_last_byte_is_whitespace(int $expected, string $plain): void
    {
        $deflated = (string) gzcompress($plain);

        $pdf = $this->pdfWithStream($deflated, strlen($deflated));

        $this->assertSame(
            str_replace("\x00", '', $plain),
            $this->pdfText($pdf),
            'A trailing whitespace byte in the COMPRESSED payload must not be stripped before inflating.',
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

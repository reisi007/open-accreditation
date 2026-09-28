<?php

namespace Tests\Feature;

use App\Models\Application;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The badge page is printed on an OPAQUE WHITE background (Nutzerentscheidung
 * 2026-09-28) — the render contract of `features/badge-template-editor.md` and
 * the decision itself in `features/badges-qr.md`.
 *
 * **What the tests pin.** dompdf paints no page background of its own, so a
 * badge page used to raster with an **alpha channel**: what it looked like was
 * decided by whoever consumed the PNG (white on paper, black — with the badge's
 * black text gone — on a dark background). The white background is therefore not
 * a cosmetic detail of one element, it is a property of the PAGE, and it has to
 * hold in every state an element can be in: a portrait on file, the silhouette
 * placeholder, and no picture behind the spot at all. A background that lived on
 * the picture boxes would have been transparent again in the third state, which
 * is why the assertion is made on the rendered PDF's content stream rather than
 * on the markup alone.
 *
 * **Why the content stream and not only the HTML.** `cardHtml()` is the public
 * render contract, so the markup is asserted too — but markup alone cannot say
 * whether dompdf actually PAINTED the fill. The content stream can: a white
 * `rg` followed by a rectangle that measures the full A6 surface in points. Both
 * halves are needed: the markup assertion is what stops someone from moving the
 * background back onto the picture boxes, the stream assertion is what stops
 * someone from leaving a declaration dompdf silently drops (the `object-fit`
 * lesson, see `BadgeRenderService`).
 */
class BadgePageBackgroundTest extends TestCase
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

    /**
     * The background is a property of the PAGE, so it sits on the A6-sized card
     * root `cardHtml()` returns — and nowhere else.
     */
    public function test_the_background_sits_on_the_card_root_and_on_no_other_box(): void
    {
        $application = $this->approvedApplication();
        $this->storePortrait($application->user, 60, 80);

        $html = $this->renderer->cardHtml($application, $this->makeTemplate([
            ['field' => 'name', 'x' => 40, 'y' => 26, 'w' => 60, 'h' => 10, 'size' => 15, 'align' => 'left'],
            ['field' => 'photo', 'x' => 5, 'y' => 25, 'w' => 30, 'h' => 30, 'size' => 12, 'align' => 'left'],
        ]));

        $rootOpen = strstr($html, '>', true);
        $this->assertIsString($rootOpen, 'the card markup must start with the card root element');
        $this->assertSame(
            '<div class="card" style="background-color:#ffffff;"',
            $rootOpen,
            'the card root — the A6-sized page container — must carry the white background',
        );

        // One declaration, and it is the one on the root: a background on a
        // picture box would be transparent again wherever the box is empty.
        $this->assertSame(
            1,
            substr_count($html, 'background-color'),
            'exactly one element on the card may declare a background — the page itself',
        );
        $this->assertStringNotContainsString(
            'background-color',
            substr($html, strlen($rootOpen) + 1),
            'no field, photo, image or QR box may declare a background of its own',
        );
    }

    /**
     * The background survives every state of a `photo` entry, including the one
     * with no picture behind it at all.
     *
     * The three states are not decoration — each one is the case in which a
     * background placed on the picture boxes would be gone:
     *
     * - `portrait`:      a real portrait is embedded,
     * - `placeholder`:   no portrait, so the bundled silhouette is drawn,
     * - `without_image`: the layout has no `photo` entry, so nothing is drawn
     *                    there at all and the spot is bare page.
     *
     * The expected `<img>` count counts the **QR** as well: `cardHtml()` always
     * renders one (at the historical fallback spot when the layout has no `qr`
     * entry), so "no picture at the photo spot" is one image on the card, not
     * zero. Getting that number wrong would make every case below fail on its
     * own precondition instead of on the background — so it is asserted, not
     * assumed.
     *
     * @param  list<array<string, mixed>>  $layout
     */
    #[DataProvider('pictureStates')]
    public function test_the_page_is_painted_white_in_every_picture_state(array $layout, bool $withPortrait, int $expectedImages): void
    {
        $application = $this->approvedApplication();

        if ($withPortrait) {
            $this->storePortrait($application->user, 60, 80);
        }

        $template = $this->makeTemplate($layout);
        $html = $this->renderer->cardHtml($application, $template);

        // The state must be the one the test claims. Without this the case
        // would still pass with a fixture that quietly changed into another one
        // (e.g. a "placeholder" card that silently got a portrait) — the
        // specific way a three-state test goes vacuous.
        $this->assertSame(
            $expectedImages,
            substr_count($html, '<img'),
            'the fixture must produce the picture state this case is about',
        );

        $this->assertPageIsFilledWhite(
            $this->renderer->renderPdf(new Collection([$application]), $template),
        );
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: bool, 2: int}>
     */
    public static function pictureStates(): array
    {
        $base = [
            ['field' => 'name', 'x' => 40, 'y' => 26, 'w' => 60, 'h' => 10, 'size' => 15, 'align' => 'left'],
        ];

        $withPhoto = [...$base, ['field' => 'photo', 'x' => 5, 'y' => 25, 'w' => 30, 'h' => 30, 'size' => 12, 'align' => 'left']];

        return [
            // A portrait on file: the historical common case. QR + portrait = 2.
            'portrait' => [$withPhoto, true, 2],
            // No portrait, no photo entry: nothing is drawn at the spot at all.
            // This is the state a background on the picture boxes would NOT have
            // covered — only the QR is drawn.
            'without_image' => [$base, false, 1],
        ];
    }

    /**
     * The placeholder case is a separate test rather than a provider row: its
     * precondition is an ASSET, not a database row, so it needs its own
     * assertion (the silhouette must really resolve) before the background is
     * checked — otherwise the "placeholder" case would silently degrade into
     * "empty box" and still pass.
     */
    public function test_the_page_is_painted_white_when_the_placeholder_silhouette_is_drawn(): void
    {
        $placeholder = app(BadgePhotoPlaceholder::class);
        $this->assertNotNull(
            $placeholder->dataUri(),
            'the bundled placeholder must resolve — otherwise this case would silently test the empty box',
        );

        $application = $this->approvedApplication();
        $this->assertNull(
            $application->user->media()->where('type', 'portrait')->first(),
            'the placeholder branch requires an application WITHOUT a portrait',
        );

        $template = $this->makeTemplate([
            ['field' => 'photo', 'x' => 5, 'y' => 25, 'w' => 30, 'h' => 30, 'size' => 12, 'align' => 'left'],
        ]);

        $html = $this->renderer->cardHtml($application, $template);
        $this->assertSame(
            2,
            substr_count($html, '<img'),
            'exactly two pictures must be drawn: the silhouette plus the QR (this layout has no qr entry, so the QR sits at its fallback spot)',
        );
        $this->assertStringContainsString('base64,', $html, 'the silhouette must be embedded as a data URI');

        $this->assertPageIsFilledWhite(
            $this->renderer->renderPdf(new Collection([$application]), $template),
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * The page of a rendered PDF carries exactly ONE white fill, and it measures
     * the whole A6 surface.
     *
     * "Exactly one" is part of the contract, not pedantry: a second white fill
     * would mean the background had been duplicated onto a box as well, and the
     * geometry assertion below is about the PAGE, not about any element on it.
     */
    private function assertPageIsFilledWhite(string $pdf): void
    {
        $fills = $this->pageFills($pdf);

        $this->assertNotEmpty(
            $fills,
            'dompdf must PAINT the page background: a background-color that never reaches '
                .'the content stream is indistinguishable from no background at all',
        );
        $this->assertCount(1, $fills, 'exactly one white fill — the page itself, not a box on it');

        // A6 in points, to within dompdf's three-decimal millimetre rounding
        // (0.002 pt measured on the y origin, 0.0005 pt on the sides). The
        // tolerance is 0.05 pt = 0.018 mm, far below the 1 mm the smallest
        // box field is measured in, and far above the serialisation error.
        $expected = [
            'x' => 0.0,
            'y' => 0.0,
            'w' => BadgeRenderService::A6_WIDTH_MM * 72 / 25.4,
            'h' => BadgeRenderService::A6_HEIGHT_MM * 72 / 25.4,
        ];

        foreach (['x' => 0, 'y' => 1, 'w' => 2, 'h' => 3] as $name => $index) {
            $this->assertEqualsWithDelta(
                $expected[$name],
                $fills[0][$index],
                0.05,
                sprintf(
                    'the white fill must cover the whole A6 surface: %s is %.3f pt, expected %.3f pt (105 × 148 mm in points)',
                    $name,
                    $fills[0][$index],
                    $expected[$name],
                ),
            );
        }
    }

    /**
     * The white page fills in a rendered PDF, as `[x, y, w, h]` in points.
     *
     * Parsed out of the inflated content stream: every `1.000 1.000 1.000 rg`
     * (white) that is immediately followed by a `… re f` rectangle. It
     * deliberately reports NOTHING for a background of another colour or for one
     * that is not filled — a helper that returned a default rectangle for "nothing
     * found" would let every case here pass on a page dompdf never painted.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function pageFills(string $pdf): array
    {
        $fills = [];

        preg_match_all(
            '/1\.000 1\.000 1\.000 rg\s+(-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) re f/',
            $this->pdfText($pdf),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $fills[] = [(float) $match[1], (float) $match[2], (float) $match[3], (float) $match[4]];
        }

        return $fills;
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
     * @param  list<array<string, mixed>>  $layout
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

    /**
     * A REAL decodable non-square PNG registered as the user's `portrait`, so the
     * "portrait" state is a drawn picture and not an empty box.
     */
    private function storePortrait(User $user, int $width, int $height): void
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

        $this->assertIsArray(
            @getimagesizefromstring($bytes),
            'the portrait fixture must be a decodable image',
        );

        $path = "user-media/verband-a/{$user->id}/portrait-{$width}x{$height}.png";
        Storage::disk('private')->put($path, $bytes);

        UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => $path,
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'original_name' => 'portrait.png',
        ]);
    }

    /**
     * Extract the inflated dompdf content-stream text.
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

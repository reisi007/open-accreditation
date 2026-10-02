<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Resources\AdminApplicationResource;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\BadgeTemplate;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\QrTokenService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExtractsPdfContentStream;
use Tests\TestCase;

/**
 * P4 — badge templates, badge export (PDF/CSV) and public QR verification.
 *
 * Template CRUD is mandant-scoped and `can:accreditations.manage`-gated:
 * super_admin + mandant_admin manage, team_admin reads only (write → 403),
 * foreign mandants → 404. Layout validation is strict (field whitelist,
 * non-negative mm values, size > 0, align whitelist). `is_default` follows
 * the one-default-per-mandant rule.
 *
 * The export streams the approved applications of one accreditation; PDF
 * renders the template layout via dompdf, CSV is `;`-separated (DE-Excel).
 *
 * The QR token is a deterministic HMAC-signed application id; approval
 * (single + bulk) issues it, the public verify surface reads it back.
 */
class BadgeTest extends TestCase
{
    use ExtractsPdfContentStream;
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        // Position 45: approvals queue their notification, which runs inline on
        // the suite's `sync` connection; fake the mail so these badge assertions
        // cannot be aborted by a relay failure.
        Mail::fake();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        // F2: the badge export only mints verifiable badges for a mandant with
        // a domain of its OWN (the verify URL host is the mandant's first
        // domain). Give the exporting fixture the realistic onboarded shape —
        // a `mandant_domains` row — instead of letting it borrow the
        // `config('app.url')` fallback host. mandantB stays domain-less on
        // purpose (see the F2-Residual guard test below).
        $this->mandantA->domains()->create(['hostname' => 'a.test']);

        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->teamB = $this->mandantA->teams()->create(['name' => 'Team B', 'slug' => 'team-b']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Badge templates — auth, gates, team_admin read-only
     | ------------------------------------------------------------------- */

    public function test_badge_template_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/badge-templates')->assertStatus(401);
        $this->postJson('/api/admin/badge-templates', [])->assertStatus(401);

        $template = $this->createTemplateRow();

        $this->putJson('/api/admin/badge-templates/'.$template->id, [])->assertStatus(401);
        $this->deleteJson('/api/admin/badge-templates/'.$template->id)->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        $template = $this->createTemplateRow();

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/badge-templates')
                ->assertStatus(403, "expected 403 for {$role->value} on badge-templates index");

            $this->actingAsApi($user)->postJson('/api/admin/badge-templates', [
                'name' => 'Presse',
                'layout' => $this->validLayout(),
            ])
                ->assertStatus(403, "expected 403 for {$role->value} on badge-templates store");

            $this->actingAsApi($user)->putJson('/api/admin/badge-templates/'.$template->id, [
                'name' => 'Presse',
                'layout' => $this->validLayout(),
            ])
                ->assertStatus(403, "expected 403 for {$role->value} on badge-templates update");

            $this->actingAsApi($user)->deleteJson('/api/admin/badge-templates/'.$template->id)
                ->assertStatus(403, "expected 403 for {$role->value} on badge-templates delete");
        }
    }

    public function test_team_admin_can_read_but_not_write_templates(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $template = $this->createTemplateRow();

        $this->actingAsApi($teamAdmin)->getJson('/api/admin/badge-templates')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsApi($teamAdmin)->postJson('/api/admin/badge-templates', [
            'name' => 'Presse',
            'layout' => $this->validLayout(),
        ])->assertStatus(403);

        $this->actingAsApi($teamAdmin)->putJson('/api/admin/badge-templates/'.$template->id, [
            'name' => 'Presse',
            'layout' => $this->validLayout(),
        ])->assertStatus(403);

        $this->actingAsApi($teamAdmin)->deleteJson('/api/admin/badge-templates/'.$template->id)->assertStatus(403);
    }

    /* ---------------------------------------------------------------------
     | Badge templates — CRUD + validation
     | ------------------------------------------------------------------- */

    public function test_super_admin_can_create_and_list_templates(): void
    {
        $this->createTemplate(['name' => 'Zweiter']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/badge-templates', [
                'name' => 'Erster',
                'layout' => $this->validLayout(),
                'is_default' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Erster')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.layout.0.field', 'name')
            ->assertJsonPath('data.layout.0.align', 'left')
            ->assertJsonPath('data.layout.0.x', 10);

        $this->assertDatabaseHas('badge_templates', [
            'mandant_id' => $this->mandantA->id,
            'name' => 'Erster',
            'is_default' => true,
        ]);

        // name ASC listing, mandant-scoped.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/badge-templates')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Erster')
            ->assertJsonPath('data.1.name', 'Zweiter');
    }

    public function test_template_layout_validation_is_strict(): void
    {
        $base = [
            'name' => 'Presse',
            'layout' => [
                ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ],
        ];

        $cases = [
            // Schema v2 (badge-template-editor.md) whitelists `qr`, `team`
            // and `vest_number` — `sponsor` stands in for any unknown field.
            'unknown field' => [
                'layout' => [array_replace($base['layout'][0], ['field' => 'sponsor'])],
                'error' => 'layout.0.field',
            ],
            'negative x' => [
                'layout' => [array_replace($base['layout'][0], ['x' => -1])],
                'error' => 'layout.0.x',
            ],
            'negative w' => [
                'layout' => [array_replace($base['layout'][0], ['w' => -5])],
                'error' => 'layout.0.w',
            ],
            'zero size' => [
                'layout' => [array_replace($base['layout'][0], ['size' => 0])],
                'error' => 'layout.0.size',
            ],
            'invalid align' => [
                'layout' => [array_replace($base['layout'][0], ['align' => 'middle'])],
                'error' => 'layout.0.align',
            ],
            'missing size' => [
                'layout' => [array_replace($base['layout'][0], ['size' => null])],
                'error' => 'layout.0.size',
            ],
            'empty layout' => [
                'layout' => [],
                'error' => 'layout',
            ],
            'missing name' => [
                'name' => null,
                'error' => 'name',
            ],
        ];

        foreach ($cases as $label => $case) {
            $this->actingAsApi($this->superAdmin())
                ->postJson('/api/admin/badge-templates', [...$base, ...$case])
                ->assertStatus(422, "expected 422 for: {$label}")
                ->assertJsonValidationErrors($case['error']);
        }

        $this->assertDatabaseCount('badge_templates', 0);
    }

    public function test_badge_template_name_rejects_invalid_utf8_with_422_not_500(): void
    {
        // Form-encoded bytes (`name=\xFF`) survive into the validated payload
        // and would otherwise reach the JSON response encoder → HTTP 500.
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-templates', [
                'name' => "\xFF",
                'layout' => $this->validLayout(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('badge_templates', 0);
    }

    public function test_one_default_per_mandant(): void
    {
        $first = $this->createTemplate(['name' => 'Erste', 'is_default' => true]);
        $second = $this->createTemplate(['name' => 'Zweite', 'is_default' => true]);

        // Setting a new default unthrones the previous one — one per mandant.
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, BadgeTemplate::query()->forMandant($this->mandantA->id)->where('is_default', true)->count());

        // A default in a second mandant does not affect the first.
        BadgeTemplate::create([
            'mandant_id' => $this->mandantB->id,
            'name' => 'B-Default',
            'layout' => $this->validLayout(),
            'is_default' => true,
        ]);

        $this->assertSame(1, BadgeTemplate::query()->forMandant($this->mandantB->id)->where('is_default', true)->count());
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_update_template_replaces_name_and_layout_and_keeps_default(): void
    {
        $template = $this->createTemplate(['name' => 'Alt', 'is_default' => true]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/badge-templates/'.$template->id, [
                'name' => 'Neu',
                'layout' => [array_replace($this->validLayout()[0], ['size' => 20])],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.layout.0.size', 20);
    }

    public function test_update_can_clear_default_and_another_becomes_default(): void
    {
        $a = $this->createTemplate(['name' => 'A', 'is_default' => true]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/badge-templates/'.$a->id, [
                'name' => 'A',
                'layout' => $this->validLayout(),
                'is_default' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_default', false);

        $this->assertSame(0, BadgeTemplate::query()->forMandant($this->mandantA->id)->where('is_default', true)->count());

        $b = $this->createTemplate(['name' => 'B', 'is_default' => true]);
        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
    }

    public function test_delete_template_returns_204(): void
    {
        $template = $this->createTemplate();

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/badge-templates/'.$template->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('badge_templates', ['id' => $template->id]);
    }

    public function test_foreign_mandant_template_is_404(): void
    {
        $foreign = BadgeTemplate::create([
            'mandant_id' => $this->mandantB->id,
            'name' => 'Fremd',
            'layout' => $this->validLayout(),
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/badge-templates/'.$foreign->id, [
                'name' => 'Fremd',
                'layout' => $this->validLayout(),
            ])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/badge-templates/'.$foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('badge_templates', ['id' => $foreign->id, 'name' => 'Fremd']);
    }

    /* ---------------------------------------------------------------------
     | Badge export — auth, gates, team scope
     | ------------------------------------------------------------------- */

    public function test_export_requires_authentication(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'pdf'])
            ->assertStatus(401);
    }

    public function test_export_forbidden_for_user_and_verifier(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $this->createTemplate(['is_default' => true]);

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)
                ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'pdf'])
                ->assertStatus(403, "expected 403 for {$role->value} on badge export");
        }
    }

    public function test_export_is_scoped_to_the_team_admins_own_team(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);
        $this->createTemplate(['is_default' => true]);

        $own = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamA->id]);
        $foreign = $this->createAccreditation(['quota' => 5, 'team_id' => $this->teamB->id]);
        $mandantLevel = $this->createAccreditation(['quota' => 5]);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$own->id.'/badges/export', ['format' => 'csv'])
            ->assertOk();

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$foreign->id.'/badges/export', ['format' => 'csv'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/accreditations/'.$mandantLevel->id.'/badges/export', ['format' => 'csv'])
            ->assertStatus(403);
    }

    public function test_export_of_foreign_mandant_accreditation_is_404(): void
    {
        $categoryB = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $foreign = $this->mandantB->accreditations()->create(['category_id' => $categoryB->id, 'scope' => 'season', 'quota' => 5]);
        $this->createTemplate(['is_default' => true]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$foreign->id.'/badges/export', ['format' => 'pdf'])
            ->assertStatus(404);
    }

    /**
     * F2-Residual: the guard is UNCONDITIONAL — a domain-less mandant cannot
     * export verifiable badges even when the `config('app.url')` fallback host
     * is routed to nobody (the local single-box shape, this fixture's
     * `accreditation.test`). The former narrowing asked a fallback-host
     * ownership predicate as a second chance, so a domain-less mandant on an
     * unowned host still minted badges that would 404 the moment that host got
     * routed.
     *
     * FAILS WITH THE NARROWED GUARD: the fallback host is unowned here, so the
     * "does the mandant own it" test came out false and the export answered 200
     * (a template is not even needed — the guard is reached before
     * `resolveTemplate()`).
     */
    public function test_export_refuses_a_domainless_mandant_even_when_the_fallback_host_is_unowned(): void
    {
        $this->assertNull(
            $this->mandantB->domains()->value('hostname'),
            'precondition: mandant B has no domain of its own',
        );

        MandantContext::set($this->mandantB);

        $category = $this->mandantB->categories()->create(['name' => 'Presse', 'slug' => 'presse-b']);
        $accreditation = $this->mandantB->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'csv'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mandant hat keine Domain — QR-Codes können nicht generiert werden.');
    }

    public function test_export_rejects_invalid_format(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $this->createTemplate(['is_default' => true]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'xlsx'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    /* ---------------------------------------------------------------------
     | Badge export — PDF
     | ------------------------------------------------------------------- */

    /**
     * The whole card: the field texts AND the portrait.
     *
     * ## The field texts, and what an accident they used to be (Position 41)
     *
     * MEASURED on a real export of this fixture (SHA `d2e7331`): the card's
     * compressed content-stream payload is 232 bytes, its LAST BYTE is `0x0d`
     * — the low byte of the four-byte **Adler-32 trailer** zlib appends to every
     * payload (`0x1B8E5B0D`) — and the inflated stream is 444 bytes ending
     * `"\nQ\nQ"`.
     *
     * That trailing `0x0d` is where the old `rtrim()`-based extractor and this
     * assertion met, and the direction of the accident is a FAILED test, not a
     * silent pass. With the `rtrim()`-based extractor restored the byte is
     * stripped, `gzuncompress()` fails, the `@` swallows the warning and
     * `pdfText()` returns LESS text (the EMPTY STRING) — so
     * `assertStringContainsString('Jane Doe', $text)` FALLS: the field-text
     * assertion could never have been green without the name — it was a false
     * NEGATIVE caused by the extractor, not a false pass (measured by restoring
     * the `rtrim()` extractor; the trait names the same failure as "a missing
     * field instead of a broken extractor"). On the committed `/Length`-bounded
     * extractor `$text` carries the real operand `[(Jane Doe)]`, and the
     * assertion is green because the name genuinely is on the card.
     *
     * That hole is closed from two sides, and both are load-bearing:
     *   - the extractor is `/Length`-bounded (`ExtractsPdfContentStream`), and
     *     `ExtractsPdfContentStreamTest` pins the extractor itself, including a
     *     negative control for the fixtures that would NOT have caught it;
     *   - the assertions below name the **PDF text-showing operand**, not a bare
     *     byte sequence. `Jane Doe` could in principle occur anywhere in the
     *     inflated bytes; `[(Jane Doe)]` is a text operand of the content
     *     stream, and it disappears the moment the layout stops asking for the
     *     field (pinned by
     *     {@see self::test_the_field_text_assertions_fail_when_the_template_omits_the_field()}).
     *
     * ## The portrait
     *
     * **The picture assertion used to name dompdf's internal label, and it was
     * wrong for two independent reasons. Both are measured, both are fixed here.**
     *
     * 1. It passed for the WRONG IMAGE. The portrait fixture held the literal
     *    `'fake-portrait-bytes'`, which dompdf cannot decode: it substituted the
     *    built-in broken-image placeholder (an SVG, drawn as vectors) and embedded
     *    no XObject for the photo at all. The `/I1 Do` the test found was the QR
     *    code's. The promise in the old comment ("the portrait and the QR code are
     *    embedded as image XObjects") was never kept.
     * 2. It only held on SOME GD BUILDS. dompdf allocates `/I<n>` per embedded
     *    image, and for a PNG it takes the alpha-splitting path (mask + image, two
     *    labels) unless the file is colour type 2/4 or a palette with bit depth
     *    exactly 4 - `Cpdf::addPngFromFile()`:
     *    `$is_alpha = in_array($color_type, [4, 6]) || ($color_type == 3 && $bit_depth != 4)`.
     *    The QR is a PALETTE PNG whose bit depth is chosen by GD's quantiser when
     *    `endroid/qr-code` calls `imagetruecolortopalette($im, false, 16)`: on the
     *    CI image it came out as 4, so the QR took the single-label path and drew
     *    `/I1 Do`; here (libgd 2.3.3) it comes out as 1, so the QR draws `/I2 Do`
     *    and this test was red. Reproduced with two synthetic PNGs: an 8-colour
     *    palette (bit depth 4) draws `/I1 Do`, a 16-colour one quantised to bit
     *    depth 8 draws `/I2 Do`. An assertion about an internal counter cannot be
     *    pinned by a suite that runs on two GD builds.
     *
     * The drawing count this test used to carry is NOT here — it is
     * {@see self::test_export_pdf_draws_each_embedded_picture_exactly_once_across_the_alpha_mask_split()},
     * which is the guard Position 41 asked for and which stands on its own, so a
     * failing field text can no longer make it unreachable.
     */
    public function test_export_pdf_contains_template_field_text_and_photo(): void
    {
        $response = $this->exportCard($this->fullLayout());

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeaderContains('Content-Disposition', 'attachment');

        $pdf = $response->streamedContent();

        $this->assertStringStartsWith('%PDF-', $pdf);

        // Text-showing OPERANDS, not bare substrings: `[(Jane Doe)]` is what the
        // content stream contains, and it cannot be satisfied by a checksum byte
        // or by anything outside the operator.
        $text = $this->pdfText($pdf);

        $this->assertStringContainsString('[(Jane Doe)]', $text);
        $this->assertStringContainsString('[(Presse)]', $text);
        $this->assertStringContainsString('[(Finale)]', $text);

        // The portrait is embedded under ITS OWN dimensions (8x8, the fixture's)
        // - proof that the file on the private disk was decoded.
        $this->assertStringContainsString('/Width 8', $pdf);
        $this->assertStringContainsString('/Height 8', $pdf);

        // ... and NOT replaced by the bundled silhouette (512x512), which is
        // what a missing or undecodable portrait renders instead.
        $this->assertStringNotContainsString('/Width 512', $pdf);
        $this->assertStringNotContainsString('/Height 512', $pdf);
    }

    /**
     * The card draws each embedded picture exactly once — across the alpha split.
     *
     * This is the guard Position 41 asked for (board decision 2026-10-02: "ein
     * Wächter gegen die SMask-Zeichnungsanzahl"), and it is deliberately NOT an
     * `/I<n>` counter. See {@see self::cardPictureStructure()} for the measured
     * structure and the reason.
     *
     * It is its own test method rather than an assertion inside the field-text
     * test, because under the defect that produced Position 41 the FIRST
     * assertion of that method fired and the draw assertion at the end was never
     * reached: a guard that only runs when everything before it passed is not a
     * second guard.
     */
    public function test_export_pdf_draws_each_embedded_picture_exactly_once_across_the_alpha_mask_split(): void
    {
        $pdf = $this->renderApprovedCardPdf($this->fullLayout());

        $structure = $this->cardPictureStructure($pdf, $this->pdfText($pdf));

        $this->assertSame(
            [],
            $structure['violations'],
            "The card's picture structure is broken:\n - ".implode("\n - ", $structure['violations']),
        );

        // The two numbers the decision is about, restated so a red run says which
        // one moved: the DRAW count is host-independent, the REGISTERED-OBJECT
        // count is not, and nothing here reads it.
        $this->assertCount(2, $structure['draws'], 'The content stream must draw two pictures.');
        $this->assertCount(2, $structure['pictures'], 'The card must embed two picture XObjects.');
    }

    /**
     * THE HOST-INDEPENDENCE PROOF, runnable without CI.
     *
     * Both shapes are byte-level facts about what dompdf writes, taken from a
     * real export of this fixture:
     *
     * - `split` (measured here, libgd 2.3.3, QR palette bit depth 1): three
     *   registered image XObjects — portrait, the mask dompdf separated out of
     *   the QR, and the QR itself, which carries `/SMask <mask> 0 R`.
     * - `unsplit` (measured on the CI image, QR palette bit depth 4): two
     *   registered image XObjects and no `/SMask` anywhere.
     *
     * The registered-object count differs (3 vs 2) — that is the counter which
     * cannot be pinned. The verdict must not: both shapes have to come back
     * clean. That is the whole reason this guard counts drawings.
     */
    #[DataProvider('gdBuildShapesProvider')]
    public function test_the_picture_guard_gives_the_same_verdict_on_both_gd_builds(string $shape): void
    {
        $drawn = ['I1', 'I3'];
        $objects = [
            'I1' => '/Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceRGB',
        ];

        if ($shape === 'split') {
            // dompdf registers the mask as a resource too (`Cpdf::o_image()`:2327)
            // but never draws it — measured, see `cardPictureStructure()`.
            $objects['I2'] = '/Type /XObject /Subtype /Image /Width 300 /Height 300 /ColorSpace /DeviceGray';
            $objects['I3'] = '/Type /XObject /Subtype /Image /Width 300 /Height 300 /SMask @I2@ 0 R /ColorSpace /DeviceRGB';
        } else {
            $drawn = ['I1', 'I2'];
            $objects['I2'] = '/Type /XObject /Subtype /Image /Width 300 /Height 300 /ColorSpace /DeviceRGB';
        }

        $pdf = $this->syntheticCardPdf($objects, $drawn);
        $structure = $this->cardPictureStructure($pdf, $this->pdfText($pdf));

        $this->assertSame(
            $shape === 'split' ? 3 : 2,
            count($structure['registered']),
            'PREMISE: the two GD builds really do register a different number of image '
            .'XObjects. If this number is equal on both shapes, the fixture no longer '
            .'reproduces the difference the guard has to survive.',
        );

        $this->assertSame([], $structure['violations'], implode("\n", $structure['violations']));
        $this->assertCount(2, $structure['draws']);
        $this->assertCount(2, $structure['pictures']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gdBuildShapesProvider(): array
    {
        return [
            'alpha split (libgd 2.3.3 here: palette bit depth 1)' => ['split'],
            'no alpha split (CI image: palette bit depth 4)' => ['unsplit'],
        ];
    }

    /**
     * THE GUARD BITES, on a real card: a template without the `photo` row.
     *
     * Same renderer, same fixture, one layout entry removed. The QR is still
     * drawn, the portrait is neither embedded nor drawn — and the guard says so
     * instead of passing. This is the mutation proof for the draw count, run
     * through the production path rather than through a hand-built PDF.
     */
    public function test_the_picture_guard_reports_a_card_whose_portrait_is_never_drawn(): void
    {
        $withoutPhoto = array_values(array_filter(
            $this->fullLayout(),
            static fn (array $row): bool => $row['field'] !== 'photo',
        ));

        $this->assertCount(
            3,
            $withoutPhoto,
            'PREMISE: the negative fixture really is the full layout minus its photo row — '
            .'otherwise this test proves nothing about a missing picture.',
        );

        $pdf = $this->renderApprovedCardPdf($withoutPhoto);
        $structure = $this->cardPictureStructure($pdf, $this->pdfText($pdf));

        $this->assertCount(1, $structure['draws'], 'PREMISE: only the QR is drawn now.');
        $this->assertCount(1, $structure['pictures'], 'PREMISE: only the QR is embedded now.');
        $this->assertNotSame([], $structure['violations'], 'The guard must report a card that draws one picture.');
        $this->assertStringContainsString(
            'must draw exactly TWO pictures',
            implode("\n", $structure['violations']),
            'The draw count is the rule that has to fire here.',
        );
        $this->assertStringContainsString(
            'must embed exactly TWO pictures',
            implode("\n", $structure['violations']),
        );
    }

    /**
     * THE SMask RULES BITE — three ways, on hand-built PDFs.
     *
     * dompdf never produces these on purpose, so no card can reach them; a rule
     * no card can reach is a rule nobody tests. Each case carries a premise
     * assertion for the property it is supposed to demonstrate, and a synthetic
     * fixture chosen because it triggers exactly one of them.
     *
     * @see self::gdBuildShapesProvider() for the two shapes a HEALTHY card has.
     */
    #[DataProvider('brokenPictureShapesProvider')]
    public function test_the_picture_guard_catches_a_broken_draw(string $case, array $objects, array $drawn, string $expectedRule): void
    {
        $pdf = $this->syntheticCardPdf($objects, $drawn);
        $structure = $this->cardPictureStructure($pdf, $this->pdfText($pdf));

        $this->assertNotSame(
            [],
            $structure['violations'],
            "The guard reported nothing for the '{$case}' shape. A guard that cannot fail is "
            .'worse than an honest gap — see `features/badges-qr.md:355-373`.',
        );

        $this->assertStringContainsString(
            $expectedRule,
            implode("\n", $structure['violations']),
            "The '{$case}' shape must trip its own rule.",
        );
    }

    /**
     * @return array<string, array{string, array<string, string>, list<string>, string}>
     */
    public static function brokenPictureShapesProvider(): array
    {
        $portrait = '/Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceRGB';
        $mask = '/Type /XObject /Subtype /Image /Width 300 /Height 300 /ColorSpace /DeviceGray';
        $qr = '/Type /XObject /Subtype /Image /Width 300 /Height 300 /SMask @I2@ 0 R /ColorSpace /DeviceRGB';

        return [
            // dompdf's own mask object drawn as if it were a picture.
            'the alpha mask is drawn' => [
                'the alpha mask is drawn',
                ['I1' => $portrait, 'I2' => $mask, 'I3' => $qr],
                ['I1', 'I2', 'I3'],
                'is the alpha mask dompdf separated out of a picture',
            ],
            // Embedded, registered, never drawn — the silent variant of the defect
            // the old `/I<n>` assertion had, in the direction a count misses.
            'an embedded picture is never drawn' => [
                'an embedded picture is never drawn',
                ['I1' => $portrait, 'I2' => $mask, 'I3' => $qr],
                ['I3'],
                'is an embedded picture and must be drawn exactly once',
            ],
            // A drawing operation on a label the page does not register: a count
            // of `Do` alone would call this two pictures.
            'a draw without a registered image' => [
                'a draw without a registered image',
                ['I1' => $portrait, 'I2' => $mask, 'I3' => $qr],
                ['I1', 'I9'],
                'which the page does not register as an image XObject',
            ],
        ];
    }

    /**
     * The field-text assertions, measured against a layout that stops asking for
     * the field — the proof that `[(Jane Doe)]` is a content assertion and not a
     * checksum byte.
     */
    public function test_the_field_text_assertions_fail_when_the_template_omits_the_field(): void
    {
        $withoutName = array_values(array_filter(
            $this->fullLayout(),
            static fn (array $row): bool => $row['field'] !== 'name',
        ));

        $this->assertCount(3, $withoutName, 'PREMISE: the negative fixture is the full layout minus its name row.');

        $text = $this->pdfText($this->renderApprovedCardPdf($withoutName));

        $this->assertStringNotContainsString(
            '[(Jane Doe)]',
            $text,
            'PREMISE: without the `name` row the text-showing operand is gone — so the assertion in '
            .'test_export_pdf_contains_template_field_text_and_photo() is sensitive to the template '
            .'and not to a payload byte. If this fails, `Jane Doe` is reaching the test by some other '
            .'route and that assertion needs to know.',
        );

        // The rows that are still there are unaffected — this is a defect in the
        // template, not in the renderer.
        $this->assertStringContainsString('[(Presse)]', $text);
        $this->assertStringContainsString('[(Finale)]', $text);
    }

    public function test_export_pdf_uses_default_template_when_template_id_is_absent(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $jane = User::factory()->create(['name' => 'Jane Doe']);
        $this->makeApplication($accreditation, $jane, ['status' => 'approved']);

        $this->createTemplate(['name' => 'Default', 'is_default' => true]);

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'pdf']);

        $response->assertOk();
        $this->assertStringContainsString('Jane Doe', $this->pdfText($response->streamedContent()));
    }

    public function test_export_without_default_template_is_422(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'pdf'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No badge template.');
    }

    public function test_export_with_foreign_template_id_is_404(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $foreign = BadgeTemplate::create([
            'mandant_id' => $this->mandantB->id,
            'name' => 'Fremd',
            'layout' => $this->validLayout(),
        ]);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', [
                'format' => 'pdf',
                'template_id' => $foreign->id,
            ])
            ->assertStatus(404);
    }

    public function test_export_empty_approved_list_returns_an_empty_document(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $jane = User::factory()->create(['name' => 'Jane Doe']);
        $this->makeApplication($accreditation, $jane, ['status' => 'requested']);
        $this->createTemplate(['is_default' => true]);

        // PDF: a valid, empty document (blank A6 page) — 200, not 204.
        $pdf = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'pdf'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->streamedContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('Jane Doe', $this->pdfText($pdf));
    }

    /* ---------------------------------------------------------------------
     | Badge export — CSV
     | ------------------------------------------------------------------- */

    public function test_export_csv_contains_header_and_rows(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $this->createAccreditation(['quota' => 5, 'scope' => 'event', 'event_id' => $event->id]);
        $jane = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $this->makeApplication($accreditation, $jane, ['status' => 'approved']);
        $this->createTemplate(['is_default' => true]);

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'csv']);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeaderContains('Content-Disposition', 'attachment');

        $csv = $response->streamedContent();

        // UTF-8 BOM so DE-Excel decodes umlauts correctly.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Name;E-Mail;Kategorie;Event;Status;Verify-URL', $csv);

        // PHP 8.5 fputcsv encloses space-containing fields ("Jane Doe"), so
        // the row is parsed back instead of substring-matched.
        $lines = explode("\n", trim(substr($csv, 3)));
        $row = str_getcsv($lines[1], ';');

        $this->assertSame('Jane Doe', $row[0]);
        $this->assertSame('jane@example.com', $row[1]);
        $this->assertSame('Presse', $row[2]);
        $this->assertSame('Finale', $row[3]);
        $this->assertSame('Akkreditiert', $row[4]);
        $this->assertStringStartsWith($this->expectedVerifyUrlPrefix('a.test'), $row[5]);
    }

    public function test_export_csv_only_contains_approved_applications(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);

        $approved = User::factory()->create(['name' => 'Jane Doe']);
        $requested = User::factory()->create(['name' => 'John Smith']);
        $denied = User::factory()->create(['name' => 'Jim Brown']);

        $this->makeApplication($accreditation, $approved, ['status' => 'approved']);
        $this->makeApplication($accreditation, $requested, ['status' => 'requested']);
        $this->makeApplication($accreditation, $denied, ['status' => 'denied', 'reason' => 'x']);
        $this->createTemplate(['is_default' => true]);

        $csv = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'csv'])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Jane Doe', $csv);
        $this->assertStringNotContainsString('John Smith', $csv);
        $this->assertStringNotContainsString('Jim Brown', $csv);
        $this->assertSame(2, substr_count($csv, "\n"));
    }

    public function test_export_csv_neutralizes_formula_injection_cells(): void
    {
        $event = $this->mandantA->events()->create(['title' => '-Finale; DROP TABLE users', 'date' => '2026-09-01']);
        $category = $this->mandantA->categories()->create(['name' => '+Presse', 'slug' => 'presse-inject']);
        $accreditation = $this->mandantA->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'event',
            'quota' => 5,
            'event_id' => $event->id,
        ]);

        $evil = User::factory()->create([
            'name' => '=HYPERLINK("http://evil.example","x")',
            'email' => '=2+2@example.com',
        ]);
        $normal = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->makeApplication($accreditation, $evil, ['status' => 'approved']);
        $this->makeApplication($accreditation, $normal, ['status' => 'approved']);
        $this->createTemplate(['is_default' => true]);

        $csv = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', ['format' => 'csv'])
            ->assertOk()
            ->streamedContent();

        // Rows are ordered by application id: evil first, normal second.
        $lines = explode("\n", trim(substr($csv, 3)));
        $evilRow = str_getcsv($lines[1], ';');
        $normalRow = str_getcsv($lines[2], ';');

        // Every user-controlled cell that starts with a formula marker gets a
        // leading `'` so Excel/Sheets render it as text.
        $this->assertSame("'=HYPERLINK(\"http://evil.example\",\"x\")", $evilRow[0]);
        $this->assertStringStartsWith("'=", $evilRow[1]);
        $this->assertStringStartsWith("'+", $evilRow[2]);
        $this->assertStringStartsWith("'-", $evilRow[3]);

        // The verify URL cell goes through the same helper (unchanged here).
        $this->assertStringStartsWith($this->expectedVerifyUrlPrefix('a.test'), $evilRow[5]);

        // A normal name/email stays untouched; the shared category/event are
        // dangerous (`+`/`-`) and sanitized in every row.
        $this->assertSame('Jane Doe', $normalRow[0]);
        $this->assertSame('jane@example.com', $normalRow[1]);
        $this->assertSame("'+Presse", $normalRow[2]);
        $this->assertSame("'-Finale; DROP TABLE users", $normalRow[3]);
    }

    /* ---------------------------------------------------------------------
     | QrTokenService — determinism, tamper detection, secrets
     | ------------------------------------------------------------------- */

    public function test_qr_token_roundtrip_and_determinism(): void
    {
        $application = $this->makeApplication($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $service = app(QrTokenService::class);
        $token = $service->make($application);

        $this->assertNotNull($token);
        $this->assertSame($application->id, $service->parse($token)?->applicationId);

        // Deterministic: same application → same token, stored on the row.
        $this->assertSame($token, $service->make($application->fresh()));
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'qr_token' => $token]);
    }

    public function test_qr_token_tampering_is_rejected(): void
    {
        $application = $this->makeApplication($this->createAccreditation(['quota' => 5]), User::factory()->create());
        $service = app(QrTokenService::class);
        $token = $service->make($application);

        // Flip a character in the MIDDLE of the token (inside the signature's
        // base64, full group) — flipping the LAST char is a no-op for single-digit
        // ids (the final group carries only padding bits, so the decoded
        // signature is unchanged and `parse()` still returns the id).
        $tampered = substr_replace($token, substr($token, 10, 1) === 'a' ? 'b' : 'a', 10, 1);
        $this->assertNull($service->parse($tampered));

        $parts = $this->splitV2Payload($this->decode($token));

        // THE PREMISE, stated instead of inherited: whether THIS run's signature
        // carries a '.' byte decides what a naive explode() would have made of
        // the payload below — three segments if it does not, four or more if it
        // does. It is a fact about the id the DB sequence handed out, not an
        // invariant of the format. Pinned as a RELATION, so it holds for every
        // id; its failure message names the count a naive split produces.
        $this->assertSame(
            3 + substr_count($parts['signature'], '.'),
            $parts['naiveSegmentCount'],
            sprintf(
                'premise: the signature of application %d %s a "." byte, so a naive explode() yields %d segments, not 3 — the claims are read from the outside in',
                $application->id,
                $parts['signatureHasDot'] ? 'carries' : 'does not carry',
                $parts['naiveSegmentCount'],
            ),
        );

        // CONTROL: this very payload IS a valid token, so what the rejections
        // below refuse is the tampered CLAIM and nothing else. Without it a
        // payload that had been mangled on the way here — a naive explode()
        // truncated a dotted signature at its first '.' byte — would be rejected
        // for its shape and every assertion below would be green for the wrong
        // reason. The round trip is asserted as well, because a split that drops
        // or duplicates a byte cannot be the origin of an identical token.
        $unaltered = $this->encode($parts['id'].'.'.$parts['signature'].'.'.$parts['mandantId']);

        $this->assertSame($token, $unaltered, 'control: re-encoding the three claims must reproduce the token byte for byte — the split lost or moved bytes');
        $this->assertNotNull(
            $service->parse($unaltered),
            'control: the unaltered payload must verify — otherwise the rejections below prove nothing',
        );

        // THE forgery this test exists for: the signature stays byte-identical
        // and only the application id claim is swapped, so the payload is
        // well-formed v2 down to its last separator and parse() really takes the
        // tenant-bound branch. It is rejected because the id claim is part of
        // the signed message ('v2:<id>:<mandantId>'), not because the payload
        // fell out of the format.
        $forgedId = $parts['id'] === '1' ? '2' : '1';
        $forgedV2 = $this->encode($forgedId.'.'.$parts['signature'].'.'.$parts['mandantId']);

        $this->assertNull(
            $service->parse($forgedV2),
            'the application id claim is covered by the HMAC, so a different id must not verify against this signature — dotted signature or not',
        );
        $this->assertFalse($service->isValidFor($application, $forgedV2));

        // The same signature presented in the LEGACY two-segment shape, now
        // COMPLETE: a v2 signature must not be readable as a v1 one, whatever it
        // contains. This is the assertion the naive explode() silently turned
        // into a different one — `$parts[1]` was a truncated prefix, so the
        // legacy check had already rejected it for a truncated HMAC.
        $forgedLegacy = $this->encode($forgedId.'.'.$parts['signature']);

        $this->assertNull(
            $service->parse($forgedLegacy),
            'a v2 signature is not a v1 signature: dropping the mandant claim must not make it verify',
        );

        $this->assertNull($service->parse('garbage'));
        $this->assertNull($service->parse(''));
    }

    /**
     * The tampering above, run against CHOSEN application ids instead of
     * whatever the sequence hands out — including ids whose v2 signature really
     * does carry a '.' byte, which is the case the naive split got wrong.
     *
     * Whether an id is dotted is a property of the PAIR (application id, mandant
     * id) under the signing key in force. Measured on the key pinned at
     * `phpunit.xml:44` (`base64:adai6…WE60=`), which is the key this suite runs
     * under: **11.505 %** of the application ids 1..20000 produce a v2
     * signature with a 0x2E byte; for mandant 1 the offenders within ids 1..40
     * are 13, 22, 28, 30 and 40. That is why every case names BOTH claims and
     * why the third element states the premise instead of leaving the test to
     * assume it: a changed key or a changed pair is then reported, not silently
     * downgraded to another dot-free case.
     *
     * Nothing is written and no sequence is consumed — `QrTokenService::token()`
     * is pure and reads the mandant claim from the preloaded relation — so these
     * ids come from the fixture. That is precisely what the test above cannot
     * guarantee.
     *
     * @return array<string, array{int, int, bool}>
     */
    public static function forgedIdPairs(): array
    {
        return [
            'application id 13, mandant id 1 (dotted)' => [13, 1, true],
            'application id 22, mandant id 1 (dotted)' => [22, 1, true],
            'application id 40, mandant id 1 (dotted)' => [40, 1, true],
            'application id 7, mandant id 2 (dotted)' => [7, 2, true],
            'application id 27, mandant id 4 (dotted)' => [27, 4, true],
            'application id 8, mandant id 5 (dotted)' => [8, 5, true],
            'application id 1, mandant id 1 (dot-free control)' => [1, 1, false],
        ];
    }

    #[DataProvider('forgedIdPairs')]
    public function test_a_forged_application_id_claim_is_rejected_for_every_signature_shape(int $applicationId, int $mandantId, bool $expectDotted): void
    {
        $service = app(QrTokenService::class);
        $application = $this->applicationWithId($applicationId, $mandantId);
        $token = $service->token($application);
        $parts = $this->splitV2Payload($this->decode($token));

        // Announced: this case is dotted (or deliberately is not), and what a
        // naive three-segment split would have concluded about it.
        $this->assertSame($expectDotted, $parts['signatureHasDot'], sprintf(
            'premise: the signature of application %d for mandant %d is expected %s a "." byte under the signing key in use',
            $applicationId,
            $mandantId,
            $expectDotted ? 'to carry' : 'NOT to carry',
        ));

        if ($expectDotted) {
            $this->assertGreaterThan(
                3,
                $parts['naiveSegmentCount'],
                'premise: a dotted signature MUST make a naive explode() yield more than three segments — otherwise this case proves nothing',
            );
        }

        // CONTROL, see test_qr_token_tampering_is_rejected(): the unaltered
        // payload must verify, so the rejection below is about the swapped claim
        // and not about a payload mangled on the way here.
        $this->assertNotNull(
            $service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.'.$parts['mandantId'])),
            'control: the unaltered payload must verify — otherwise the rejection below proves nothing',
        );

        $forgedId = (string) ($applicationId + 1);
        $forged = $this->encode($forgedId.'.'.$parts['signature'].'.'.$parts['mandantId']);

        $this->assertNull(
            $service->parse($forged),
            'only the application id claim differs and it is part of the signed message, so this signature must not verify against a neighbouring id — whether or not the signature itself contains a "." byte',
        );
        $this->assertFalse($service->isValidFor($application, $forged));

        // A truncated signature (what a naive explode() used to hand out) is
        // also rejected, and the distinction matters: this assertion holds for
        // the COMPLETE signature above as well, so the rejection cannot be
        // explained by the shape of the forged payload.
        $this->assertNull(
            $service->parse($this->encode($forgedId.'.'.$parts['signature'])),
            'a complete v2 signature must not verify in the legacy two-segment shape',
        );
    }

    public function test_qr_token_with_wrong_secret_is_rejected(): void
    {
        $application = $this->makeApplication($this->createAccreditation(['quota' => 5]), User::factory()->create());
        $token = app(QrTokenService::class)->make($application);

        $this->assertNull((new QrTokenService('some-other-secret'))->parse($token));

        // The same explicit secret reproduces the token.
        $sameSecret = new QrTokenService((string) config('app.key'));
        $this->assertSame($application->id, $sameSecret->parse($token)?->applicationId);
        $this->assertSame($token, $sameSecret->make($application->fresh()));
    }

    /* ---------------------------------------------------------------------
     | qr_token issuance at approval time
     | ------------------------------------------------------------------- */

    public function test_qr_token_is_set_on_single_approve(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/applications/'.$application->id, ['status' => 'approved'])
            ->assertOk();

        $token = $application->fresh()->qr_token;

        $this->assertNotNull($token);
        $this->assertSame($application->id, app(QrTokenService::class)->parse($token)?->applicationId);
    }

    public function test_qr_token_is_set_on_bulk_allocate_all(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 3]);
        $first = $this->makeApplication($accreditation, User::factory()->create());
        $second = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.approved', 2);

        $this->assertNotNull($first->fresh()->qr_token);
        $this->assertNotNull($second->fresh()->qr_token);
        $this->assertSame($first->id, app(QrTokenService::class)->parse((string) $first->fresh()->qr_token)?->applicationId);
    }

    public function test_qr_token_is_set_on_bulk_allocate_first(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 3]);
        $first = $this->makeApplication($accreditation, User::factory()->create());
        $second = $this->makeApplication($accreditation, User::factory()->create());

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/allocate', ['mode' => 'first', 'limit' => 1])
            ->assertOk()
            ->assertJsonPath('data.approved', 1);

        $this->assertNotNull($first->fresh()->qr_token);
        $this->assertNull($second->fresh()->qr_token);
    }

    public function test_legacy_approved_application_gets_token_via_service(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);

        $this->assertNull($application->qr_token);

        $token = app(QrTokenService::class)->make($application);

        $this->assertNotNull($application->fresh()->qr_token);
        $this->assertSame($token, $application->fresh()->qr_token);
    }

    /* ---------------------------------------------------------------------
     | Public verification
     | ------------------------------------------------------------------- */

    public function test_verify_approved_token_returns_full_data(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $this->createAccreditation(['quota' => 5, 'scope' => 'event', 'event_id' => $event->id]);
        $jane = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->makeApplication($accreditation, $jane, ['status' => 'approved']);
        $token = app(QrTokenService::class)->make($application);

        $this->getJson('/api/verify/'.$token)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.category', 'Presse')
            ->assertJsonPath('data.event', 'Finale')
            ->assertJsonPath('data.date', '2026-09-01')
            ->assertJsonPath('data.photo_url', '/api/verify/'.$token.'/photo');
    }

    public function test_verify_non_approved_statuses_return_only_status(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);

        foreach (['requested', 'denied', 'blacklisted'] as $status) {
            $application = $this->makeApplication(
                $accreditation,
                User::factory()->create(['name' => 'Geheim']),
                ['status' => $status],
            );
            $token = app(QrTokenService::class)->make($application);

            $this->getJson('/api/verify/'.$token)
                ->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonCount(1, 'data')
                ->assertJsonMissingPath('data.name')
                ->assertJsonMissingPath('data.category')
                ->assertJsonMissingPath('data.photo_url');
        }
    }

    public function test_verify_invalid_and_tampered_tokens_are_404(): void
    {
        $this->getJson('/api/verify/not-a-real-token')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Invalid verification token.');

        $application = $this->makeApplication($this->createAccreditation(['quota' => 5]), User::factory()->create());
        $token = app(QrTokenService::class)->make($application);
        $tampered = substr_replace($token, substr($token, 10, 1) === 'a' ? 'b' : 'a', 10, 1);

        $this->getJson('/api/verify/'.$tampered)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Invalid verification token.');
    }

    public function test_verify_photo_returns_inline_portrait_for_approved_application(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $jane = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->makeApplication($accreditation, $jane, ['status' => 'approved']);
        $this->storePortrait($jane);
        $token = app(QrTokenService::class)->make($application);

        $this->getJson('/api/verify/'.$token.'/photo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeaderContains('Content-Disposition', 'inline');
    }

    public function test_verify_photo_without_portrait_is_404(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);
        $token = app(QrTokenService::class)->make($application);

        $this->getJson('/api/verify/'.$token.'/photo')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Invalid verification token.');
    }

    public function test_verify_photo_of_denied_application_is_404(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $jane = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->makeApplication($accreditation, $jane, ['status' => 'denied', 'reason' => 'x']);
        $this->storePortrait($jane);
        $token = app(QrTokenService::class)->make($application);

        $this->getJson('/api/verify/'.$token.'/photo')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Invalid verification token.');
    }

    public function test_verify_route_is_rate_limited(): void
    {
        Cache::flush();

        // The dedicated `verify` limiter (P4-F3) is env-dependent: 300/min in
        // local/testing (raised for the ui-review screenshot suite), 60/min in
        // production.
        $limit = app()->environment('local', 'testing') ? 300 : 60;

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson('/api/verify/not-a-real-token')->assertStatus(404);
        }

        $this->getJson('/api/verify/not-a-real-token')->assertStatus(429);
    }

    /* ---------------------------------------------------------------------
     | Admin application resource — qr_url
     | ------------------------------------------------------------------- */

    public function test_admin_application_resource_includes_qr_url_only_for_approved(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 10]);
        $approved = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);
        $requested = $this->makeApplication($accreditation, User::factory()->create());

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications')
            ->assertOk();

        $data = collect($response->json('data'));

        $approvedEntry = $data->firstWhere('id', $approved->id);
        $requestedEntry = $data->firstWhere('id', $requested->id);

        // The approved row has no stored token — the resource computes the
        // deterministic token on read (without persisting it).
        $this->assertNotNull($approvedEntry['qr_url']);
        $this->assertStringStartsWith('/verify/', $approvedEntry['qr_url']);

        // Read path must NOT write: the DB row stays NULL after serialization.
        $this->assertNull($approved->fresh()->qr_token);

        // The computed token still resolves to the application (idempotent HMAC).
        $computed = substr($approvedEntry['qr_url'], strlen('/verify/'));
        $this->assertSame($approved->id, app(QrTokenService::class)->parse($computed)?->applicationId);

        $this->assertNull($requestedEntry['qr_url']);
    }

    public function test_serializing_approved_application_with_null_qr_token_does_not_persist(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $application = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);

        $this->assertNull($application->qr_token);

        // Serialize the resource directly (the admin approval view does this).
        $request = Request::create('/api/admin/applications');
        $array = (new AdminApplicationResource($application))->toArray($request);

        // qr_url is still populated — computed from the deterministic token.
        $this->assertNotNull($array['qr_url']);
        $this->assertStringStartsWith('/verify/', $array['qr_url']);

        $token = substr($array['qr_url'], strlen('/verify/'));
        $this->assertSame($application->id, app(QrTokenService::class)->parse($token)?->applicationId);

        // No write-on-read: the DB row remains untouched.
        $this->assertNull($application->fresh()->qr_token);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'qr_token' => null,
        ]);
    }

    public function test_backfill_qr_tokens_command_fills_approved_without_token(): void
    {
        $accreditation = $this->createAccreditation(['quota' => 5]);
        $approved = $this->makeApplication($accreditation, User::factory()->create(), ['status' => 'approved']);
        $requested = $this->makeApplication($accreditation, User::factory()->create());

        $this->assertNull($approved->fresh()->qr_token);
        $this->assertNull($requested->fresh()->qr_token);

        $exit = Artisan::call('accreditation:backfill-qr-tokens');

        $this->assertSame(0, $exit);

        // Only the approved row got a token; requested rows are untouched.
        $token = $approved->fresh()->qr_token;
        $this->assertNotNull($token);
        $this->assertSame($approved->id, app(QrTokenService::class)->parse($token)?->applicationId);
        $this->assertNull($requested->fresh()->qr_token);

        // Idempotent: a second run keeps the same token and does not error.
        Artisan::call('accreditation:backfill-qr-tokens');
        $this->assertSame($token, $approved->fresh()->qr_token);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private static int $mediaCount = 0;

    /**
     * POST the PDF export for one approved application, once, with the given
     * template layout. Every card fixture in this file goes through here, so the
     * four picture tests differ ONLY in their layout.
     */
    private function exportCard(array $layout): TestResponse
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $this->createAccreditation(['quota' => 5, 'scope' => 'event', 'event_id' => $event->id]);
        $jane = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $this->makeApplication($accreditation, $jane, ['status' => 'approved']);
        $this->storePortrait($jane);

        $template = $this->createTemplate(['name' => 'Presseausweis']);
        $template->update(['layout' => $layout]);

        return $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', [
                'format' => 'pdf',
                'template_id' => $template->id,
            ]);
    }

    /**
     * The raw PDF of that card, with the export itself already checked for 200.
     */
    private function renderApprovedCardPdf(array $layout): string
    {
        return $this->exportCard($layout)->assertOk()->streamedContent();
    }

    /**
     * The picture structure of a card: what is EMBEDDED, what is DRAWN, and
     * every rule that does not hold.
     *
     * ## Why this counts drawings and not `/I<n>` labels (Position 41)
     *
     * dompdf separates an alpha PNG into a mask plus a base image and registers
     * BOTH as page resources — `Cpdf::o_image()`:
     *
     *     if (isset($options['masked']) && $options['masked']) {
     *         $info['SMask'] = ($this->numObj - 1) . ' 0 R';
     *     }
     *
     * … but it only ever DRAWS the base one. Whether the split happens at all is
     * `$is_alpha` in `Cpdf::addPngFromFile()`:6255,
     * `in_array($color_type, [4, 6]) || ($color_type == 3 && $bit_depth != 4)`,
     * and the QR code is a PALETTE PNG whose bit depth the GD quantiser picks —
     * measured 4 on the CI image, 1 here (libgd 2.3.3). So the number of
     * registered image objects is **2 or 3 depending on the host**, while the
     * number of `Do` operations is **2 either way**. An `/I<n>` assertion is
     * therefore green on one build and red on the other; a draw count is not.
     *
     * MEASURED on this host (real export of `fullLayout()`, SHA `d2e7331`):
     *
     * | object | what                                    | registered as | drawn |
     * |--------|-----------------------------------------|---------------|-------|
     * | 13     | portrait 8x8, `/DeviceRGB`, no SMask    | `/I1`         | yes   |
     * | 16     | QR mask 300x300, `/DeviceGray`          | `/I2`         | NEVER |
     * | 17     | QR 300x300, `/SMask 16 0 R`, `/DeviceRGB`| `/I3`        | yes   |
     *
     * So object 16 is registered, is never drawn, and is not a picture — it is
     * the mask of object 17. That classification is what makes the guard
     * host-independent: it splits the registered objects into pictures and alpha
     * masks, and holds both to rules that hold on either build.
     *
     * The rules, in the order they are reported:
     *
     * 1. the content stream draws exactly TWO pictures (portrait + QR);
     * 2. every drawn label is registered as an image XObject;
     * 3. an alpha mask is drawn ZERO times (it is bookkeeping, not a picture);
     * 4. every embedded picture is drawn EXACTLY once;
     * 5. the card embeds exactly TWO picture XObjects.
     *
     * Rule 1 and 5 are what a count alone would have said; 2–4 are what it could
     * not. Each is made to fail, in
     * {@see self::test_the_picture_guard_catches_a_broken_draw()} and
     * {@see self::test_the_picture_guard_reports_a_card_whose_portrait_is_never_drawn()}.
     *
     * @return array{draws: list<string>, pictures: list<string>, masks: list<int>, registered: list<int>, violations: list<string>}
     */
    private function cardPictureStructure(string $pdf, string $contentStream): array
    {
        $resources = $this->pageImageResources($pdf);

        // label => object number, for objects that really are images.
        $labels = [];
        foreach ($resources as $label => $objectNumber) {
            $dictionary = $this->pdfObjectDictionary($pdf, $objectNumber);

            if ($dictionary !== null && str_contains($dictionary, '/Subtype /Image')) {
                $labels[$objectNumber] = $label;
            }
        }

        // Object numbers that some other image names in ITS `/SMask` — those are
        // masks, not pictures. Measured above: 16 is named by 17.
        $masks = [];
        foreach (array_keys($labels) as $objectNumber) {
            $dictionary = $this->pdfObjectDictionary($pdf, $objectNumber);

            if ($dictionary !== null && preg_match('#/SMask\s+(\d+)\s+0\s+R#', $dictionary, $matches) === 1) {
                $masks[(int) $matches[1]] = true;
            }
        }

        preg_match_all('#/([A-Za-z0-9_.-]+)\s+Do\b#', $contentStream, $drawMatches);
        $draws = $drawMatches[1];

        $pictures = [];
        foreach ($labels as $objectNumber => $label) {
            if (! isset($masks[$objectNumber])) {
                $pictures[] = $label;
            }
        }

        $drawnPerLabel = array_count_values($draws);
        $violations = [];

        if (count($draws) !== 2) {
            $violations[] = sprintf(
                'The content stream must draw exactly TWO pictures (portrait + QR); it drew %d [%s].',
                count($draws),
                implode(', ', $draws),
            );
        }

        foreach ($draws as $label) {
            $objectNumber = $resources[$label] ?? null;

            if ($objectNumber === null || ! isset($labels[$objectNumber])) {
                $violations[] = sprintf(
                    'The content stream draws /%s, which the page does not register as an image XObject.',
                    $label,
                );
            }
        }

        foreach ($labels as $objectNumber => $label) {
            $drawn = $drawnPerLabel[$label] ?? 0;

            if (isset($masks[$objectNumber])) {
                if ($drawn > 0) {
                    $violations[] = sprintf(
                        '/%s is the alpha mask dompdf separated out of a picture, not a picture itself, '
                        .'but it was drawn %d time(s).',
                        $label,
                        $drawn,
                    );
                }

                continue;
            }

            if ($drawn !== 1) {
                $violations[] = sprintf(
                    '/%s is an embedded picture and must be drawn exactly once; it was drawn %d time(s).',
                    $label,
                    $drawn,
                );
            }
        }

        if (count($pictures) !== 2) {
            $violations[] = sprintf(
                'The card must embed exactly TWO pictures (portrait + QR) as image XObjects; it embedded %d [%s].',
                count($pictures),
                implode(', ', $pictures),
            );
        }

        return [
            'draws' => $draws,
            'pictures' => $pictures,
            'masks' => array_keys($masks),
            'registered' => array_keys($labels),
            'violations' => $violations,
        ];
    }

    /**
     * Every `/XObject` name the page's resource dictionary maps to an object, as
     * `label => object number`.
     *
     * @return array<string, int>
     */
    private function pageImageResources(string $pdf): array
    {
        // dompdf writes the resource dictionary of the page tree in the clear —
        // only stream PAYLOADS are compressed, so this is readable here.
        if (preg_match('#/XObject\s*<<(.*?)>>#s', $pdf, $matches) !== 1) {
            return [];
        }

        preg_match_all('#/([A-Za-z0-9_.-]+)\s+(\d+)\s+0\s+R#', $matches[1], $entries, PREG_SET_ORDER);

        $resources = [];
        foreach ($entries as [, $label, $objectNumber]) {
            $resources[$label] = (int) $objectNumber;
        }

        return $resources;
    }

    /**
     * One indirect object's dictionary as plain text, or `null` when the object
     * is not in the file.
     */
    private function pdfObjectDictionary(string $pdf, int $objectNumber): ?string
    {
        $start = strpos($pdf, $objectNumber.' 0 obj');

        if ($start === false) {
            return null;
        }

        $dictionary = substr($pdf, $start + strlen((string) $objectNumber.' 0 obj'));

        // Never read into the payload: an object with stream data ends its
        // dictionary at `stream`.
        $stream = strpos($dictionary, 'stream');

        if ($stream !== false) {
            $dictionary = substr($dictionary, 0, $stream);
        }

        $end = strpos($dictionary, '>>');

        return $end === false ? $dictionary : substr($dictionary, 0, $end);
    }

    /**
     * A hand-built PDF of the shape dompdf writes, for the picture structures a
     * real card cannot produce.
     *
     * The content stream is FLATE-compressed like the real one: `pdfText()` drops
     * a stream it cannot inflate, so an uncompressed fixture would silently
     * deliver an empty content stream and every draw assertion in it would be
     * vacuous.
     *
     * Object numbers are assigned from 13 upwards, the first number the real card
     * uses. A dictionary that has to point at a sibling — the `/SMask` of a split
     * image — writes `@I2@` for "whatever object `/I2` got", because hardcoding
     * the number would tie the fixture to the insertion order: a wrong reference
     * is not a mask at all, and the mask rule would then be reported as untested
     * rather than as broken.
     *
     * @param  array<string, string>  $objects  resource label => image dictionary
     * @param  list<string>  $drawn  labels the content stream draws
     */
    private function syntheticCardPdf(array $objects, array $drawn): string
    {
        $pdf = "%PDF-1.7\n";
        $resources = '';
        $numberByLabel = [];
        $objectNumber = 13;

        foreach (array_keys($objects) as $label) {
            $numberByLabel[$label] = $objectNumber;
            $objectNumber++;
        }

        foreach ($objects as $label => $dictionary) {
            $number = $numberByLabel[$label];

            $resources .= sprintf("/%s %d 0 R\n", $label, $number);
            $dictionary = str_replace(
                array_map(static fn (string $l): string => '@'.$l.'@', array_keys($numberByLabel)),
                array_map(static fn (int $n): string => (string) $n, $numberByLabel),
                $dictionary,
            );

            $pdf .= sprintf("%d 0 obj\n<< %s >>\nendobj\n", $number, $dictionary);
        }

        $contentStream = '';
        foreach ($drawn as $label) {
            $contentStream .= sprintf("/%s Do\n", $label);
        }

        $deflated = (string) gzcompress($contentStream);

        $pdf .= sprintf(
            "3 0 obj\n<< /Type /Pages /Resources << /XObject <<\n%s>> >> >>\nendobj\n",
            $resources,
        );
        $pdf .= sprintf(
            "7 0 obj\n<< /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream\nendobj\n",
            strlen($deflated),
            $deflated,
        );

        return $pdf."%%EOF\n";
    }

    private function createAccreditation(array $attributes = []): Accreditation
    {
        $category = $this->mandantA->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandantA->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function makeApplication(Accreditation $accreditation, User $user, array $attributes = []): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    private function validLayout(): array
    {
        return [
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
        ];
    }

    private function fullLayout(): array
    {
        return [
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'category', 'x' => 10, 'y' => 22, 'w' => 80, 'h' => 8, 'size' => 11, 'align' => 'left'],
            ['field' => 'event', 'x' => 10, 'y' => 32, 'w' => 80, 'h' => 8, 'size' => 11, 'align' => 'center'],
            ['field' => 'photo', 'x' => 10, 'y' => 44, 'w' => 30, 'h' => 40, 'size' => 11, 'align' => 'left'],
        ];
    }

    /**
     * Create a template via the admin API (mandant A).
     */
    private function createTemplate(array $body = []): BadgeTemplate
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/badge-templates', [
                'name' => $body['name'] ?? 'Presseausweis',
                'layout' => $body['layout'] ?? $this->validLayout(),
                'is_default' => $body['is_default'] ?? false,
            ])
            ->assertStatus(201);

        return BadgeTemplate::findOrFail($response->json('data.id'));
    }

    private function createTemplateRow(): BadgeTemplate
    {
        return BadgeTemplate::create([
            'mandant_id' => $this->mandantA->id,
            'name' => 'Presseausweis',
            'layout' => $this->validLayout(),
            'is_default' => false,
        ]);
    }

    /**
     * A REAL portrait file, not a placeholder string.
     *
     * The bytes used to be the literal `'fake-portrait-bytes'`, and that made
     * this fixture a lie in a way only the PDF showed: dompdf could not decode
     * them, replaced the `<img>` with its built-in broken-image placeholder (an
     * SVG, drawn as vector commands) and embedded NO image XObject for the
     * photo at all. `test_export_pdf_contains_template_field_text_and_photo`
     * nevertheless passed on CI, because its `/I1 Do` assertion was satisfied
     * by the QR code's label — see that test's docblock for the measured
     * reason.
     *
     * The bytes are a literal 8x8 opaque PNG (truecolour, colour type 2) rather
     * than GD output: handing a live `GdImage` into the test process and then
     * letting dompdf rasterise inside the same request rendered an EMPTY
     * document here (both libraries keep process-global GD state, and dompdf's
     * PNG path is GD-backed). A constant decodes identically everywhere and
     * cannot leak that state.
     */
    private function storePortrait(User $user): UserMedia
    {
        $media = UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => "user-media/verband-a/{$user->id}/portrait/portrait-".self::$mediaCount.'.png',
            'mime' => 'image/png',
            'size' => 123,
            'original_name' => 'portrait.png',
        ]);

        Storage::disk('private')->put($media->path, self::portraitPngBytes());

        self::$mediaCount++;

        return $media;
    }

    /**
     * 8x8, solid colour, 98 bytes — a decodable PNG and nothing more.
     */
    private static function portraitPngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAA'
            .'FElEQVQImWOs6DnBgA0wYRUdtBIAao8B3A38XoUAAAAASUVORK5CYII=',
            true,
        );
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandantId,
            'team_id' => $teamId,
        ]);

        return $user;
    }

    /**
     * Decode a base64url token to its raw payload — `strtr()` first, because
     * `base64_decode` in strict mode rejects '-' and '_'.
     */
    private function decode(string $token): string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        $this->assertIsString($decoded, 'premise: a token must decode as base64url — got '.var_export($token, true));

        return $decoded;
    }

    private function encode(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Split a decoded v2 payload into its three claims — the ONE place in this
     * class that knows how to read one. It mirrors `QrTokenService::parse()`:
     * `strpos()` for the application id (`QrTokenService.php:163`) and
     * `strrpos()` for the mandant claim (`:193`) — from the OUTSIDE in.
     *
     * Why outside-in, and why `explode('.', $decoded)` is the wrong tool here:
     * the signature is a RAW HMAC (`hash_hmac('sha256', …, $key, true)`, 32
     * binary bytes) and can therefore contain a '.' byte (0x2E). Measured on the
     * signing key pinned at `phpunit.xml:44`: **11.505 %** of the application ids
     * 1..20000 produce a v2 signature with such a byte, so roughly one id in
     * nine makes `explode()` return four or more segments. Which ids those are
     * depends on the PAIR (application id, mandant id) — see forgedIdPairs().
     * The segment count of a token is therefore an accident of the id, never an
     * invariant of the format, and the id comes from the DB sequence, which is
     * engine-dependent (SQLite restarts per test database, a Postgres sequence
     * keeps counting across rolled-back tests).
     *
     * That is exactly the defect this replaced: `explode()` returned `$parts[1]`
     * = the signature TRUNCATED at its first '.' byte, so the forged payload
     * built from it contained only ONE separator, `parse()` never took the v2
     * branch, and `test_qr_token_tampering_is_rejected()` was green while
     * proving something entirely different from what its comment claimed.
     *
     * The outside-in split is nevertheless unambiguous: the application id
     * precedes the FIRST separator and is all digits, the mandant claim follows
     * the LAST separator and is all digits, so neither claim can hide a dot and
     * the bytes in between are exactly the signature.
     *
     * Its preconditions are asserted HERE rather than in each caller: a payload
     * that is not well-formed v2 fails loudly at the split instead of silently
     * handing out nonsense parts to a "forged token" assertion.
     *
     * @return array{
     *     id: string,
     *     signature: string,
     *     mandantId: string,
     *     signatureHasDot: bool,
     *     naiveSegmentCount: int,
     * }
     */
    private function splitV2Payload(string $decoded): array
    {
        $firstSeparator = strpos($decoded, '.');
        $lastSeparator = strrpos($decoded, '.');

        $this->assertIsInt($firstSeparator, 'premise: a v2 payload separates the id from the signature — got '.strlen($decoded).' raw bytes with no "." at all');
        $this->assertGreaterThan($firstSeparator, $lastSeparator, 'premise: a v2 payload carries the signature BETWEEN two separators');

        $id = substr($decoded, 0, $firstSeparator);
        $signature = substr($decoded, $firstSeparator + 1, $lastSeparator - $firstSeparator - 1);
        $mandantId = substr($decoded, $lastSeparator + 1);

        $this->assertNotSame('', $id, 'premise: the application id claim must not be empty');
        $this->assertTrue(ctype_digit($id), 'premise: the application id claim is all digits and so cannot hide a separator — got '.var_export($id, true));
        $this->assertNotSame('', $signature, 'premise: the signature between the claims must not be empty');
        $this->assertTrue(ctype_digit($mandantId), 'premise: the mandant claim is all digits and so cannot hide a separator — got '.var_export($mandantId, true));

        return [
            'id' => $id,
            'signature' => $signature,
            'mandantId' => $mandantId,
            'signatureHasDot' => str_contains($signature, '.'),
            'naiveSegmentCount' => substr_count($decoded, '.') + 1,
        ];
    }

    /**
     * An UNSAVED application with a caller-chosen id and mandant claim, for the
     * cases that must exercise a SPECIFIC id instead of whatever the database
     * sequence hands out. `QrTokenService::token()` is pure and reads the
     * mandant claim from the preloaded relation (`mandantIdOf()`), so no row is
     * needed and nothing is written.
     */
    private function applicationWithId(int $applicationId, int $mandantId): Application
    {
        $accreditation = new Accreditation;
        $accreditation->forceFill(['mandant_id' => $mandantId]);

        $application = new Application;
        $application->forceFill([
            'id' => $applicationId,
            'accreditation_id' => 0,
            'status' => 'approved',
            'priority' => false,
        ]);
        $application->setRelation('accreditation', $accreditation);

        return $application;
    }
}

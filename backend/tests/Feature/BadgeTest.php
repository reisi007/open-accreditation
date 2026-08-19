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
use Illuminate\Support\Facades\Storage;
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
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

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
            'unknown field' => [
                'layout' => [array_replace($base['layout'][0], ['field' => 'qr'])],
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

    public function test_export_pdf_contains_template_field_text_and_photo(): void
    {
        $event = $this->mandantA->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $this->createAccreditation(['quota' => 5, 'scope' => 'event', 'event_id' => $event->id]);
        $jane = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $this->makeApplication($accreditation, $jane, ['status' => 'approved']);
        $this->storePortrait($jane);

        $template = $this->createTemplate(['name' => 'Presseausweis']);
        $template->update(['layout' => $this->fullLayout()]);

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$accreditation->id.'/badges/export', [
                'format' => 'pdf',
                'template_id' => $template->id,
            ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeaderContains('Content-Disposition', 'attachment');

        $pdf = $response->streamedContent();

        $this->assertStringStartsWith('%PDF-', $pdf);

        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('Jane Doe', $text);
        $this->assertStringContainsString('Presse', $text);
        $this->assertStringContainsString('Finale', $text);

        // The portrait and the QR code are embedded as image XObjects (the
        // content stream draws them with `/I1 Do`, `/I2 Do`).
        $this->assertStringContainsString('/I1 Do', $text);
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
        $this->assertStringStartsWith('https://accreditation.test/verify/', $row[5]);
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
        $this->assertStringStartsWith('https://accreditation.test/verify/', $evilRow[5]);

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
        $this->assertSame($application->id, $service->parse($token));

        // Deterministic: same application → same token, stored on the row.
        $this->assertSame($token, $service->make($application->fresh()));
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'qr_token' => $token]);
    }

    public function test_qr_token_tampering_is_rejected(): void
    {
        $application = $this->makeApplication($this->createAccreditation(['quota' => 5]), User::factory()->create());
        $token = app(QrTokenService::class)->make($application);

        // Flip a character in the MIDDLE of the token (inside the signature's
        // base64, full group) — flipping the LAST char is a no-op for single-digit
        // ids (the final group carries only padding bits, so the decoded
        // signature is unchanged and `parse()` still returns the id).
        $tampered = substr_replace($token, substr($token, 10, 1) === 'a' ? 'b' : 'a', 10, 1);
        $this->assertNull(app(QrTokenService::class)->parse($tampered));

        // Forge a valid signature over a different application id.
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $parts = explode('.', (string) $decoded);
        $forgedId = $parts[0] === '1' ? '2' : '1';
        $forged = rtrim(strtr(base64_encode($forgedId.'.'.$parts[1]), '+/', '-_'), '=');
        $this->assertNull(app(QrTokenService::class)->parse($forged));

        $this->assertNull(app(QrTokenService::class)->parse('garbage'));
        $this->assertNull(app(QrTokenService::class)->parse(''));
    }

    public function test_qr_token_with_wrong_secret_is_rejected(): void
    {
        $application = $this->makeApplication($this->createAccreditation(['quota' => 5]), User::factory()->create());
        $token = app(QrTokenService::class)->make($application);

        $this->assertNull((new QrTokenService('some-other-secret'))->parse($token));

        // The same explicit secret reproduces the token.
        $sameSecret = new QrTokenService((string) config('app.key'));
        $this->assertSame($application->id, $sameSecret->parse($token));
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
        $this->assertSame($application->id, app(QrTokenService::class)->parse($token));
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
        $this->assertSame($first->id, app(QrTokenService::class)->parse((string) $first->fresh()->qr_token));
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
        $this->assertSame($approved->id, app(QrTokenService::class)->parse($computed));

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
        $this->assertSame($application->id, app(QrTokenService::class)->parse($token));

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
        $this->assertSame($approved->id, app(QrTokenService::class)->parse($token));
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

        Storage::disk('private')->put($media->path, 'fake-portrait-bytes');

        self::$mediaCount++;

        return $media;
    }

    /**
     * Extract the (inflated) content stream text of a dompdf PDF so field
     * texts can be asserted. Content streams are FlateDecode-compressed;
     * object dictionaries stay uncompressed and are not part of the output.
     * dompdf encodes text as UTF-16BE (interleaved `\x00` bytes), so null
     * bytes are stripped before returning.
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

            $data = rtrim(substr($pdf, $dataStart, $dataEnd - $dataStart));

            $inflated = @gzuncompress($data);

            if ($inflated === false) {
                $inflated = @gzinflate($data);
            }

            if ($inflated !== false) {
                $text .= $inflated;
            }

            $offset = $dataEnd;
        }

        return str_replace("\x00", '', $text);
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
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P4 / badge-template-editor Etappe 1 — layout schema v2 validation.
 *
 * The whitelist gains `qr`, `team` and `vest_number`; the dedicated `qr`
 * entry (max. one per template) is the only entry allowed to omit
 * `size`/`align`. Cross-field rules: A6 bounds (`x + w ≤ 105`, `y + h ≤ 148`,
 * derived from the render service constants), minimum box sizes (text 5 × 3 mm,
 * photo/qr 10 × 10 mm) and the historical size/align requirement for data
 * fields. Legacy layouts stay valid unchanged.
 *
 * Freely placed images (user decision 2026-08-26, features/badge-template-
 * editor.md): the `image` entry carries a required source union —
 * `{kind: brand, ref: logo|header}` or `{kind: upload, image_id: int}` — plus
 * the optional `fit` switch (contain/cover). Like `qr` it may omit the
 * meaningless `size`/`align`, uses the box minimum (10 × 10 mm) and may occur
 * multiple times. Existence/mandant-scoping of `image_id` lands with the
 * badge_images infrastructure slice; here the union structure is validated.
 */
class BadgeTemplateSchemaV2Test extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Accepted layouts
     | ------------------------------------------------------------------- */

    public function test_legacy_layout_without_qr_entry_stays_valid(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ['field' => 'category', 'x' => 10, 'y' => 22, 'w' => 80, 'h' => 8, 'size' => 11, 'align' => 'center'],
            ['field' => 'photo', 'x' => 10, 'y' => 44, 'w' => 30, 'h' => 40, 'size' => 11, 'align' => 'left'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.layout.0.field', 'name')
            ->assertJsonPath('data.layout.1.align', 'center');
    }

    public function test_team_and_vest_number_fields_are_accepted_and_persisted(): void
    {
        $response = $this->postTemplate([
            ['field' => 'team', 'x' => 10, 'y' => 12, 'w' => 60, 'h' => 6, 'size' => 10, 'align' => 'left'],
            ['field' => 'vest_number', 'x' => 10, 'y' => 20, 'w' => 30, 'h' => 5, 'size' => 9, 'align' => 'left'],
        ])->assertStatus(201);

        $templateId = $response->json('data.id');

        // Roundtrip: the new field types survive storage and serialization.
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/badge-templates')
            ->assertOk()
            ->assertJsonPath('data.0.layout.0.field', 'team')
            ->assertJsonPath('data.0.layout.1.field', 'vest_number')
            ->assertJsonPath('data.0.layout.1.size', 9);

        $this->assertDatabaseHas('badge_templates', ['id' => $templateId]);
    }

    public function test_qr_entry_with_coordinates_is_accepted(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            // Spec example shape: qr carries coordinates only — no size/align.
            ['field' => 'qr', 'x' => 78, 'y' => 121, 'w' => 22, 'h' => 22],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.layout.1.field', 'qr')
            ->assertJsonPath('data.layout.1.x', 78)
            ->assertJsonPath('data.layout.1.w', 22);
    }

    public function test_qr_entry_may_carry_meaningless_size_and_align(): void
    {
        $this->postTemplate([
            ['field' => 'qr', 'x' => 70, 'y' => 120, 'w' => 25, 'h' => 25, 'size' => 12, 'align' => 'center'],
        ])->assertStatus(201);
    }

    public function test_image_entries_are_accepted_and_persisted(): void
    {
        $response = $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            // Brand source without fit (defaults to contain renderer-side) and
            // without the meaningless size/align — spec example shapes.
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
            [
                'field' => 'image',
                'x' => 40,
                'y' => 130,
                'w' => 15,
                'h' => 12,
                'src' => ['kind' => 'upload', 'image_id' => 17],
                'fit' => 'cover',
            ],
        ])->assertStatus(201);

        // Roundtrip: coordinates + source union + fit survive storage and
        // serialization.
        $templateId = $response->json('data.id');
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/badge-templates')
            ->assertOk()
            ->assertJsonPath('data.0.layout.1.field', 'image')
            ->assertJsonPath('data.0.layout.1.src.kind', 'brand')
            ->assertJsonPath('data.0.layout.1.src.ref', 'logo')
            ->assertJsonPath('data.0.layout.2.field', 'image')
            ->assertJsonPath('data.0.layout.2.src.kind', 'upload')
            ->assertJsonPath('data.0.layout.2.src.image_id', 17)
            ->assertJsonPath('data.0.layout.2.fit', 'cover');

        $this->assertDatabaseHas('badge_templates', ['id' => $templateId]);
    }

    public function test_image_entry_with_header_brand_ref_is_accepted(): void
    {
        $this->postTemplate([
            ['field' => 'image', 'x' => 0, 'y' => 0, 'w' => 105, 'h' => 20, 'src' => ['kind' => 'brand', 'ref' => 'header'], 'fit' => 'contain'],
        ])->assertStatus(201);
    }

    public function test_exact_a6_boundaries_are_accepted(): void
    {
        $this->postTemplate([
            // x + w = 105 exactly (right edge) and y + h = 148 exactly
            // (bottom edge) — both are ON the card, not beyond it.
            ['field' => 'name', 'x' => 25, 'y' => 0, 'w' => 80, 'h' => 8, 'size' => 14, 'align' => 'left'],
            ['field' => 'category', 'x' => 0, 'y' => 140, 'w' => 20, 'h' => 8, 'size' => 10, 'align' => 'left'],
        ])->assertStatus(201);
    }

    public function test_exact_minimum_sizes_are_accepted(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 3, 'size' => 6, 'align' => 'left'],
            ['field' => 'photo', 'x' => 0, 'y' => 10, 'w' => 10, 'h' => 10, 'size' => 8, 'align' => 'left'],
            ['field' => 'qr', 'x' => 80, 'y' => 120, 'w' => 10, 'h' => 10],
        ])->assertStatus(201);
    }

    /* ---------------------------------------------------------------------
     | Rejected layouts
     | ------------------------------------------------------------------- */

    public function test_unknown_field_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'sponsor', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 12, 'align' => 'left'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layout.0.field');
    }

    public function test_second_qr_entry_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 12, 'align' => 'left'],
            ['field' => 'qr', 'x' => 70, 'y' => 100, 'w' => 20, 'h' => 20],
            ['field' => 'qr', 'x' => 0, 'y' => 0, 'w' => 20, 'h' => 20],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layout.2.field');

        $this->assertDatabaseCount('badge_templates', 0);
    }

    public function test_field_beyond_the_right_edge_is_rejected(): void
    {
        // x + w = 106 > 105.
        $this->postTemplate([
            ['field' => 'name', 'x' => 26, 'y' => 10, 'w' => 80, 'h' => 8, 'size' => 12, 'align' => 'left'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layout.0.w');
    }

    public function test_field_beyond_the_bottom_edge_is_rejected(): void
    {
        // y + h = 149 > 148.
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 140, 'w' => 40, 'h' => 9, 'size' => 12, 'align' => 'left'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layout.0.h');
    }

    public function test_text_field_below_minimum_size_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 4.9, 'h' => 8, 'size' => 12, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.w');

        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 2.9, 'size' => 12, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.h');
    }

    public function test_photo_and_qr_below_minimum_size_are_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'photo', 'x' => 10, 'y' => 10, 'w' => 9.9, 'h' => 40, 'size' => 12, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.w');

        $this->postTemplate([
            ['field' => 'photo', 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 9, 'size' => 12, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.h');

        $this->postTemplate([
            ['field' => 'qr', 'x' => 10, 'y' => 10, 'w' => 9, 'h' => 20],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.w');
    }

    public function test_image_below_minimum_size_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'image', 'x' => 0, 'y' => 0, 'w' => 9.9, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.w');

        $this->postTemplate([
            ['field' => 'image', 'x' => 0, 'y' => 0, 'w' => 20, 'h' => 9.9, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.h');
    }

    public function test_image_beyond_the_card_bounds_is_rejected(): void
    {
        // x + w = 110 > 105.
        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 0, 'w' => 105, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.w');

        // y + h = 150 > 148.
        $this->postTemplate([
            ['field' => 'image', 'x' => 0, 'y' => 140, 'w' => 20, 'h' => 10, 'src' => ['kind' => 'brand', 'ref' => 'logo']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.h');
    }

    public function test_image_without_source_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.src');

        $this->assertDatabaseCount('badge_templates', 0);
    }

    public function test_image_with_invalid_brand_ref_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'brand', 'ref' => 'footer']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.src');
    }

    public function test_image_with_unknown_kind_is_rejected(): void
    {
        // Client-controlled paths/URLs are never accepted — only the enum
        // union (brand ref / upload id) is addressable.
        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'url', 'url' => 'https://evil.example/x.png']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.src');
    }

    public function test_image_upload_source_requires_positive_integer_id(): void
    {
        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.src');

        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.src');

        $this->postTemplate([
            ['field' => 'image', 'x' => 5, 'y' => 130, 'w' => 20, 'h' => 12, 'src' => ['kind' => 'upload', 'image_id' => 'abc']],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.src');
    }

    public function test_image_with_invalid_fit_is_rejected(): void
    {
        $this->postTemplate([
            [
                'field' => 'image',
                'x' => 5,
                'y' => 130,
                'w' => 20,
                'h' => 12,
                'src' => ['kind' => 'brand', 'ref' => 'logo'],
                'fit' => 'stretch',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.fit');
    }

    public function test_font_size_above_72_is_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 73, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.size');
    }

    public function test_data_field_requires_size_and_align(): void
    {
        // Historical contract: a data field without `size` (absent key) fails.
        $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.size');

        $this->postTemplate([
            ['field' => 'vest_number', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 12],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.align');
    }

    public function test_negative_coordinates_stay_rejected(): void
    {
        $this->postTemplate([
            ['field' => 'name', 'x' => -0.5, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 12, 'align' => 'left'],
        ])->assertStatus(422)->assertJsonValidationErrors('layout.0.x');
    }

    public function test_update_enforces_the_same_schema_v2_rules(): void
    {
        $templateId = $this->postTemplate([
            ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 12, 'align' => 'left'],
        ])->assertStatus(201)->json('data.id');

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/badge-templates/'.$templateId, [
                'name' => 'Presseausweis',
                'layout' => [
                    ['field' => 'qr', 'x' => 90, 'y' => 130, 'w' => 30, 'h' => 30],
                    ['field' => 'qr', 'x' => 0, 'y' => 0, 'w' => 20, 'h' => 20],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('layout.1.field');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function postTemplate(array $layout): TestResponse
    {
        return $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/badge-templates', [
                'name' => 'Presseausweis',
                'layout' => $layout,
            ]);
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandantId,
            'team_id' => null,
        ]);

        return $user;
    }
}

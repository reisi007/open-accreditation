<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BadgeTemplate;
use App\Models\Category;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W3 — fachliches `event_types.presets` schema (`v = 1`) through the admin API.
 *
 * Every violation answers 422 on the exact leaf key (`presets.<path>`); the
 * structural envelope (object root, depth, scalar leaves, size, UTF-8) is
 * covered by {@see AdminEventTypeTest} and re-checked here for the two
 * boundary cases named in the W3 task (too deep / too large).
 *
 * Tenant safety: `badge_template_id` and `defaults.category_slugs` are
 * resolved against the current mandant — a foreign reference is a hard 422.
 */
class EventTypePresetSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);
        $this->mandantB->domains()->create(['hostname' => 'verband-b.test']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Accepted
     | ------------------------------------------------------------------- */

    public function test_accepts_full_valid_preset(): void
    {
        $badge = $this->makeBadgeTemplate($this->mandantA, 'Bundesliga-Ausweis');
        $this->makeCategory($this->mandantA, 'presse');
        $this->makeCategory($this->mandantA, 'fotograf');

        $presets = [
            'v' => 1,
            'badge_template_id' => $badge->id,
            'consent_pdf' => ['enabled' => true, 'template' => 'standard'],
            'accreditation_pdf' => ['layout' => 'badge'],
            'defaults' => [
                'quota' => 50,
                'deadline_offset_days' => 14,
                'auto_approve' => false,
                'category_slugs' => ['presse', 'fotograf'],
            ],
        ];

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => $presets,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.presets.v', 1)
            ->assertJsonPath('data.presets.badge_template_id', $badge->id)
            ->assertJsonPath('data.presets.consent_pdf.enabled', true)
            ->assertJsonPath('data.presets.accreditation_pdf.layout', 'badge')
            ->assertJsonPath('data.presets.defaults.quota', 50)
            ->assertJsonPath('data.presets.defaults.category_slugs.0', 'presse');

        $type = EventType::query()->findOrFail($response->json('data.id'));
        $this->assertSame($presets, $type->presets);
    }

    public function test_accepts_minimal_version_only_preset(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'cup',
                'name' => 'Cup',
                'presets' => ['v' => 1],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.presets.v', 1);
    }

    public function test_accepts_empty_preset_envelope(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'cup',
                'name' => 'Cup',
                'presets' => [],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.presets', []);
    }

    /* ---------------------------------------------------------------------
     | Version
     | ------------------------------------------------------------------- */

    public function test_rejects_missing_version(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['bagde_template_id' => 1],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.v');

        $this->assertDatabaseMissing('event_types', ['slug' => 'bundesliga']);
    }

    public function test_rejects_unsupported_version(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 2],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.v');
    }

    public function test_rejects_non_integer_version(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => '1'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.v');
    }

    /* ---------------------------------------------------------------------
     | Unknown keys (strict whitelist)
     | ------------------------------------------------------------------- */

    public function test_rejects_unknown_top_level_key(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'fancy_new_thing' => true],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.fancy_new_thing');
    }

    public function test_rejects_unknown_nested_key(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => [
                    'v' => 1,
                    'consent_pdf' => ['enabled' => true, 'color' => 'red'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.consent_pdf.color');
    }

    /* ---------------------------------------------------------------------
     | Wrong scalar types
     | ------------------------------------------------------------------- */

    public function test_rejects_wrong_types_per_section(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => [
                    'v' => 1,
                    'badge_template_id' => '12',
                    'consent_pdf' => ['enabled' => 'yes'],
                    'accreditation_pdf' => ['layout' => 3],
                    'defaults' => ['quota' => '50', 'deadline_offset_days' => '14', 'auto_approve' => 'no'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'presets.badge_template_id',
                'presets.consent_pdf.enabled',
                'presets.accreditation_pdf.layout',
                'presets.defaults.quota',
                'presets.defaults.deadline_offset_days',
                'presets.defaults.auto_approve',
            ]);

        $response->assertJsonMissingValidationErrors(['presets.v']);
    }

    /* ---------------------------------------------------------------------
     | Sections: required content / enums
     | ------------------------------------------------------------------- */

    public function test_rejects_empty_section_objects(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => [
                    'v' => 1,
                    'consent_pdf' => [],
                    'accreditation_pdf' => [],
                    'defaults' => [],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'presets.consent_pdf',
                'presets.accreditation_pdf',
                'presets.defaults',
            ]);
    }

    public function test_rejects_missing_enabled_in_consent_pdf(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'consent_pdf' => ['template' => 'standard']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.consent_pdf.enabled');
    }

    public function test_rejects_unknown_consent_template(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'consent_pdf' => ['enabled' => true, 'template' => 'custom']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.consent_pdf.template');
    }

    public function test_rejects_unknown_accreditation_layout(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'accreditation_pdf' => ['layout' => 'poster']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.accreditation_pdf.layout');
    }

    /* ---------------------------------------------------------------------
     | Quota / deadline ranges
     | ------------------------------------------------------------------- */

    public function test_rejects_negative_quota(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['quota' => -1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.quota');
    }

    public function test_rejects_quota_above_maximum(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['quota' => 100001]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.quota');
    }

    public function test_rejects_deadline_offset_out_of_range(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['deadline_offset_days' => 0]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.deadline_offset_days');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['deadline_offset_days' => 366]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.deadline_offset_days');
    }

    public function test_accepts_deadline_offset_boundaries(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['deadline_offset_days' => 1]],
            ])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga-zwei',
                'name' => 'Bundesliga 2',
                'presets' => ['v' => 1, 'defaults' => ['deadline_offset_days' => 365]],
            ])
            ->assertStatus(201);
    }

    /* ---------------------------------------------------------------------
     | Badge template reference (tenant scoped)
     | ------------------------------------------------------------------- */

    public function test_rejects_missing_badge_template(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'badge_template_id' => 999999],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.badge_template_id');
    }

    public function test_rejects_foreign_mandant_badge_template(): void
    {
        $foreign = $this->makeBadgeTemplate($this->mandantB, 'Fremder Ausweis');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'badge_template_id' => $foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.badge_template_id');

        $this->assertDatabaseMissing('event_types', ['slug' => 'bundesliga']);
    }

    /* ---------------------------------------------------------------------
     | Category slugs (tenant scoped)
     | ------------------------------------------------------------------- */

    public function test_rejects_unknown_category_slug(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['category_slugs' => ['presse']]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.category_slugs.0');
    }

    public function test_rejects_foreign_mandant_category_slug(): void
    {
        $this->makeCategory($this->mandantB, 'presse');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['category_slugs' => ['presse']]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.category_slugs.0');
    }

    public function test_rejects_duplicate_category_slugs(): void
    {
        $this->makeCategory($this->mandantA, 'presse');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['category_slugs' => ['presse', 'presse']]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.category_slugs.1');
    }

    public function test_rejects_invalid_category_slug_format(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['category_slugs' => ['Not Valid!']]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.category_slugs.0');
    }

    public function test_rejects_non_list_category_slugs(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'defaults' => ['category_slugs' => ['presse' => true]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.category_slugs');
    }

    /* ---------------------------------------------------------------------
     | Structural envelope boundaries (too deep / too large)
     | ------------------------------------------------------------------- */

    public function test_rejects_too_deep_preset_envelope(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['x' => ['a' => ['b' => ['c' => ['d' => 1]]]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets');
    }

    public function test_rejects_oversized_preset_envelope(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['v' => 1, 'blob' => str_repeat('a', 20000)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets');
    }

    public function test_rejects_non_object_preset_envelope(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['a', 'b'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets');
    }

    /* ---------------------------------------------------------------------
     | Update path uses the same schema
     | ------------------------------------------------------------------- */

    public function test_update_rejects_invalid_preset_schema(): void
    {
        $type = EventType::query()->create([
            'mandant_id' => $this->mandantA->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/event-types/'.$type->id, [
                'presets' => ['v' => 1, 'defaults' => ['quota' => -5]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets.defaults.quota');

        $this->assertNull($type->fresh()->presets);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function makeBadgeTemplate(Mandant $mandant, string $name): BadgeTemplate
    {
        return BadgeTemplate::query()->create([
            'mandant_id' => $mandant->id,
            'name' => $name,
            'layout' => [
                ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 8, 'size' => 12, 'align' => 'left'],
            ],
            'is_default' => true,
        ]);
    }

    private function makeCategory(Mandant $mandant, string $slug): Category
    {
        return Category::query()->create([
            'mandant_id' => $mandant->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }
}

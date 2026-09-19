<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\MediaPathService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W2 admin API — mandant event types (CRUD, logo media, `presets` envelope)
 * and the `events.event_type_id` assignment.
 *
 * Event types are mandant-level: super_admin and mandant_admin manage them,
 * a team_admin may only read. Rows/logo files of a foreign mandant are 404
 * (tenant-guarded route model binding + MandantContext-derived queries). The
 * slug is unique per mandant — two Verbände may use the same slug. Logo files
 * land on the public `media` disk under the W1 layout
 * (`<host>/event-types/<slug>/logo.<ext>`) and are delivered auth-gated.
 */
class AdminEventTypeTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Storage::fake(MediaPathService::DISK);

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
     | CRUD + authorization
     | ------------------------------------------------------------------- */

    public function test_event_type_endpoints_require_authentication(): void
    {
        $type = $this->makeType($this->mandantA);

        $this->getJson('/api/admin/event-types')->assertStatus(401);
        $this->postJson('/api/admin/event-types', [])->assertStatus(401);
        $this->putJson('/api/admin/event-types/'.$type->id, [])->assertStatus(401);
        $this->deleteJson('/api/admin/event-types/'.$type->id)->assertStatus(401);

        $this->getJson('/api/admin/event-types/'.$type->id.'/logo')->assertStatus(401);
        $this->post('/api/admin/event-types/'.$type->id.'/logo', [])->assertStatus(401);
        $this->deleteJson('/api/admin/event-types/'.$type->id.'/logo')->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/event-types')
                ->assertStatus(403, "expected 403 for {$role->value} on event-types index");

            $this->actingAsApi($user)->postJson('/api/admin/event-types', ['slug' => 'x', 'name' => 'X'])
                ->assertStatus(403, "expected 403 for {$role->value} on event-types store");
        }
    }

    public function test_super_admin_can_create_event_type(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'active' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'bundesliga')
            ->assertJsonPath('data.name', 'Bundesliga')
            ->assertJsonPath('data.mandant_id', $this->mandantA->id)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.presets', null)
            ->assertJsonPath('data.logo_url', null);

        $this->assertDatabaseHas('event_types', [
            'mandant_id' => $this->mandantA->id,
            'slug' => 'bundesliga',
            'active' => true,
        ]);
    }

    public function test_client_supplied_mandant_id_is_ignored(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'mandant_id' => $this->mandantB->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.mandant_id', $this->mandantA->id);

        // The mandant is always derived from MandantContext, never the payload.
        $this->assertDatabaseHas('event_types', [
            'mandant_id' => $this->mandantA->id,
            'slug' => 'bundesliga',
        ]);
        $this->assertDatabaseMissing('event_types', ['mandant_id' => $this->mandantB->id]);
    }

    public function test_mandant_admin_can_create_event_type(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/event-types', ['slug' => 'cup', 'name' => 'Cup'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'cup');
    }

    public function test_team_admin_can_read_but_not_write(): void
    {
        $team = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $team->id);
        $type = $this->makeType($this->mandantA);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/event-types')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/event-types', ['slug' => 'hack', 'name' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/event-types/'.$type->id, ['name' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/event-types/'.$type->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('event_types', ['id' => $type->id, 'name' => 'Bundesliga']);
    }

    public function test_team_admin_cannot_upload_or_delete_logo(): void
    {
        $team = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $team->id);
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($teamAdmin)
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/event-types/'.$type->id.'/logo')
            ->assertStatus(403);

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/event-types/bundesliga/logo.png');
        $this->assertNull($type->fresh()->logo_path);
    }

    public function test_slug_must_be_unique_per_mandant(): void
    {
        $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', ['slug' => 'bundesliga', 'name' => 'Doppelt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_same_slug_is_allowed_in_another_mandant(): void
    {
        $this->makeType($this->mandantA, ['slug' => 'bundesliga', 'name' => 'Bundesliga A']);

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', ['slug' => 'bundesliga', 'name' => 'Bundesliga B'])
            ->assertStatus(201)
            ->assertJsonPath('data.mandant_id', $this->mandantB->id);

        $this->assertDatabaseHas('event_types', [
            'mandant_id' => $this->mandantB->id,
            'slug' => 'bundesliga',
        ]);
    }

    public function test_index_only_returns_current_mandant_types(): void
    {
        $this->makeType($this->mandantA, ['slug' => 'bundesliga', 'name' => 'Bundesliga A']);
        $this->makeType($this->mandantB, ['slug' => 'bundesliga', 'name' => 'Bundesliga B']);
        $this->makeType($this->mandantB, ['slug' => 'cup', 'name' => 'Cup B']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/event-types')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'bundesliga')
            ->assertJsonPath('data.0.name', 'Bundesliga A');

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/event-types')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_foreign_mandant_type_is_not_reachable(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/event-types/'.$type->id, ['name' => 'Hack'])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/event-types/'.$type->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('event_types', ['id' => $type->id, 'name' => 'Bundesliga']);
    }

    public function test_super_admin_can_update_partially(): void
    {
        $type = $this->makeType($this->mandantA, [
            'slug' => 'bundesliga',
            'name' => 'Alt',
            'presets' => ['badge_template' => 'presse'],
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/event-types/'.$type->id, ['name' => 'Neu'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu')
            ->assertJsonPath('data.slug', 'bundesliga')
            ->assertJsonPath('data.presets.badge_template', 'presse');

        $this->assertDatabaseHas('event_types', ['id' => $type->id, 'name' => 'Neu']);
    }

    public function test_super_admin_can_delete_event_type(): void
    {
        $type = $this->makeType($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/event-types/'.$type->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('event_types', ['id' => $type->id]);
    }

    public function test_active_filter(): void
    {
        $this->makeType($this->mandantA, ['slug' => 'aktiv', 'name' => 'Aktiv', 'active' => true]);
        $this->makeType($this->mandantA, ['slug' => 'inaktiv', 'name' => 'Inaktiv', 'active' => false]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/event-types?active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'aktiv');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/event-types?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'inaktiv');
    }

    public function test_slug_real_regex_and_name_required(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', ['slug' => 'Not Valid!', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', ['slug' => 'valid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /* ---------------------------------------------------------------------
     | presets envelope (structural only, W3 adds the fachliches schema)
     | ------------------------------------------------------------------- */

    public function test_presets_roundtrip(): void
    {
        $presets = [
            'v' => 1,
            'defaults' => ['quota' => 10, 'auto_approve' => false, 'deadline_offset_days' => 14],
        ];

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => $presets,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.presets.v', 1)
            ->assertJsonPath('data.presets.defaults.quota', 10)
            ->assertJsonPath('data.presets.defaults.deadline_offset_days', 14);

        $type = EventType::query()->findOrFail($response->json('data.id'));
        $this->assertSame($presets, $type->presets);
    }

    public function test_presets_reject_non_object_root(): void
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

    public function test_presets_reject_excessive_depth(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]];

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => $deep,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets');
    }

    public function test_presets_reject_oversized_payload(): void
    {
        $big = ['blob' => str_repeat('a', 20000)];

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => $big,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets');
    }

    public function test_presets_reject_invalid_utf8_with_422_not_500(): void
    {
        // Form-encoded requests can smuggle raw invalid bytes past the JSON
        // decoder (e.g. `presets[blob]=\xFF`). Before the JSON_THROW_ON_ERROR
        // guard `json_encode()` returned `false`, `(string) false` slipped
        // through the size check and the Eloquent JSON cast raised a
        // JsonEncodingException → HTTP 500. It must be a validation error.
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'presets' => ['blob' => "\xFF"],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presets');

        $this->assertDatabaseMissing('event_types', ['slug' => 'bundesliga']);
    }

    public function test_presets_can_be_cleared_with_null(): void
    {
        $type = $this->makeType($this->mandantA, ['presets' => ['a' => 1]]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/event-types/'.$type->id, ['presets' => null])
            ->assertOk()
            ->assertJsonPath('data.presets', null);

        $this->assertNull($type->fresh()->presets);
    }

    /* ---------------------------------------------------------------------
     | Logo media
     | ------------------------------------------------------------------- */

    public function test_upload_logo_stores_on_media_disk_in_domain_layout(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_url', route('api.admin.event-types.logo', ['eventType' => $type->id]));

        $expected = 'verband-a.test/event-types/bundesliga/logo.png';
        Storage::disk(MediaPathService::DISK)->assertExists($expected);
        $this->assertSame($expected, $type->fresh()->logo_path);
    }

    public function test_upload_logo_rejects_invalid_file_type(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->create('logo.txt', 100, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/event-types/bundesliga/logo.txt');
    }

    public function test_upload_logo_rejects_oversized_file(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('huge.png')->size(3000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_logo_rejects_oversized_dimensions(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('big.png', 2001, 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/event-types/bundesliga/logo.png');
    }

    public function test_replacing_logo_deletes_the_previous_file(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo-a.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo-b.jpg'),
            ])
            ->assertOk();

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/event-types/bundesliga/logo.png');
        Storage::disk(MediaPathService::DISK)
            ->assertExists('verband-a.test/event-types/bundesliga/logo.jpg');

        $this->assertSame(
            'verband-a.test/event-types/bundesliga/logo.jpg',
            $type->fresh()->logo_path,
        );
    }

    public function test_delete_logo_removes_file_and_path(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/event-types/'.$type->id.'/logo')
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/event-types/bundesliga/logo.png');
        $this->assertNull($type->fresh()->logo_path);
    }

    public function test_deleting_event_type_removes_logo_file(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/event-types/'.$type->id)
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)
            ->assertMissing('verband-a.test/event-types/bundesliga/logo.png');
    }

    public function test_logo_delivery_is_auth_gated_with_correct_content_type(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/event-types/'.$type->id.'/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeaderContains('Content-Disposition', 'inline');
    }

    public function test_logo_delivery_returns_404_without_file(): void
    {
        $type = $this->makeType($this->mandantA);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/event-types/'.$type->id.'/logo')
            ->assertStatus(404);
    }

    public function test_logo_upload_of_foreign_mandant_type_is_404(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(404);

        $this->assertNull($type->fresh()->logo_path);
    }

    public function test_logo_upload_uses_host_neutral_path_without_domain(): void
    {
        $mandantC = Mandant::factory()->create(['slug' => 'verband-c', 'name' => 'Verband C']);
        MandantContext::set($mandantC);
        $type = $this->makeType($mandantC, ['slug' => 'cup']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/event-types/'.$type->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        Storage::disk(MediaPathService::DISK)->assertExists('event-types/cup/logo.png');
        $this->assertSame('event-types/cup/logo.png', $type->fresh()->logo_path);
    }

    /* ---------------------------------------------------------------------
     | events.event_type_id
     | ------------------------------------------------------------------- */

    public function test_event_can_be_assigned_an_event_type_via_api(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Spieltag 1',
                'event_type_id' => $type->id,
                'competition' => 'Bundesliga',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.event_type_id', $type->id)
            ->assertJsonPath('data.competition', 'Bundesliga');

        $event = Event::query()->where('title', 'Spieltag 1')->firstOrFail();

        $this->assertSame($type->id, $event->event_type_id);
        $this->assertTrue($event->eventType->is($type));
    }

    public function test_event_creation_rejects_foreign_mandant_event_type(): void
    {
        $foreign = $this->makeType($this->mandantB, ['slug' => 'fremd']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/events', [
                'title' => 'Hack',
                'event_type_id' => $foreign->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('events', ['title' => 'Hack']);
    }

    public function test_deleting_a_type_nulls_the_event_reference_and_keeps_competition(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);

        $event = $this->mandantA->events()->create([
            'title' => 'Finale',
            'event_type_id' => $type->id,
            'competition' => 'Pokal',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/event-types/'.$type->id)
            ->assertStatus(204);

        $event->refresh();

        $this->assertNull($event->event_type_id);
        $this->assertSame('Pokal', $event->competition);
    }

    public function test_event_update_can_clear_or_set_event_type(): void
    {
        $type = $this->makeType($this->mandantA, ['slug' => 'bundesliga']);
        $event = $this->mandantA->events()->create(['title' => 'Finale']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['event_type_id' => $type->id])
            ->assertOk()
            ->assertJsonPath('data.event_type_id', $type->id);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/events/'.$event->id, ['event_type_id' => null])
            ->assertOk()
            ->assertJsonPath('data.event_type_id', null);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeType(Mandant $mandant, array $attributes = []): EventType
    {
        return EventType::query()->create([
            'mandant_id' => $mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
            ...$attributes,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function mandantAdmin(): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id);
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

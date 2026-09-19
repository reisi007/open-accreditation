<?php

namespace Tests\Feature;

use App\Enums\UserRole;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P8b Mandant self-service logo/header management — `can:mandant.media.manage`.
 *
 * A mandant_admin manages the logo/header of the OWN mandant (resolved from
 * MandantContext, never from a request parameter → no IDOR). super_admin keeps
 * full control. team_admin, user and guests are rejected; a mandant_admin on a
 * foreign mandant context is denied by the gate.
 */
class MandantMediaSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');
        Storage::fake(MediaPathService::DISK);

        $this->mandantA = Mandant::factory()->create([
            'slug' => 'verband-a',
            'name' => 'Verband A',
        ]);
        $this->mandantB = Mandant::factory()->create([
            'slug' => 'verband-b',
            'name' => 'Verband B',
        ]);

        // Re-read the rows so the container instance is not flagged as
        // "recently created" — otherwise the MandantResource response would
        // carry 201 (ResourceResponse) instead of the contract's 200, an
        // artifact of the factory instance that production never sees.
        $this->mandantA = Mandant::query()->findOrFail($this->mandantA->id);
        $this->mandantB = Mandant::query()->findOrFail($this->mandantB->id);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public static function selfServiceRoutesProvider(): array
    {
        return [
            'logo show' => ['get', '/api/mandant/logo'],
            'logo store' => ['post', '/api/mandant/logo'],
            'logo destroy' => ['delete', '/api/mandant/logo'],
            'header show' => ['get', '/api/mandant/header'],
            'header store' => ['post', '/api/mandant/header'],
            'header destroy' => ['delete', '/api/mandant/header'],
        ];
    }

    /* ---------------------------------------------------------------------
     | Mandant admin happy paths
     | ------------------------------------------------------------------- */

    public function test_mandant_admin_can_upload_logo(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.logo_url', route('api.admin.mandants.logo', ['mandant' => $this->mandantA->id]));

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => '_tenants/'.$this->mandantA->id.'/logo.png',
        ]);
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/logo.png');
    }

    public function test_mandant_admin_can_upload_header(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/header', [
                'file' => UploadedFile::fake()->image('header.png'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.header_url', route('api.admin.mandants.header', ['mandant' => $this->mandantA->id]));

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'header_path' => '_tenants/'.$this->mandantA->id.'/header.png',
        ]);
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/header.png');
    }

    public function test_mandant_admin_can_replace_logo(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', [
                'file' => UploadedFile::fake()->image('logo-a.png'),
            ])
            ->assertStatus(200);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', [
                'file' => UploadedFile::fake()->image('logo-b.jpg'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.logo_url', route('api.admin.mandants.logo', ['mandant' => $this->mandantA->id]));

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/logo.jpg');
        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => '_tenants/'.$this->mandantA->id.'/logo.jpg',
        ]);
    }

    public function test_mandant_admin_can_delete_logo_and_header(): void
    {
        $admin = $this->mandantAdmin($this->mandantA);

        $this->actingAsApi($admin)
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('logo.png')])
            ->assertStatus(200);
        $this->actingAsApi($admin)
            ->post('/api/mandant/header', ['file' => UploadedFile::fake()->image('header.png')])
            ->assertStatus(200);

        $this->actingAsApi($admin)->deleteJson('/api/mandant/logo')->assertStatus(204);
        $this->actingAsApi($admin)->deleteJson('/api/mandant/header')->assertStatus(204);

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => null,
            'header_path' => null,
        ]);
        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/header.png');

        // After deletion the next upload response exposes the cleared urls.
        $this->actingAsApi($admin)
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('neu.png')])
            ->assertStatus(200)
            ->assertJsonPath('data.logo_url', route('api.admin.mandants.logo', ['mandant' => $this->mandantA->id]));
    }

    public function test_upload_uses_domain_layout_when_mandant_has_a_domain(): void
    {
        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('logo.png')])
            ->assertStatus(200);

        $this->assertSame('verband-a.test/logo.png', $this->mandantA->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
    }

    public function test_first_domain_wins_even_when_a_later_alias_exists(): void
    {
        // W6 host convention: the first domain (orderBy id) is the path prefix;
        // a later alias never changes where a mandant's media already lives.
        $this->mandantA->domains()->create(['hostname' => 'primary.test']);
        $this->mandantA->domains()->create(['hostname' => 'alias.test']);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('logo.png')])
            ->assertStatus(200);

        $this->assertSame('primary.test/logo.png', $this->mandantA->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertExists('primary.test/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('alias.test/logo.png');
    }

    public function test_legacy_private_logo_stays_readable_and_is_removed_on_delete(): void
    {
        // A file written before W6 (legacy `private` layout) must stay readable
        // and deleting must clean it up on the legacy disk.
        $png = UploadedFile::fake()->image('legacy.png');
        Storage::disk('private')->put('mandants/verband-a/logo.png', (string) file_get_contents($png->getRealPath()));
        $this->mandantA->update(['logo_path' => 'mandants/verband-a/logo.png']);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/mandant/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->deleteJson('/api/mandant/logo')
            ->assertStatus(204);

        Storage::disk('private')->assertMissing('mandants/verband-a/logo.png');
        $this->assertNull($this->mandantA->fresh()->logo_path);
    }

    public function test_logo_delivery_streams_image_png_inline(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('logo.png')])
            ->assertStatus(200);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/mandant/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeaderContains('Content-Disposition', 'inline');
    }

    public function test_delivery_returns_404_message_without_file(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/mandant/logo')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Kein Bild hinterlegt.');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/mandant/header')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Kein Bild hinterlegt.');
    }

    /* ---------------------------------------------------------------------
     | Access control
     | ------------------------------------------------------------------- */

    #[DataProvider('selfServiceRoutesProvider')]
    public function test_team_admin_is_denied_on_all_self_service_routes(string $method, string $uri): void
    {
        $this->actingAsApi($this->teamAdmin($this->mandantA))
            ->call($method, $uri)
            ->assertStatus(403, "expected 403 for team_admin on {$method} {$uri}");
    }

    #[DataProvider('selfServiceRoutesProvider')]
    public function test_plain_user_is_denied_on_all_self_service_routes(string $method, string $uri): void
    {
        $this->actingAsApi($this->plainUser($this->mandantA))
            ->call($method, $uri)
            ->assertStatus(403, "expected 403 for user on {$method} {$uri}");
    }

    #[DataProvider('selfServiceRoutesProvider')]
    public function test_guest_gets_401_on_all_self_service_routes(string $method, string $uri): void
    {
        $this->call($method, $uri)
            ->assertStatus(401, "expected 401 for guest on {$method} {$uri}");
    }

    public function test_mandant_admin_on_foreign_mandant_is_denied(): void
    {
        // mandant_admin of mandant A, current context switched to foreign B.
        $admin = $this->mandantAdmin($this->mandantA);
        MandantContext::set($this->mandantB);

        foreach (['get', 'post', 'delete'] as $method) {
            foreach (['logo', 'header'] as $kind) {
                $uri = '/api/mandant/'.$kind;
                $this->actingAsApi($admin)
                    ->call($method, $uri)
                    ->assertStatus(403, "expected 403 on {$method} {$uri} for foreign mandant context");
            }
        }

        $this->assertFalse($admin->hasPermission('mandant.media.manage'));
    }

    public function test_super_admin_works_on_primary_mandant_context(): void
    {
        $this->mandantA->update(['is_primary' => true]);
        MandantContext::set($this->mandantA);

        $admin = $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);

        $this->actingAsApi($admin)
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('logo.png')])
            ->assertStatus(200);

        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/logo.png');
        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => '_tenants/'.$this->mandantA->id.'/logo.png',
        ]);
    }

    public function test_self_service_route_404_when_no_mandant_in_context(): void
    {
        // No primary mandant and no fallback configured → MandantContext yields
        // null. super_admin bypasses the gate, so the controller 404s.
        MandantContext::reset();

        $admin = $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);

        $this->actingAsApi($admin)
            ->getJson('/api/mandant/logo')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Kein Mandant im Kontext.');
    }

    /* ---------------------------------------------------------------------
     | Validation
     | ------------------------------------------------------------------- */

    public function test_non_image_upload_is_rejected(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', [
                'file' => UploadedFile::fake()->create('logo.txt', 100, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.txt');
    }

    public function test_oversized_image_dimensions_are_rejected(): void
    {
        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->post('/api/mandant/logo', [
                'file' => UploadedFile::fake()->image('big.png', 2001, 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.png');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function mandantAdmin(Mandant $mandant): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $mandant->id);
    }

    private function teamAdmin(Mandant $mandant): User
    {
        return $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $mandant->id);
    }

    private function plainUser(Mandant $mandant): User
    {
        return $this->createUserWithRole(UserRole::USER->value, $mandant->id);
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

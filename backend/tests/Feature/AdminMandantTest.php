<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\MandantController;
use App\Http\Controllers\Api\Admin\MandantDomainController;
use App\Http\Controllers\Api\Admin\MandantMediaController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\MediaPathService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * P2a Super Admin API — mandants, domains, logo/header media, smtp_config.
 *
 * Access matrix: only the global super admin may reach the /api/admin/*
 * surface; mandant_admin, team_admin, user and guests are rejected.
 */
class AdminMandantTest extends TestCase
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

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Access matrix
     | ------------------------------------------------------------------- */

    public static function adminGetRoutesProvider(): array
    {
        return [
            'mandants index' => ['get', '/api/admin/mandants'],
            'mandants show' => ['get', '/api/admin/mandants/{id}'],
            'domains index' => ['get', '/api/admin/mandants/{id}/domains'],
            'logo show' => ['get', '/api/admin/mandants/{id}/logo'],
            'header show' => ['get', '/api/admin/mandants/{id}/header'],
        ];
    }

    #[DataProvider('adminGetRoutesProvider')]
    public function test_non_super_admin_roles_are_denied_on_all_admin_routes(string $method, string $uri): void
    {
        $url = str_replace('{id}', (string) $this->mandantA->id, $uri);

        $deniedUsers = [
            'mandant_admin' => $this->mandantAdmin($this->mandantA),
            'team_admin' => $this->teamAdmin($this->mandantA),
            'user' => $this->plainUser($this->mandantA),
        ];

        foreach ($deniedUsers as $label => $user) {
            $this->actingAsApi($user)
                ->call($method, $url)
                ->assertStatus(403, "expected 403 for {$label} on {$method} {$url}");
        }
    }

    /**
     * P2b-F1: the teams *read* endpoint is guarded by `can:teams.view` — a
     * mandant_admin may list all teams, a team_admin only his own team(s). A
     * plain user stays denied. Write endpoints are covered by the write
     * provider above (still 403).
     */
    public function test_teams_index_read_is_accessible_to_mandant_and_team_admin(): void
    {
        $own = $this->mandantA->teams()->create(['name' => 'Eigenes', 'slug' => 'eigenes']);
        $this->mandantA->teams()->create(['name' => 'Fremdes Team', 'slug' => 'fremdes-team']);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $own->id);
        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->actingAsApi($this->plainUser($this->mandantA))
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')
            ->assertStatus(403);
    }

    public static function adminWriteRoutesProvider(): array
    {
        return [
            'mandants store' => ['post', '/api/admin/mandants', ['name' => 'X', 'slug' => 'x']],
            'mandants update' => ['put', '/api/admin/mandants/{id}', ['name' => 'Y']],
            'mandants destroy' => ['delete', '/api/admin/mandants/{id}', []],
            'domains store' => ['post', '/api/admin/mandants/{id}/domains', ['hostname' => 'hack.test']],
            'domains destroy' => ['delete', '/api/admin/mandants/{id}/domains/{domainId}', []],
            'logo store' => ['post', '/api/admin/mandants/{id}/logo', []],
            'logo destroy' => ['delete', '/api/admin/mandants/{id}/logo', []],
            'header store' => ['post', '/api/admin/mandants/{id}/header', []],
            'header destroy' => ['delete', '/api/admin/mandants/{id}/header', []],
            'teams store' => ['post', '/api/admin/mandants/{id}/teams', ['name' => 'Hack', 'slug' => 'hack']],
            'teams update' => ['put', '/api/admin/mandants/{id}/teams/{teamId}', ['name' => 'Hacked']],
            'teams destroy' => ['delete', '/api/admin/mandants/{id}/teams/{teamId}', []],
        ];
    }

    #[DataProvider('adminWriteRoutesProvider')]
    public function test_non_super_admin_roles_are_denied_on_all_admin_write_routes(string $method, string $uri, array $data): void
    {
        $domain = $this->mandantA->domains()->create(['hostname' => 'zu-loeschen.test']);
        $team = $this->mandantA->teams()->create(['name' => 'FC Beispiel', 'slug' => 'fc-beispiel']);

        $url = str_replace(
            ['{id}', '{domainId}', '{teamId}'],
            [(string) $this->mandantA->id, (string) $domain->id, (string) $team->id],
            $uri,
        );

        $deniedUsers = [
            'mandant_admin' => $this->mandantAdmin($this->mandantA),
            'team_admin' => $this->teamAdmin($this->mandantA),
            'user' => $this->plainUser($this->mandantA),
        ];

        foreach ($deniedUsers as $label => $user) {
            $this->actingAsApi($user)
                ->json($method, $url, $data)
                ->assertStatus(403, "expected 403 for {$label} on {$method} {$url}");
        }
    }

    public function test_super_admin_can_access_all_admin_endpoints(): void
    {
        $admin = $this->superAdmin();

        $this->actingAsApi($admin)->getJson('/api/admin/mandants')->assertOk();
        $this->actingAsApi($admin)->getJson('/api/admin/mandants/'.$this->mandantA->id)->assertOk();
        $this->actingAsApi($admin)->getJson('/api/admin/mandants/'.$this->mandantA->id.'/domains')->assertOk();
        $this->actingAsApi($admin)->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')->assertOk();
        $this->actingAsApi($admin)->getJson('/api/admin/mandants/'.$this->mandantA->id.'/logo')->assertStatus(404);
        $this->actingAsApi($admin)->getJson('/api/admin/mandants/'.$this->mandantA->id.'/header')->assertStatus(404);
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/mandants')->assertStatus(401);
        $this->getJson('/api/admin/mandants/'.$this->mandantA->id.'/teams')->assertStatus(401);
        $this->postJson('/api/admin/mandants', ['name' => 'X', 'slug' => 'x'])->assertStatus(401);
    }

    /* ---------------------------------------------------------------------
     | Mandant CRUD
     | ------------------------------------------------------------------- */

    public function test_can_create_mandant(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants', [
                'name' => 'Neuer Verband',
                'slug' => 'neuer-verband',
                'teams_enabled' => true,
                'is_active' => false,
                'impressum_text' => 'Impressum Beispiel',
                'privacy_text' => 'Datenschutz Beispiel',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Neuer Verband')
            ->assertJsonPath('data.slug', 'neuer-verband')
            ->assertJsonPath('data.teams_enabled', true)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.impressum_text', 'Impressum Beispiel')
            ->assertJsonPath('data.privacy_text', 'Datenschutz Beispiel')
            ->assertJsonPath('data.teams_count', 0)
            ->assertJsonPath('data.domains', []);

        $this->assertDatabaseHas('mandants', [
            'slug' => 'neuer-verband',
            'name' => 'Neuer Verband',
            'impressum_text' => 'Impressum Beispiel',
            'privacy_text' => 'Datenschutz Beispiel',
        ]);
    }

    public static function invalidSlugProvider(): array
    {
        return [
            'uppercase' => ['Foo'],
            'underscore' => ['foo_bar'],
            'double dash' => ['foo--bar'],
            'leading dash' => ['-foo'],
            'trailing dash' => ['foo-'],
            'space' => ['foo bar'],
        ];
    }

    #[DataProvider('invalidSlugProvider')]
    public function test_cannot_create_mandant_with_invalid_slug(string $slug): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants', ['name' => 'X', 'slug' => $slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_cannot_create_mandant_with_duplicate_slug(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants', ['name' => 'X', 'slug' => $this->mandantA->slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_cannot_create_mandant_without_required_fields(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug']);
    }

    public function test_mandant_name_rejects_invalid_utf8_with_422_not_500(): void
    {
        // Form-encoded bytes (`name=\xFF`) survive into the validated payload
        // and would otherwise reach the JSON response encoder → HTTP 500.
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants', ['name' => "\xFF", 'slug' => 'utf8-invalid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseMissing('mandants', ['slug' => 'utf8-invalid']);

        $this->actingAsApi($this->superAdmin())
            ->put('/api/admin/mandants/'.$this->mandantA->id, ['name' => "\xFF"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id, 'name' => 'Verband A']);
    }

    public function test_mandant_impressum_text_rejects_invalid_utf8_with_422_not_500(): void
    {
        // Legal texts are persisted as raw text (no HTML strip here) — the
        // guard only checks the bytes, so a broken sequence is a 422.
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants', [
                'name' => 'UTF8 Verband',
                'slug' => 'utf8-verband',
                'impressum_text' => "\xFF",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('impressum_text');

        $this->assertDatabaseMissing('mandants', ['slug' => 'utf8-verband']);

        $this->actingAsApi($this->superAdmin())
            ->put('/api/admin/mandants/'.$this->mandantA->id, ['impressum_text' => "\xFF"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('impressum_text');

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id, 'impressum_text' => null]);
    }

    public function test_mandant_privacy_text_rejects_invalid_utf8_with_422_not_500(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants', [
                'name' => 'UTF8 Verband 2',
                'slug' => 'utf8-verband-2',
                'privacy_text' => "\xFF",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('privacy_text');

        $this->assertDatabaseMissing('mandants', ['slug' => 'utf8-verband-2']);

        $this->actingAsApi($this->superAdmin())
            ->put('/api/admin/mandants/'.$this->mandantA->id, ['privacy_text' => "\xFF"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('privacy_text');

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id, 'privacy_text' => null]);
    }

    public function test_can_update_mandant_partially(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id, [
                'name' => 'Verband A neu',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Verband A neu')
            ->assertJsonPath('data.slug', 'verband-a');

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'name' => 'Verband A neu',
            'slug' => 'verband-a',
        ]);
    }

    public function test_can_toggle_teams_enabled_and_is_active(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantA->id, [
                'teams_enabled' => true,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.teams_enabled', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'teams_enabled' => true,
            'is_active' => false,
        ]);
    }

    public function test_cannot_update_mandant_to_existing_slug(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantB->id, [
                'slug' => $this->mandantA->slug,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_cannot_delete_primary_mandant(): void
    {
        $primary = Mandant::factory()->create(['slug' => 'prim', 'is_primary' => true]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$primary->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('mandants', ['id' => $primary->id]);
    }

    public function test_cannot_delete_mandant_with_teams(): void
    {
        $this->mandantA->teams()->create(['name' => 'FC Beispiel', 'slug' => 'fc-beispiel']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id)
            ->assertStatus(409);

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id]);
    }

    public function test_can_delete_mandant(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantB->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('mandants', ['id' => $this->mandantB->id]);
    }

    public function test_deleting_mandant_forgets_domain_host_cache(): void
    {
        $this->mandantB->domains()->create(['hostname' => 'verband-b.de']);

        // Prime the host→mandant cache.
        $this->assertSame($this->mandantB->id, MandantContext::resolve('verband-b.de')?->id);
        $this->assertTrue(Cache::has('mandant.domain.verband-b.de'));

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantB->id)
            ->assertStatus(204);

        $this->assertFalse(Cache::has('mandant.domain.verband-b.de'));
        $this->assertNull(MandantContext::resolve('verband-b.de'));
    }

    public function test_deleting_a_mandant_drops_the_cached_hostname_allow_list(): void
    {
        // WF-2-c: `destroy()` dropped the per-host mapping but not the
        // hostname LIST that the `trustHosts` allow-list is built from. The
        // list is cached for `mandants.cache_ttl` (3600 s), so a deleted
        // mandant stayed allow-listed for up to an hour: the request passed
        // the Host check and then 404'd in MandantContextMiddleware instead
        // of being rejected as a foreign host. Same invalidation as
        // `MandantDomainController` does on domain create/delete.
        $this->mandantB->domains()->create(['hostname' => 'verband-b.de']);

        // Prime the list (the TrustHosts middleware does this on every
        // request, so it is warm by the time the controller runs).
        $this->assertContains('verband-b.de', MandantContext::hostnames() ?? []);
        $this->assertNotNull(
            Cache::get(MandantContext::HOSTNAMES_CACHE_KEY),
            'precondition: warm hostname-list cache',
        );

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantB->id)
            ->assertStatus(204);

        $this->assertNull(
            Cache::get(MandantContext::HOSTNAMES_CACHE_KEY),
            'the hostname list must be invalidated when a mandant is deleted',
        );
        $this->assertNotContains('verband-b.de', MandantContext::hostnames() ?? []);
    }

    public function test_index_orders_mandants_by_name(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Verband A')
            ->assertJsonPath('data.1.name', 'Verband B');
    }

    /* ---------------------------------------------------------------------
     | Domains
     | ------------------------------------------------------------------- */

    public function test_can_add_domain(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/domains', [
                'hostname' => 'example.com',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.hostname', 'example.com');

        $this->assertDatabaseHas('mandant_domains', [
            'mandant_id' => $this->mandantA->id,
            'hostname' => 'example.com',
        ]);
    }

    public function test_adding_domain_clears_negative_host_cache(): void
    {
        // A previous failed lookup caches a MISSING sentinel for this host.
        $this->assertNull(MandantContext::resolve('frisch.domain.test'));
        $this->assertTrue(Cache::has('mandant.domain.frisch.domain.test'));

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/domains', [
                'hostname' => 'frisch.domain.test',
            ])
            ->assertStatus(201);

        // The negative cache entry must be dropped so the new domain resolves.
        $this->assertFalse(Cache::has('mandant.domain.frisch.domain.test'));
        $this->assertNotNull(MandantContext::resolve('frisch.domain.test'));
        $this->assertSame($this->mandantA->id, MandantContext::resolve('frisch.domain.test')->id);
    }

    public static function invalidHostnameProvider(): array
    {
        return [
            'scheme' => ['https://example.com'],
            'port' => ['example.com:8080'],
            'path' => ['example.com/path'],
            'uppercase' => ['Example.COM'],
            'double dot' => ['foo..com'],
            'leading dash label' => ['-foo.com'],
            'trailing dash label' => ['foo-.com'],
            'underscore' => ['foo_bar.com'],
            'trailing dot' => ['example.com.'],
        ];
    }

    #[DataProvider('invalidHostnameProvider')]
    public function test_rejects_invalid_hostnames(string $hostname): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/domains', [
                'hostname' => $hostname,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hostname');
    }

    public function test_rejects_duplicate_hostname_globally(): void
    {
        $this->mandantA->domains()->create(['hostname' => 'shared.example.com']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantB->id.'/domains', [
                'hostname' => 'shared.example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hostname');
    }

    public function test_domain_index_lists_domains_of_mandant_only(): void
    {
        $this->mandantA->domains()->create(['hostname' => 'a.example.com']);
        $this->mandantB->domains()->create(['hostname' => 'b.example.com']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/domains')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hostname', 'a.example.com');
    }

    public function test_can_delete_domain(): void
    {
        $domain = $this->mandantA->domains()->create(['hostname' => 'verband-a.de']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/domains/'.$domain->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('mandant_domains', ['id' => $domain->id]);
    }

    public function test_deleted_domain_hostname_is_reusable(): void
    {
        $domain = $this->mandantA->domains()->create(['hostname' => 'reuse.de']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/domains/'.$domain->id)
            ->assertStatus(204);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantA->id.'/domains', [
                'hostname' => 'reuse.de',
            ])
            ->assertStatus(201);
    }

    public function test_cannot_delete_domain_of_foreign_mandant(): void
    {
        $domain = $this->mandantB->domains()->create(['hostname' => 'b.example.com']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/domains/'.$domain->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('mandant_domains', ['id' => $domain->id]);
    }

    /* ---------------------------------------------------------------------
     | Logo / Header media
     | ------------------------------------------------------------------- */

    public function test_upload_logo_stores_file_in_media_layout(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
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

    public function test_upload_logo_uses_domain_layout_with_configured_domain(): void
    {
        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(200);

        $this->assertSame('verband-a.test/logo.png', $this->mandantA->fresh()->logo_path);
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/logo.png');
    }

    public function test_upload_logo_rejects_invalid_file_type(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->create('logo.txt', 100, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.txt');
    }

    public function test_upload_logo_rejects_oversized_file(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('huge.png')->size(3000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_logo_rejects_oversized_dimensions(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('big.png', 2001, 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.png');
    }

    public function test_logo_delivery_is_auth_gated_with_correct_content_type(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(200);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeaderContains('Content-Disposition', 'inline');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/logo')
            ->assertStatus(403);
    }

    public function test_logo_delivery_returns_404_without_file(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/logo')
            ->assertStatus(404);
    }

    public function test_delete_logo_removes_file_and_path(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(200);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/logo')
            ->assertStatus(204);

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id, 'logo_path' => null]);
        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.png');
    }

    public function test_replacing_logo_deletes_the_previous_file(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo-a.png'),
            ])
            ->assertStatus(200);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo-b.jpg'),
            ])
            ->assertStatus(200);

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.png');
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/logo.jpg');
        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => '_tenants/'.$this->mandantA->id.'/logo.jpg',
        ]);
    }

    public function test_upload_logo_derives_extension_from_mime_not_client_name(): void
    {
        // A client name whose extension does not match the validated MIME type
        // (`.php` names are already blocked by the `mimes` rule, so `.txt`
        // reproduces the same mismatch without tripping that security check).
        $png = UploadedFile::fake()->image('logo.png');
        $disguised = new UploadedFile(
            (string) $png->getRealPath(),
            'logo.txt',
            'image/png',
            null,
            true,
        );

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => $disguised,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => '_tenants/'.$this->mandantA->id.'/logo.png',
        ]);
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/logo.txt');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_can_upload_and_delete_header_image(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/header', [
                'file' => UploadedFile::fake()->image('header.png'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.header_url', route('api.admin.mandants.header', ['mandant' => $this->mandantA->id]));

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'header_path' => '_tenants/'.$this->mandantA->id.'/header.png',
        ]);
        Storage::disk(MediaPathService::DISK)->assertExists('_tenants/'.$this->mandantA->id.'/header.png');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/header')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/header')
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)->assertMissing('_tenants/'.$this->mandantA->id.'/header.png');
        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id, 'header_path' => null]);
    }

    /* ---------------------------------------------------------------------
     | smtp_config
     | ------------------------------------------------------------------- */

    public function test_smtp_password_never_serialized_and_has_password_flag(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants', [
                'name' => 'SMTP Verband',
                'slug' => 'smtp-verband',
                'smtp_config' => [
                    'host' => 'mail.example.com',
                    'port' => 587,
                    'username' => 'user@example.com',
                    'password' => 'geheim123',
                    'encryption' => 'tls',
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.smtp_config.host', 'mail.example.com')
            ->assertJsonPath('data.smtp_config.port', 587)
            ->assertJsonPath('data.smtp_has_password', true);

        $this->assertArrayNotHasKey('password', $response->json('data.smtp_config'));

        $mandant = Mandant::query()->where('slug', 'smtp-verband')->firstOrFail();

        $show = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$mandant->id)
            ->assertOk()
            ->assertJsonPath('data.smtp_has_password', true);

        $this->assertArrayNotHasKey('password', $show->json('data.smtp_config'));

        // The password itself is persisted (server needs it to send mail).
        $this->assertSame('geheim123', $mandant->smtp_config['password']);
    }

    public function test_smtp_config_rejects_invalid_utf8_with_422_not_500(): void
    {
        // Form-encoded bytes survive into the nested smtp_config payload and
        // would otherwise be persisted / echoed through the JSON encoder → 500.
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants', [
                'name' => 'UTF8 SMTP Verband',
                'slug' => 'utf8-smtp-verband',
                'smtp_config' => ['host' => "\xFF"],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('smtp_config.host');

        $this->assertDatabaseMissing('mandants', ['slug' => 'utf8-smtp-verband']);

        foreach (['host', 'username', 'encryption', 'password'] as $field) {
            $this->actingAsApi($this->superAdmin())
                ->put('/api/admin/mandants/'.$this->mandantA->id, [
                    'smtp_config' => [$field => "\xFF"],
                ])
                ->assertStatus(422, "expected 422 for smtp_config.{$field}")
                ->assertJsonValidationErrors('smtp_config.'.$field);
        }

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantA->id, 'smtp_config' => null]);
    }

    public function test_smtp_password_is_preserved_when_config_updated_without_it(): void
    {
        $mandant = Mandant::factory()->create([
            'slug' => 'smtp-keep',
            'smtp_config' => [
                'host' => 'mail.example.com',
                'port' => 587,
                'username' => 'u',
                'password' => 'altes-passwort',
            ],
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, [
                'smtp_config' => ['host' => 'new-mail.example.com'],
            ])
            ->assertOk()
            ->assertJsonPath('data.smtp_config.host', 'new-mail.example.com')
            ->assertJsonPath('data.smtp_config.port', 587)
            ->assertJsonPath('data.smtp_has_password', true);

        $this->assertSame('altes-passwort', $mandant->fresh()->smtp_config['password']);
    }

    public function test_smtp_password_can_be_replaced(): void
    {
        $mandant = Mandant::factory()->create([
            'slug' => 'smtp-replace',
            'smtp_config' => ['host' => 'mail.example.com', 'password' => 'alt'],
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, [
                'smtp_config' => ['password' => 'neu'],
            ])
            ->assertOk()
            ->assertJsonPath('data.smtp_has_password', true);

        $this->assertSame('neu', $mandant->fresh()->smtp_config['password']);
    }

    public function test_smtp_password_null_or_empty_keeps_stored_password(): void
    {
        $mandant = Mandant::factory()->create([
            'slug' => 'smtp-keep-null',
            'smtp_config' => ['host' => 'mail.example.com', 'password' => 'alt'],
        ]);

        foreach ([['password' => null], ['password' => '']] as $payload) {
            $this->actingAsApi($this->superAdmin())
                ->putJson('/api/admin/mandants/'.$mandant->id, [
                    'smtp_config' => $payload,
                ])
                ->assertOk()
                ->assertJsonPath('data.smtp_has_password', true);
        }

        $this->assertSame('alt', $mandant->fresh()->smtp_config['password']);
    }

    public function test_smtp_config_null_clears_config(): void
    {
        $mandant = Mandant::factory()->create([
            'slug' => 'smtp-clear',
            'smtp_config' => ['host' => 'mail.example.com', 'port' => 587, 'password' => 'alt'],
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, [
                'smtp_config' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.smtp_config.host', null)
            ->assertJsonPath('data.smtp_config.port', null)
            ->assertJsonPath('data.smtp_config.username', null)
            ->assertJsonPath('data.smtp_config.encryption', null)
            ->assertJsonPath('data.smtp_has_password', false);

        $this->assertSame(
            ['host' => null, 'port' => null, 'username' => null, 'password' => null, 'encryption' => null],
            $mandant->fresh()->smtp_config,
        );
    }

    public function test_smtp_config_absent_is_noop(): void
    {
        $mandant = Mandant::factory()->create([
            'slug' => 'smtp-noop',
            'smtp_config' => ['host' => 'mail.example.com', 'port' => 587, 'password' => 'alt'],
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, [
                'name' => 'Nur Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nur Name')
            ->assertJsonPath('data.smtp_config.host', 'mail.example.com')
            ->assertJsonPath('data.smtp_config.port', 587)
            ->assertJsonPath('data.smtp_has_password', true);

        $this->assertSame('alt', $mandant->fresh()->smtp_config['password']);
    }

    public function test_smtp_config_rejects_non_array_payload(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants', [
                'name' => 'X',
                'slug' => 'x',
                'smtp_config' => 'not-an-array',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('smtp_config');
    }

    /* ---------------------------------------------------------------------
     | Resource shape
     | ------------------------------------------------------------------- */

    public function test_mandant_resource_exposes_domains_and_teams_count(): void
    {
        $this->mandantA->domains()->create(['hostname' => 'verband-a.de']);
        $this->mandantA->teams()->create(['name' => 'FC Test', 'slug' => 'fc-test']);
        $this->mandantB->teams()->create(['name' => 'Anderer', 'slug' => 'anderer']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.domains')
            ->assertJsonPath('data.domains.0.hostname', 'verband-a.de')
            ->assertJsonPath('data.teams_count', 1)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.header_url', null);
    }

    public function test_index_resources_include_domains_and_teams_count(): void
    {
        $this->mandantA->domains()->create(['hostname' => 'a.de']);
        $this->mandantA->teams()->create(['name' => 'T', 'slug' => 't']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.domains.0.hostname', 'a.de')
            ->assertJsonPath('data.0.teams_count', 1)
            ->assertJsonPath('data.1.teams_count', 0);
    }

    /* ---------------------------------------------------------------------
     | The `{mandant}` route parameter — 403/404 unification
     |
     | `Mandant` is the one model that must NOT get a mandant-scoped
     | `resolveRouteBindingQuery()`: such a binding would take away the
     | super_admin's entire purpose. So the check lives in the controller
     | (`ResolvesMandantRouteParameter`), where it can branch on the CALLER.
     | The host is mandantA throughout (`setUp()`).
     |
     | What the code path actually looks like today, measured:
     |
     |   - `can:mandants.manage` is granted to NO role (only super_admin, via
     |     the `*` matrix entry + `Gate::before`). A mandant_admin addressing a
     |     foreign mandant on those 11 routes is therefore refused by the ROUTE
     |     GATE with 403 before the controller ever runs — the guard behind it
     |     is defence in depth, and only observable by bypassing the router.
     |   - `can:teams.view` IS granted to mandant_admin/team_admin, so on
     |     `teams index` the controller guard is the thing that refuses, and it
     |     answers 404 (the mandant axis).
     |
     | Both halves below are a pair and must stay one: the guard test pins the
     | 404, the super_admin test pins that the cross-host flow survives.
     * ------------------------------------------------------------------- */

    /**
     * Every `{mandant}` action of the tenant-CRUD surface, as
     * `[controller, action, http verb, params, extra args]` so the guard can be
     * invoked directly (the router would answer 403 at the gate first).
     */
    public static function mandantRouteParameterActionsProvider(): array
    {
        return [
            'mandants show' => [MandantController::class, 'show', 'GET', [], []],
            'mandants update' => [MandantController::class, 'update', 'PUT', ['name' => 'X'], []],
            'mandants destroy' => [MandantController::class, 'destroy', 'DELETE', [], []],
            'domains index' => [MandantDomainController::class, 'index', 'GET', [], []],
            'domains store' => [MandantDomainController::class, 'store', 'POST', ['hostname' => 'neu.test'], []],
            'domains destroy' => [MandantDomainController::class, 'destroy', 'DELETE', [], ['1']],
            'logo show' => [MandantMediaController::class, 'showLogo', 'GET', [], []],
            'logo store' => [MandantMediaController::class, 'storeLogo', 'POST', [], []],
            'logo destroy' => [MandantMediaController::class, 'destroyLogo', 'DELETE', [], []],
            'header show' => [MandantMediaController::class, 'showHeader', 'GET', [], []],
            'header store' => [MandantMediaController::class, 'storeHeader', 'POST', [], []],
            'header destroy' => [MandantMediaController::class, 'destroyHeader', 'DELETE', [], []],
            'teams index' => [TeamController::class, 'index', 'GET', [], []],
        ];
    }

    /**
     * The guard itself, with the router bypassed: a mandant_admin of mandantA
     * addressing mandantB is 404 on EVERY `{mandant}` action, and nothing is
     * written before the rejection.
     *
     * Calling the action directly is the only way to observe the guard on the
     * `mandants.manage` routes — over HTTP the gate's 403 arrives first. That
     * is exactly why the guard must exist as a second layer: it is what holds
     * the moment `mandants.manage` is ever granted to a non-super-admin role
     * (as `config/permissions.php` already does for `teams.manage`).
     */
    #[DataProvider('mandantRouteParameterActionsProvider')]
    public function test_the_mandant_route_parameter_guard_is_404_for_a_non_super_admin(
        string $controller,
        string $action,
        string $verb,
        array $params,
        array $extra,
    ): void {
        $mandantAdmin = $this->mandantAdmin($this->mandantA);
        $request = $this->mandantRouteParameterRequest($verb, $params, $mandantAdmin);

        $domainsBefore = MandantDomain::query()->count();

        try {
            app($controller)->{$action}($request, $this->mandantB, ...$extra);

            $this->fail("expected a 404 from {$controller}::{$action} for a foreign mandant");
        } catch (NotFoundHttpException $exception) {
            // 404, not 403: the mandant axis is 404 across the whole codebase
            // (`assertMandantScope()`, every `resolveRouteBindingQuery()` scope
            // on the tenant models). 403 is reserved for the team/role axes
            // (`assertOwnership()`, `authorizeSuperAdmin()`). Keeping the two
            // axes on distinct codes is what makes them tellable apart.
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantB->id]);
        $this->assertSame($domainsBefore, MandantDomain::query()->count(), 'no domain may be created for a foreign mandant');
    }

    /**
     * Over HTTP the 11 `mandants.manage` routes refuse a mandant_admin with the
     * gate's 403 and `teams index` with the guard's 404. Pinned as a SET
     * because the split is deliberate: the gate answers "you lack the
     * permission", the guard answers "that mandant is not reachable from here".
     */
    #[DataProvider('mandantScopedRoutesProvider')]
    public function test_mandant_admin_addressing_a_foreign_mandant_is_refused(string $method, string $uri, ?array $data): void
    {
        $this->mandantB->teams()->create(['name' => 'Fremder Verein', 'slug' => 'fremder-verein']);

        $domainsBefore = MandantDomain::query()->count();

        $url = str_replace('{id}', (string) $this->mandantB->id, $uri);

        $status = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->json($method, $url, $data ?? [])
            ->getStatusCode();

        $this->assertContains(
            $status,
            [403, 404],
            "expected the request to be refused (403 at the gate, 404 at the guard) on {$method} {$url}",
        );

        // A refusal must happen BEFORE any side effect: a hostname decides
        // which mandant a request resolves to, so a leaked write here is a live
        // tenant-boundary break, not a cosmetic status code.
        $this->assertDatabaseHas('mandants', ['id' => $this->mandantB->id]);
        $this->assertSame($domainsBefore, MandantDomain::query()->count(), 'no domain may be created for a foreign mandant');
    }

    public static function mandantScopedRoutesProvider(): array
    {
        return [
            'mandants show' => ['get', '/api/admin/mandants/{id}', null],
            'mandants update' => ['put', '/api/admin/mandants/{id}', ['name' => 'Umbenannt']],
            'mandants destroy' => ['delete', '/api/admin/mandants/{id}', null],
            'domains index' => ['get', '/api/admin/mandants/{id}/domains', null],
            'domains store' => ['post', '/api/admin/mandants/{id}/domains', ['hostname' => 'fremd.test']],
            'logo show' => ['get', '/api/admin/mandants/{id}/logo', null],
            'logo store' => ['post', '/api/admin/mandants/{id}/logo', null],
            'logo destroy' => ['delete', '/api/admin/mandants/{id}/logo', null],
            'header show' => ['get', '/api/admin/mandants/{id}/header', null],
            'header store' => ['post', '/api/admin/mandants/{id}/header', null],
            'header destroy' => ['delete', '/api/admin/mandants/{id}/header', null],
            'teams index' => ['get', '/api/admin/mandants/{id}/teams', null],
        ];
    }

    /**
     * THE regression: super_admin must keep addressing any mandant from any
     * host. Without this test the guard could be "hardened" by dropping its
     * super_admin branch and the 404 test above would still pass — while the
     * tenant-CRUD surface silently stopped working for the only role that is
     * supposed to have it.
     */
    #[DataProvider('mandantScopedRoutesProvider')]
    public function test_super_admin_may_address_any_mandant_from_any_host(string $method, string $uri, ?array $data): void
    {
        $this->mandantB->teams()->create(['name' => 'Fremder Verein', 'slug' => 'fremder-verein']);

        $url = str_replace('{id}', (string) $this->mandantB->id, $uri);

        // mandantA is the current context, mandantB is addressed. 404 is a
        // legitimate answer for a few of these on their own merits (no brand
        // media stored yet; the is_primary / has-teams delete guards), so the
        // assertion is deliberately negative: 403 would mean the super_admin
        // branch of the guard had been tightened away.
        $status = $this->actingAsApi($this->superAdmin())
            ->json($method, $url, $data ?? [])
            ->getStatusCode();

        $this->assertNotSame(403, $status, "super_admin must not be 403 on {$method} {$url}");

        $this->assertDatabaseHas('mandants', ['id' => $this->mandantB->id]);
    }

    /**
     * The headline flow, pinned on its own: `PUT /api/admin/mandants/{B}` with
     * A as the request host must still write. This is the exact case a
     * binding-scope "fix" would break, and the reason the guard branches on the
     * caller instead of on the host.
     */
    public function test_super_admin_updates_a_foreign_mandant_from_another_hosts_context(): void
    {
        $this->assertSame($this->mandantA->id, MandantContext::currentId());

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$this->mandantB->id, ['name' => 'Verband B umbenannt'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Verband B umbenannt');

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantB->id,
            'name' => 'Verband B umbenannt',
        ]);
    }

    /**
     * The domains guard is the sharpest one: a hostname decides which mandant a
     * request resolves to, so it must stay as narrow as the mandant CRUD.
     */
    public function test_super_admin_creates_a_domain_on_a_foreign_mandant_from_another_hosts_context(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/mandants/'.$this->mandantB->id.'/domains', ['hostname' => 'verband-b-neu.test'])
            ->assertStatus(201)
            ->assertJsonPath('data.hostname', 'verband-b-neu.test');

        $this->assertDatabaseHas('mandant_domains', [
            'mandant_id' => $this->mandantB->id,
            'hostname' => 'verband-b-neu.test',
        ]);
    }

    /**
     * A mandant_admin manages his OWN mandant's brand media through the
     * separate host-derived surface (`/api/mandant/logo`, P8b). The super-admin
     * route must not become a back door to a foreign mandant's logo (that is
     * what the guard tests above pin); this asserts the legitimate path still
     * works in the same breath, so the fix cannot be "just close the route".
     */
    public function test_mandant_admin_keeps_his_own_brand_media_through_the_self_service_surface(): void
    {
        $mandantAdmin = $this->mandantAdmin($this->mandantA);

        $this->actingAsApi($mandantAdmin)
            ->post('/api/mandant/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)])
            ->assertStatus(201);

        $this->actingAsApi($mandantAdmin)
            ->get('/api/mandant/logo')
            ->assertOk();
    }

    /**
     * A request built for a direct (router-less) controller call, carrying the
     * given user. `MandantMediaController` validates a `file` input before it
     * would touch the mandant, so one is attached unconditionally — the guard
     * under test runs before that validation anyway.
     */
    private function mandantRouteParameterRequest(string $verb, array $params, User $as): Request
    {
        $request = Request::create(
            '/api/admin/mandants/'.$this->mandantB->id.'/logo',
            $verb,
            $params,
            [],
            ['file' => UploadedFile::fake()->image('logo.png', 200, 200)],
        );

        $request->setUserResolver(fn (): User => $as);

        return $request;
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

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

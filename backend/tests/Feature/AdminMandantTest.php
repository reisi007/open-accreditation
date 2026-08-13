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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'teams index' => ['get', '/api/admin/mandants/{id}/teams'],
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

    public function test_upload_logo_stores_file_on_private_disk(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.logo_url', route('api.admin.mandants.logo', ['mandant' => $this->mandantA->id]));

        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => 'mandants/verband-a/logo.png',
        ]);
        Storage::disk('private')->assertExists('mandants/verband-a/logo.png');
    }

    public function test_upload_logo_rejects_invalid_file_type(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/mandants/'.$this->mandantA->id.'/logo', [
                'file' => UploadedFile::fake()->create('logo.txt', 100, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Storage::disk('private')->assertMissing('mandants/verband-a/logo.txt');
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

        Storage::disk('private')->assertMissing('mandants/verband-a/logo.png');
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
        Storage::disk('private')->assertMissing('mandants/verband-a/logo.png');
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

        Storage::disk('private')->assertMissing('mandants/verband-a/logo.png');
        Storage::disk('private')->assertExists('mandants/verband-a/logo.jpg');
        $this->assertDatabaseHas('mandants', [
            'id' => $this->mandantA->id,
            'logo_path' => 'mandants/verband-a/logo.jpg',
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
            'logo_path' => 'mandants/verband-a/logo.png',
        ]);
        Storage::disk('private')->assertExists('mandants/verband-a/logo.png');
        Storage::disk('private')->assertMissing('mandants/verband-a/logo.txt');

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
            'header_path' => 'mandants/verband-a/header.png',
        ]);
        Storage::disk('private')->assertExists('mandants/verband-a/header.png');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants/'.$this->mandantA->id.'/header')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$this->mandantA->id.'/header')
            ->assertStatus(204);

        Storage::disk('private')->assertMissing('mandants/verband-a/header.png');
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

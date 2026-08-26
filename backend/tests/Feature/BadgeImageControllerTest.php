<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P4 / badge-template-editor — mandant-owned badge images (features/badge-
 * template-editor.md, "Upload-Infrastruktur").
 *
 * The upload/delivery surface of the freely placed `image` layout entries:
 *
 *   GET    /api/admin/badge-images                    mandant-scoped list
 *   POST   /api/admin/badge-images                    upload (`file`)
 *   GET    /api/admin/badge-images/{id}/file          auth-gated delivery
 *   DELETE /api/admin/badge-images/{id}               remove row + file
 *
 * Guarded by `can:accreditations.manage` (super_admin + mandant_admin write;
 * team_admin reads only). Every row/file is mandant-scoped: a foreign-mandant
 * id never binds (tenant-guarded route model) and the list only ever returns
 * the current mandant's uploads. Upload validation mirrors the self-service
 * media: `file` required, `image`, `mimes:jpeg,png,webp`, `max:2048` KB plus
 * the 2000×2000 px dimension limit; the extension derives from the validated
 * MIME type, never from the client filename.
 */
class BadgeImageControllerTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Upload — validation
     | ------------------------------------------------------------------- */

    public function test_upload_stores_file_on_private_disk_and_returns_resource(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->image('wappen.png'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.original_name', 'wappen.png')
            ->assertJsonPath('data.mime', 'image/png');

        $id = $response->json('data.id');

        $this->assertDatabaseHas('badge_images', [
            'id' => $id,
            'mandant_id' => $this->mandantA->id,
            'original_name' => 'wappen.png',
            'mime' => 'image/png',
        ]);

        $path = BadgeImage::query()->find($id)->path;
        Storage::disk('private')->assertExists($path);
        $this->assertStringStartsWith('badge-images/verband-a/', $path);
        $this->assertStringEndsWith('.png', $path);
    }

    public function test_upload_accepts_jpeg_and_webp(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->image('logo.jpg'),
            ])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->image('logo.webp'),
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('badge_images', 2);
    }

    public function test_upload_rejects_non_image(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->create('evil.txt', 100, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('badge_images', 0);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->image('huge.png')->size(3000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_rejects_oversized_dimensions(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->image('big.png', 2001, 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('badge_images', 0);
    }

    public function test_upload_derives_extension_from_mime_not_client_name(): void
    {
        $png = UploadedFile::fake()->image('logo.png');
        $disguised = new UploadedFile(
            (string) $png->getRealPath(),
            'logo.txt',
            'image/png',
            null,
            true,
        );

        $response = $this->actingAsApi($this->superAdmin())
            ->post('/api/admin/badge-images', ['file' => $disguised])
            ->assertStatus(201);

        $path = BadgeImage::query()->find($response->json('data.id'))->path;
        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('logo.txt', $path);
    }

    /* ---------------------------------------------------------------------
     | List — mandant scoping
     | ------------------------------------------------------------------- */

    public function test_list_returns_only_current_mandants_images(): void
    {
        $imageA = BadgeImage::create([
            'mandant_id' => $this->mandantA->id,
            'path' => 'badge-images/verband-a/a.png',
            'mime' => 'image/png',
            'original_name' => 'a.png',
        ]);
        BadgeImage::create([
            'mandant_id' => $this->mandantB->id,
            'path' => 'badge-images/verband-b/b.png',
            'mime' => 'image/png',
            'original_name' => 'b.png',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/badge-images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $imageA->id)
            ->assertJsonPath('data.0.original_name', 'a.png');
    }

    public function test_list_returns_empty_array_without_uploads(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/badge-images')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------------------------------------------------------------------
     | Delivery — auth-gated stream
     | ------------------------------------------------------------------- */

    public function test_file_delivery_streams_the_private_file(): void
    {
        $image = BadgeImage::create([
            'mandant_id' => $this->mandantA->id,
            'path' => 'badge-images/verband-a/stream.png',
            'mime' => 'image/png',
            'original_name' => 'stream.png',
        ]);
        Storage::disk('private')->put($image->path, 'bytes');

        $this->actingAsApi($this->superAdmin())
            ->getJson("/api/admin/badge-images/{$image->id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_file_delivery_returns_404_without_file(): void
    {
        $image = BadgeImage::create([
            'mandant_id' => $this->mandantA->id,
            'path' => 'badge-images/verband-a/missing.png',
            'mime' => 'image/png',
            'original_name' => 'missing.png',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->getJson("/api/admin/badge-images/{$image->id}/file")
            ->assertStatus(404);
    }

    public function test_file_delivery_is_mandant_scoped(): void
    {
        $imageB = BadgeImage::create([
            'mandant_id' => $this->mandantB->id,
            'path' => 'badge-images/verband-b/foreign.png',
            'mime' => 'image/png',
            'original_name' => 'foreign.png',
        ]);

        // The tenant-guarded route model turns a foreign-mandant id into 404.
        $this->actingAsApi($this->superAdmin())
            ->getJson("/api/admin/badge-images/{$imageB->id}/file")
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Delete
     | ------------------------------------------------------------------- */

    public function test_delete_removes_row_and_file(): void
    {
        $image = BadgeImage::create([
            'mandant_id' => $this->mandantA->id,
            'path' => 'badge-images/verband-a/doomed.png',
            'mime' => 'image/png',
            'original_name' => 'doomed.png',
        ]);
        Storage::disk('private')->put($image->path, 'bytes');

        $this->actingAsApi($this->superAdmin())
            ->deleteJson("/api/admin/badge-images/{$image->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('badge_images', ['id' => $image->id]);
        Storage::disk('private')->assertMissing('badge-images/verband-a/doomed.png');
    }

    public function test_delete_is_mandant_scoped(): void
    {
        $imageB = BadgeImage::create([
            'mandant_id' => $this->mandantB->id,
            'path' => 'badge-images/verband-b/foreign.png',
            'mime' => 'image/png',
            'original_name' => 'foreign.png',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson("/api/admin/badge-images/{$imageB->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('badge_images', ['id' => $imageB->id]);
    }

    /* ---------------------------------------------------------------------
     | Access — team_admin reads, not writes
     | ------------------------------------------------------------------- */

    public function test_team_admin_is_blocked_by_permission_gate(): void
    {
        // Badge images live on the `accreditations.manage` surface — the same
        // gate as badge-templates. team_admin lacks that permission, so the
        // route is 403 before reaching the controller (mirrors the template
        // access model, where team_admin is read-only but templates are a
        // mandant-level resource he cannot see here).
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/badge-images')
            ->assertStatus(403);
    }

    public function test_mandant_admin_may_upload(): void
    {
        $mandantAdmin = $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id);

        $this->actingAsApi($mandantAdmin)
            ->post('/api/admin/badge-images', [
                'file' => UploadedFile::fake()->image('admin.png'),
            ])
            ->assertStatus(201);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

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

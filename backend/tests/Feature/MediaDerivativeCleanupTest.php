<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Services\BadgeImageService;
use App\Services\EventTypeMediaService;
use App\Services\MandantMediaService;
use App\Services\MediaPathService;
use App\Services\MediaStorage;
use App\Services\TeamMediaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W11 self-cleaning derivative cache — synchronous cascade.
 *
 * Every delete path (service `destroy()`/`purge()`, entity delete, mandant
 * delete) must remove the `.webp` sibling together with the original, so a
 * deleted entity never leaves orphaned derivatives on the public media disk.
 */
class MediaDerivativeCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake(MediaPathService::DISK);
        Storage::fake(MediaStorage::LEGACY_DISK);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    public function test_mandant_brand_destroy_removes_logo_header_and_derivatives(): void
    {
        $service = app(MandantMediaService::class);

        $service->store($this->mandant, 'logo', UploadedFile::fake()->image('logo.png'));
        $service->store($this->mandant, 'header', UploadedFile::fake()->image('header.png'));

        foreach (['logo', 'header'] as $kind) {
            Storage::disk(MediaPathService::DISK)->assertExists("verband-a.test/{$kind}.webp");
        }

        $service->destroy($this->mandant, 'logo');
        $service->destroy($this->mandant, 'header');

        foreach (['logo', 'header'] as $kind) {
            Storage::disk(MediaPathService::DISK)->assertMissing("verband-a.test/{$kind}.png");
            Storage::disk(MediaPathService::DISK)->assertMissing("verband-a.test/{$kind}.webp");
        }

        $this->assertNull($this->mandant->fresh()->logo_path);
        $this->assertNull($this->mandant->fresh()->header_path);
    }

    public function test_team_destroy_removes_the_webp_derivative(): void
    {
        $service = app(TeamMediaService::class);
        $team = Team::factory()->create(['mandant_id' => $this->mandant->id, 'slug' => 'team-a', 'name' => 'Team A']);

        $service->store($team, UploadedFile::fake()->image('logo.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/teams/team-a/logo.webp');
        $service->destroy($team);

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/team-a/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/teams/team-a/logo.webp');
        $this->assertNull($team->fresh()->logo_path);
    }

    public function test_event_type_purge_removes_the_webp_derivative(): void
    {
        $service = app(EventTypeMediaService::class);
        $eventType = EventType::query()->create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);

        $service->store($eventType, UploadedFile::fake()->image('logo.png'));
        Storage::disk(MediaPathService::DISK)->assertExists('verband-a.test/event-types/bundesliga/logo.webp');
        $service->purge($eventType);
        $eventType->delete();

        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/event-types/bundesliga/logo.png');
        Storage::disk(MediaPathService::DISK)->assertMissing('verband-a.test/event-types/bundesliga/logo.webp');
    }

    public function test_badge_image_destroy_removes_the_webp_derivative(): void
    {
        $service = app(BadgeImageService::class);

        $image = $service->store($this->mandant, UploadedFile::fake()->image('wappen.png'));
        $sibling = (string) app(MediaStorage::class)->webpSiblingPath($image->path);

        $this->assertNotSame('', $sibling);
        Storage::disk(MediaPathService::DISK)->assertExists($image->path);
        Storage::disk(MediaPathService::DISK)->assertExists($sibling);

        $service->destroy($image);

        Storage::disk(MediaPathService::DISK)->assertMissing($image->path);
        Storage::disk(MediaPathService::DISK)->assertMissing($sibling);
        $this->assertDatabaseMissing('badge_images', ['id' => $image->id]);
    }

    public function test_mandant_delete_purges_brand_media_before_the_row_disappears(): void
    {
        $target = Mandant::factory()->create(['slug' => 'verband-c', 'name' => 'Verband C']);
        $target->domains()->create(['hostname' => 'verband-c.test']);

        $media = app(MandantMediaService::class);
        $media->store($target, 'logo', UploadedFile::fake()->image('logo.png'));

        $path = (string) $target->fresh()->logo_path;
        $sibling = (string) app(MediaStorage::class)->webpSiblingPath($path);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$target->id)
            ->assertStatus(204);

        Storage::disk(MediaPathService::DISK)->assertMissing($path);
        Storage::disk(MediaPathService::DISK)->assertMissing($sibling);
    }

    public function test_mandant_delete_purges_event_type_and_badge_media(): void
    {
        $target = Mandant::factory()->create(['slug' => 'verband-d', 'name' => 'Verband D']);
        $target->domains()->create(['hostname' => 'verband-d.test']);

        $eventType = EventType::query()->create([
            'mandant_id' => $target->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);
        app(EventTypeMediaService::class)->store($eventType, UploadedFile::fake()->image('logo.png'));

        $badge = app(BadgeImageService::class)->store($target, UploadedFile::fake()->image('wappen.png'));

        $eventLogo = (string) $eventType->fresh()->logo_path;
        $badgePath = $badge->path;
        $storage = app(MediaStorage::class);

        $this->assertNotSame('', $eventLogo);
        Storage::disk(MediaPathService::DISK)->assertExists($eventLogo);
        Storage::disk(MediaPathService::DISK)->assertExists($badgePath);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/mandants/'.$target->id)
            ->assertStatus(204);

        // The DB cascade removes the rows; the files (and their .webp siblings)
        // must already be gone synchronously — no weekly prune needed.
        Storage::disk(MediaPathService::DISK)->assertMissing($eventLogo);
        Storage::disk(MediaPathService::DISK)->assertMissing((string) $storage->webpSiblingPath($eventLogo));
        Storage::disk(MediaPathService::DISK)->assertMissing($badgePath);
        Storage::disk(MediaPathService::DISK)->assertMissing((string) $storage->webpSiblingPath($badgePath));
        $this->assertDatabaseMissing('event_types', ['id' => $eventType->id]);
        $this->assertDatabaseMissing('badge_images', ['id' => $badge->id]);
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

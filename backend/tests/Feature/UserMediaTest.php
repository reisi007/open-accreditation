<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\User;
use App\Models\UserMedia;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserMediaTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->mandant = Mandant::factory()->create(['slug' => 'verband']);
        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_media_endpoints_require_authentication(): void
    {
        $this->getJson('/api/user/media')->assertStatus(401);
        $this->post('/api/user/media', [
            'type' => 'portrait',
            'file' => UploadedFile::fake()->image('portrait.jpg'),
        ])->assertStatus(401);
    }

    public function test_upload_stores_portrait_on_private_disk(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.png'),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'portrait');

        $media = UserMedia::findOrFail($response->json('data.id'));

        $this->assertSame($user->id, $media->user_id);
        $this->assertSame('image/png', $media->mime);
        $this->assertStringStartsWith('user-media/verband/'.$user->id.'/portrait/', $media->path);

        Storage::disk('private')->assertExists($media->path);
        $this->assertTrue($user->hasMedia('portrait'));
    }

    public function test_upload_rejects_unknown_type(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'video',
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_upload_rejects_oversized_file(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'attachment',
                'file' => UploadedFile::fake()->image('huge.jpg')->size(11000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('user_media', 0);
    }

    public function test_upload_rejects_image_exceeding_dimension_limit(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('big.png', 2001, 2001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('user_media', 0);
    }

    public function test_upload_accepts_images_up_to_the_dimension_limit(): void
    {
        $user = User::factory()->create();

        $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('ok.png', 2000, 2000),
            ])
            ->assertStatus(201);
    }

    public function test_portrait_upload_replaces_the_previous_portrait(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait-a.jpg'),
            ]);

        $firstMedia = UserMedia::findOrFail($first->json('data.id'));

        $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait-b.jpg'),
            ])
            ->assertStatus(201);

        $this->assertSame(1, UserMedia::where('user_id', $user->id)->where('type', 'portrait')->count());

        Storage::disk('private')->assertMissing($firstMedia->path);
    }

    public function test_attachment_allows_multiple_files(): void
    {
        $user = User::factory()->create();

        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $file) {
            $this->actingAsApi($user)
                ->post('/api/user/media', [
                    'type' => 'attachment',
                    'file' => UploadedFile::fake()->image($file),
                ])
                ->assertStatus(201);
        }

        $this->assertSame(3, UserMedia::where('user_id', $user->id)->where('type', 'attachment')->count());
    }

    public function test_owner_can_deliver_media_inline(): void
    {
        $user = User::factory()->create();

        $upload = $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'press_id',
                'file' => UploadedFile::fake()->image('press-id.jpg'),
            ]);

        $media = UserMedia::findOrFail($upload->json('data.id'));

        $this->actingAsApi($user)
            ->getJson('/api/user/media/'.$media->id)
            ->assertOk()
            ->assertHeader('Content-Type', $media->mime)
            ->assertHeaderContains('Content-Disposition', 'inline');
    }

    public function test_foreign_user_cannot_deliver_media(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $upload = $this->actingAsApi($owner)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ]);

        $media = UserMedia::findOrFail($upload->json('data.id'));

        $this->actingAsApi($other)
            ->getJson('/api/user/media/'.$media->id)
            ->assertStatus(403);
    }

    public function test_owner_can_delete_media(): void
    {
        $user = User::factory()->create();

        $upload = $this->actingAsApi($user)
            ->post('/api/user/media', [
                'type' => 'attachment',
                'file' => UploadedFile::fake()->image('doc.jpg'),
            ]);

        $media = UserMedia::findOrFail($upload->json('data.id'));

        $this->actingAsApi($user)
            ->deleteJson('/api/user/media/'.$media->id)
            ->assertOk();

        $this->assertDatabaseMissing('user_media', ['id' => $media->id]);
        Storage::disk('private')->assertMissing($media->path);
    }

    public function test_foreign_user_cannot_delete_media(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $upload = $this->actingAsApi($owner)
            ->post('/api/user/media', [
                'type' => 'attachment',
                'file' => UploadedFile::fake()->image('doc.jpg'),
            ]);

        $media = UserMedia::findOrFail($upload->json('data.id'));

        $this->actingAsApi($other)
            ->deleteJson('/api/user/media/'.$media->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('user_media', ['id' => $media->id]);
        Storage::disk('private')->assertExists($media->path);
    }

    public function test_index_lists_only_own_media(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $upload = $this->actingAsApi($owner)
            ->post('/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ]);

        $media = UserMedia::findOrFail($upload->json('data.id'));

        $this->actingAsApi($other)
            ->getJson('/api/user/media')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsApi($owner)
            ->getJson('/api/user/media')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $media->id)
            ->assertJsonPath('data.0.url', $media->url());
    }
}

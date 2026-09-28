<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\BadgeTemplate;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\BadgePhotoPlaceholder;
use App\Services\BadgeRenderService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The fallback for a `photo` badge entry whose application has no portrait
 * (features/badge-template-editor.md, "Platzhalter für ein fehlendes Porträt").
 *
 * Three things are nailed here, in both directions:
 *
 * 1. **RENDER.** No portrait → the bundled person silhouette is printed in the
 *    photo box, sized like `contain` and centered. A portrait → the portrait, and
 *    *not* the icon. The second direction is the one that regresses: a fallback
 *    that is applied unconditionally is the classic bug here, so the silhouette's
 *    data URI is asserted to be ABSENT whenever a real portrait is embedded.
 * 2. **ONE SOURCE.** The PDF embeds the very bytes the editor is served, and both
 *    come from the one file in the repository. Proven by comparing the decoded
 *    base64 payload of the card markup and the HTTP response against the file on
 *    disk — not by asserting that two literals happen to be equal.
 * 3. **DELIVERY.** The asset is a build artifact, but it is still delivered
 *    auth-gated through the admin surface (AGENTS.md §11) and it is not reachable
 *    from the web root. It carries no tenant data, which is asserted rather than
 *    claimed: two mandants get byte-identical responses.
 */
class BadgePhotoPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private BadgeRenderService $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        MandantContext::set($this->mandantA);
        $this->renderer = app(BadgeRenderService::class);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The bundled asset itself
     | ------------------------------------------------------------------- */

    public function test_the_bundled_asset_is_a_png_outside_the_web_root(): void
    {
        $placeholder = app(BadgePhotoPlaceholder::class);

        $this->assertFileExists($placeholder->path());

        $size = getimagesize($placeholder->path());
        $this->assertNotFalse($size, 'the bundled placeholder must be a real image');
        $this->assertSame('image/png', $size['mime']);
        // Square: the renderer derives the contain geometry from the box, and a
        // square asset is what makes that arithmetic correct.
        $this->assertSame($size[0], $size[1]);

        // AGENTS.md §11: no public path. `public/` is served by Caddy/Laravel
        // without authentication; the asset must never end up there.
        $this->assertStringStartsNotWith(realpath(public_path()).DIRECTORY_SEPARATOR, realpath($placeholder->path()));
    }

    /* ---------------------------------------------------------------------
     | Direction 1 — no portrait → the placeholder
     | ------------------------------------------------------------------- */

    public function test_photo_without_a_portrait_renders_the_placeholder_in_its_box(): void
    {
        $html = $this->cardHtml(['x' => 8, 'y' => 30, 'w' => 30, 'h' => 30], $this->approvedApplication());

        $this->assertStringContainsString(
            '<div style="position:absolute;left:8.00mm;top:30.00mm;width:30.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;overflow:hidden;"><img src="data:image/png;base64,',
            $html,
        );

        // The embedded bytes are the bundled file — byte for byte, not "some png".
        $this->assertSame(
            file_get_contents(app(BadgePhotoPlaceholder::class)->path()),
            $this->decodedPngPayloads($html)[0],
        );
    }

    public function test_photo_row_without_a_stored_file_falls_back_to_the_placeholder(): void
    {
        // A portrait row whose file vanished (deleted outside the app, lost
        // migration): the same fallback as "never had a portrait".
        $application = $this->approvedApplication();
        UserMedia::create([
            'user_id' => $application->user->id,
            'type' => 'portrait',
            'path' => 'user-media/gone/portrait.png',
            'mime' => 'image/png',
            'size' => 10,
            'original_name' => 'portrait.png',
        ]);

        $html = $this->cardHtml(['x' => 8, 'y' => 30, 'w' => 30, 'h' => 30], $application);

        $this->assertStringContainsString(
            'data:image/png;base64,',
            $html,
        );
        $this->assertSame(
            file_get_contents(app(BadgePhotoPlaceholder::class)->path()),
            $this->decodedPngPayloads($html)[0],
        );
    }

    public function test_the_placeholder_is_squared_and_centered_in_a_non_square_box(): void
    {
        // dompdf implements no `object-fit` at all (measured: a card rendered with
        // `object-fit: contain` and one without it are byte-identical PDFs), so
        // the contain geometry is computed in mm — otherwise a 25 × 30 mm box
        // would stretch the silhouette into a wide blob.
        $html = $this->cardHtml(['x' => 5, 'y' => 25, 'w' => 25, 'h' => 30], $this->approvedApplication());

        $this->assertStringContainsString(
            'style="position:absolute;left:0.00mm;top:2.50mm;width:25.00mm;height:25.00mm;object-fit:contain;"',
            $html,
        );
    }

    public function test_a_missing_bundled_asset_falls_back_to_the_empty_box_instead_of_breaking_the_card(): void
    {
        // A deployment defect must not take down a badge export: the card still
        // prints, with the historical empty box in the photo position.
        $this->app->instance(BadgePhotoPlaceholder::class, new BadgePhotoPlaceholder('img/badge/not-shipped.png'));
        $renderer = app(BadgeRenderService::class);

        $application = $this->approvedApplication();
        $template = $this->template(['x' => 8, 'y' => 30, 'w' => 30, 'h' => 30]);

        $this->assertStringContainsString(
            '<div style="position:absolute;left:8.00mm;top:30.00mm;width:30.00mm;height:30.00mm;'
            .'font-size:12pt;text-align:left;"></div>',
            $renderer->cardHtml($application, $template),
        );

        // …and the rest of the card is untouched: the PDF still renders.
        $this->assertStringStartsWith('%PDF-', $renderer->renderPdf([$application], $template));
    }

    /* ---------------------------------------------------------------------
     | Direction 2 — a portrait → the portrait, and NO icon
     | ------------------------------------------------------------------- */

    public function test_photo_with_a_portrait_renders_the_portrait_and_not_the_placeholder(): void
    {
        $application = $this->approvedApplication();
        $portraitBytes = $this->storePortrait($application->user, [40, 90, 160]);

        $html = $this->cardHtml(['x' => 8, 'y' => 30, 'w' => 30, 'h' => 30], $application);

        // The portrait is embedded with `cover` geometry (the photo default):
        // the 60 × 80 portrait fills the square 30 × 30 box and is cropped
        // 5.00 mm top and bottom rather than stretched — dompdf ignores
        // `object-fit`, so `cover` is millimetres, not a declaration.
        $this->assertStringContainsString(
            '<img src="data:image/png;base64,'.base64_encode($portraitBytes).'"'
            .' style="position:absolute;left:0.00mm;top:-5.00mm;width:30.00mm;height:40.00mm;object-fit:cover;">',
            $html,
        );

        // The regression this test exists for: the icon must NOT be there when
        // there is a real portrait. Compared by decoded bytes, so a future
        // re-encoding of the asset cannot make this assertion vacuous.
        $placeholderBytes = file_get_contents(app(BadgePhotoPlaceholder::class)->path());
        foreach ($this->decodedPngPayloads($html) as $payload) {
            $this->assertNotSame($placeholderBytes, $payload, 'the placeholder leaked into a card that has a portrait');
        }
    }

    /* ---------------------------------------------------------------------
     | Delivery — auth-gated, tenant-independent, one source with the PDF
     | ------------------------------------------------------------------- */

    public function test_the_placeholder_is_served_auth_gated_to_the_admin_surface(): void
    {
        $url = '/api/admin/badge-assets/photo-placeholder';

        $this->getJson($url)->assertStatus(401);

        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $this->actingAsApi($this->userWithRole($role->value, $this->mandantA->id))
                ->getJson($url)
                ->assertStatus(403, "expected 403 for {$role->value}");
        }
    }

    public function test_the_delivered_bytes_are_the_bundled_file(): void
    {
        $response = $this->actingAsApi($this->userWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id))
            ->get('/api/admin/badge-assets/photo-placeholder')
            ->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame(
            file_get_contents(app(BadgePhotoPlaceholder::class)->path()),
            $response->getContent(),
        );
    }

    public function test_the_delivered_bytes_are_identical_for_two_mandants(): void
    {
        // The asset holds no tenant data — asserted, not claimed. Were a
        // mandant-specific variant ever introduced here, this test fails.
        $url = '/api/admin/badge-assets/photo-placeholder';

        $first = $this->actingAsApi($this->userWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id))
            ->get($url)
            ->assertOk();

        MandantContext::set($this->mandantB);
        $second = $this->actingAsApi($this->userWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantB->id))
            ->get($url)
            ->assertOk();

        $this->assertSame(
            $first->getContent(),
            $second->getContent(),
        );
    }

    public function test_the_pdf_and_the_editor_embed_the_same_bytes(): void
    {
        // The single-source claim, proven across the two consumers: the payload
        // in the card markup and the payload over HTTP are the same file.
        $url = '/api/admin/badge-assets/photo-placeholder';

        $html = $this->cardHtml(['x' => 8, 'y' => 30, 'w' => 30, 'h' => 30], $this->approvedApplication());
        $served = $this->actingAsApi($this->userWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id))
            ->get($url)
            ->assertOk();

        $this->assertSame(
            $this->decodedPngPayloads($html)[0],
            $served->getContent(),
        );
    }

    public function test_the_asset_is_not_reachable_through_the_web_root(): void
    {
        $this->actingAsApi($this->userWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id))
            ->get('/'.BadgePhotoPlaceholder::RELATIVE_PATH)
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * @param  array<string, float|int>  $geometry
     */
    private function cardHtml(array $geometry, Application $application): string
    {
        return $this->renderer->cardHtml($application, $this->template($geometry));
    }

    /**
     * @param  array<string, float|int>  $geometry
     */
    private function template(array $geometry): BadgeTemplate
    {
        return BadgeTemplate::create([
            'mandant_id' => $this->mandantA->id,
            'name' => 'Presseausweis',
            'layout' => [['field' => 'photo', 'size' => 12, 'align' => 'left', ...$geometry]],
            'is_default' => false,
        ]);
    }

    /**
     * Every Base64 PNG payload of the markup, decoded.
     *
     * @return list<string>
     */
    private function decodedPngPayloads(string $html): array
    {
        preg_match_all('/src="data:image\/png;base64,([A-Za-z0-9+\/=]+)"/', $html, $matches);

        return array_map(
            static fn (string $payload): string => (string) base64_decode($payload, true),
            $matches[1],
        );
    }

    private function approvedApplication(): Application
    {
        $category = $this->mandantA->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse',
        ]);
        $accreditation = $this->mandantA->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);
        $user = User::factory()->create(['name' => 'Jane Doe']);

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    /**
     * A real decodable PNG on the private disk, registered as the user's
     * `portrait` media row.
     *
     * @param  array{int, int, int}  $fill
     */
    private function storePortrait(User $user, array $fill): string
    {
        $image = imagecreatetruecolor(60, 80);
        imagefilledrectangle($image, 0, 0, 59, 79, imagecolorallocate($image, $fill[0], $fill[1], $fill[2]));
        imagefilledellipse($image, 30, 30, 30, 30, imagecolorallocate($image, 230, 200, 150));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        $path = 'user-media/'.self::class.'/portrait-'.substr(sha1($bytes), 0, 8).'.png';
        Storage::disk('private')->put($path, $bytes);

        UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => $path,
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'original_name' => 'portrait.png',
        ]);

        return $bytes;
    }

    private function userWithRole(string $roleSlug, int $mandantId): User
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

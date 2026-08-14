<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Mandant;
use App\Models\SubApplication;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * P6 — wallet pass download endpoints.
 *
 *   GET /api/applications/{application}/wallet           Apple .pkpass
 *   GET /api/applications/{application}/wallet/google    Google payload
 *   GET /api/sub-applications/{subApplication}/wallet    Apple .pkpass (Park-/Sitzkarte)
 *
 * All three sit behind `auth:api` (guest → 401), are scoped to the owner +
 * current mandant (foreign user or mandant → 404) and only serve `approved`
 * applications (anything else → 422 `{message}`). The Apple responses stream
 * `application/vnd.apple.pkpass` attachments with a structurally valid
 * unsigned bundle (no `signature` file without certificates).
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        config()->set([
            'wallet.google.issuer_id' => null,
            'wallet.google.service_account_email' => null,
            'wallet.google.service_account_key' => null,
        ]);
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Auth + ownership + status guards
     | ------------------------------------------------------------------- */

    public function test_wallet_endpoints_require_authentication(): void
    {
        $user = User::factory()->create();
        $application = $this->approvedApplication($this->mandantA, $user);
        $sub = $this->approvedSubApplication($this->mandantA, $user, 'park');

        $this->getJson('/api/applications/'.$application->id.'/wallet')->assertStatus(401);
        $this->getJson('/api/applications/'.$application->id.'/wallet/google')->assertStatus(401);
        $this->getJson('/api/sub-applications/'.$sub->id.'/wallet')->assertStatus(401);
    }

    public function test_foreign_application_is_404(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $application = $this->approvedApplication($this->mandantA, $owner);

        $this->actingAsApi($stranger)
            ->get('/api/applications/'.$application->id.'/wallet')
            ->assertStatus(404);

        $this->actingAsApi($stranger)
            ->getJson('/api/applications/'.$application->id.'/wallet/google')
            ->assertStatus(404);
    }

    public function test_not_approved_application_is_422(): void
    {
        $user = User::factory()->create();

        foreach (['requested', 'denied', 'blacklisted'] as $status) {
            $application = $this->approvedApplication($this->mandantA, $user, [], ['status' => $status]);

            $this->actingAsApi($user)
                ->get('/api/applications/'.$application->id.'/wallet')
                ->assertStatus(422)
                ->assertJsonPath('message', 'Only approved applications can be downloaded as a wallet pass.');

            $this->actingAsApi($user)
                ->getJson('/api/applications/'.$application->id.'/wallet/google')
                ->assertStatus(422);
        }
    }

    public function test_application_of_foreign_mandant_is_404(): void
    {
        $user = User::factory()->create();
        $foreign = $this->approvedApplication($this->mandantB, $user);

        $this->actingAsApi($user)
            ->get('/api/applications/'.$foreign->id.'/wallet')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Apple download
     | ------------------------------------------------------------------- */

    public function test_apple_wallet_downloads_a_pkpass_for_own_approved_application(): void
    {
        $user = User::factory()->create();
        $application = $this->approvedApplication($this->mandantA, $user);

        $response = $this->actingAsApi($user)
            ->get('/api/applications/'.$application->id.'/wallet');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.pkpass')
            ->assertHeaderContains('Content-Disposition', 'attachment');

        $files = $this->unzip($response->getContent());

        $this->assertArrayHasKey('pass.json', $files);
        $this->assertArrayHasKey('icon.png', $files);
        $this->assertArrayHasKey('icon@2x.png', $files);
        $this->assertArrayHasKey('manifest.json', $files);
        $this->assertArrayNotHasKey('signature', $files);
    }

    public function test_apple_wallet_barcode_is_the_verify_url(): void
    {
        $user = User::factory()->create();
        $application = $this->approvedApplication($this->mandantA, $user);

        $response = $this->actingAsApi($user)
            ->get('/api/applications/'.$application->id.'/wallet');

        $files = $this->unzip($response->getContent());
        $pass = json_decode((string) $files['pass.json'], true);

        $this->assertSame(
            'https://verband-a.test/verify/'.$application->fresh()->qr_token,
            $pass['barcode']['message'],
        );
        $this->assertSame('PKBarcodeFormatQR', $pass['barcode']['format']);
        $this->assertSame('main-'.$application->id, $pass['serialNumber']);
    }

    /* ---------------------------------------------------------------------
     | Google download
     | ------------------------------------------------------------------- */

    public function test_google_wallet_downloads_the_event_ticket_object(): void
    {
        config()->set('wallet.google.issuer_id', '3388000000000000');

        $user = User::factory()->create();
        $application = $this->approvedApplication($this->mandantA, $user);

        $response = $this->actingAsApi($user)
            ->get('/api/applications/'.$application->id.'/wallet/google');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');

        $object = $response->json();

        $this->assertSame('3388000000000000.accriditation.main-'.$application->id, $object['id']);
        $this->assertSame('3388000000000000.accriditation', $object['classId']);
        $this->assertSame('Verband A', $object['issuerName']);
        $this->assertSame('QR_CODE', $object['barcode']['type']);
        $this->assertSame(
            'https://verband-a.test/verify/'.$application->fresh()->qr_token,
            $object['barcode']['value'],
        );
    }

    /* ---------------------------------------------------------------------
     | Sub-applications
     | ------------------------------------------------------------------- */

    public function test_sub_wallet_downloads_a_pkpass_for_own_approved_sub(): void
    {
        $user = User::factory()->create();

        foreach (['park', 'seat'] as $type) {
            $sub = $this->approvedSubApplication($this->mandantA, $user, $type);

            $response = $this->actingAsApi($user)
                ->get('/api/sub-applications/'.$sub->id.'/wallet');

            $response->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.apple.pkpass');

            $files = $this->unzip($response->getContent());
            $pass = json_decode((string) $files['pass.json'], true);

            $this->assertSame($type.'-'.$sub->id, $pass['serialNumber']);
            $this->assertSame($type === 'park' ? 'Parkkarte' : 'Sitzkarte', $pass['eventTicket']['auxiliaryFields'][1]['value']);
        }
    }

    public function test_foreign_sub_application_is_404(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $sub = $this->approvedSubApplication($this->mandantA, $owner, 'park');

        $this->actingAsApi($stranger)
            ->get('/api/sub-applications/'.$sub->id.'/wallet')
            ->assertStatus(404);
    }

    public function test_not_approved_sub_application_is_422(): void
    {
        $user = User::factory()->create();
        $sub = $this->approvedSubApplication($this->mandantA, $user, 'park', ['status' => 'requested']);

        $this->actingAsApi($user)
            ->get('/api/sub-applications/'.$sub->id.'/wallet')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only approved sub-applications can be downloaded as a wallet pass.');
    }

    public function test_sub_application_of_foreign_mandant_is_404(): void
    {
        $user = User::factory()->create();
        $foreign = $this->approvedSubApplication($this->mandantB, $user, 'park');

        $this->actingAsApi($user)
            ->get('/api/sub-applications/'.$foreign->id.'/wallet')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function approvedApplication(Mandant $mandant, User $user, array $accAttributes = [], array $appAttributes = []): Application
    {
        $category = $mandant->categories()->create(['name' => 'Presse', 'slug' => 'presse-'.bin2hex(random_bytes(4))]);
        $event = $mandant->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $mandant->accreditations()->create([
            'category_id' => $category->id,
            'event_id' => $event->id,
            'scope' => 'event',
            'quota' => 5,
            'deadline_end' => '2026-08-20',
            ...$accAttributes,
        ]);

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
            ...$appAttributes,
        ]);
    }

    private function approvedSubApplication(Mandant $mandant, User $user, string $type, array $subAttributes = []): SubApplication
    {
        $application = $this->approvedApplication($mandant, $user);
        $sub = $application->accreditation->subAccreditations()->create([
            'type' => $type,
            'quota' => 5,
        ]);

        return SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
            ...$subAttributes,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pkpass');
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($tmp);

        return $files;
    }
}

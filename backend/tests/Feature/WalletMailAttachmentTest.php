<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ApplicationApprovedMail;
use App\Mail\PassMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\WalletPassService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use ZipArchive;

/**
 * P6 — the wallet passes as attachments of the approval mails.
 *
 * The user decision (2026-10-02) is "pass as mail attachment, ADDITIONALLY to
 * the download endpoint". An automated Wallet import is not E2E-testable, so
 * what is pinned here is the **validity of the produced file** — read from the
 * attachment that actually rides along with the sent mailable, never from the
 * `WalletPassService` in isolation (`WalletPassServiceTest` already owns the
 * pure contract).
 *
 * Covered:
 *  - Apple: the attached bytes are a valid ZIP with `pass.json`, `icon.png`,
 *    `icon@2x.png`, `manifest.json`; the manifest hashes match the files;
 *    without certificates the ZIP contains NO `signature`.
 *  - Google: the preview `EventTicketObject` JSON without credentials and the
 *    RS256 `savetowallet` JWT (`typ`/`aud`) with a service account.
 *  - The download endpoint and the attachment share ONE name/MIME contract.
 *  - Fail-safe: a pass build failure is logged and skipped, the mail survives.
 *  - A non-approved application yields no pass.
 */
class WalletMailAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private AllocationService $allocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);

        $this->allocation = app(AllocationService::class);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        config()->set([
            'wallet.apple.cert' => null,
            'wallet.apple.key' => null,
            'wallet.apple.key_password' => null,
            'wallet.apple.wwdr' => null,
            'wallet.google.issuer_id' => null,
            'wallet.google.service_account_email' => null,
            'wallet.google.service_account_key' => null,
        ]);

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Apple attachment
     | ------------------------------------------------------------------- */

    public function test_approval_mail_attaches_a_valid_unsigned_pkpass_and_the_google_preview(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);

        $this->allocation->approveApplication($application);

        $named = $this->attachmentsByName(Mail::sent(ApplicationApprovedMail::class)->sole());

        $this->assertSame([
            'accreditation-'.$application->id.'.pkpass',
            'accreditation-'.$application->id.'.json',
        ], array_keys($named));

        $apple = $named['accreditation-'.$application->id.'.pkpass'];

        // The attachment declares the contract MIME of the download endpoint.
        $this->assertSame(WalletPassService::APPLE_CONTENT_TYPE, $apple['mime']);

        $this->assertValidUnsignedPkpass($apple['data'], $application);
    }

    public function test_google_preview_attachment_is_an_event_ticket_object(): void
    {
        config()->set('wallet.google.issuer_id', '3388000000000000');

        Mail::fake();

        $user = User::factory()->create(['name' => 'Jane Doe']);
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);

        $this->allocation->approveApplication($application);

        $named = $this->attachmentsByName(Mail::sent(ApplicationApprovedMail::class)->sole());

        $google = $named['accreditation-'.$application->id.'.json'];
        $this->assertSame(WalletPassService::GOOGLE_CONTENT_TYPE, $google['mime']);

        $object = json_decode($google['data'], true);

        $this->assertIsArray($object);
        $this->assertSame('3388000000000000.accriditation', $object['classId']);
        $this->assertSame('3388000000000000.accriditation.main-'.$application->id, $object['id']);
        $this->assertSame('ACTIVE', $object['state']);
        $this->assertSame('Jane Doe', $object['ticketHolderName']);
        $this->assertSame('QR_CODE', $object['barcode']['type']);
    }

    /* ---------------------------------------------------------------------
     | Google JWT attachment
     | ------------------------------------------------------------------- */

    public function test_google_attachment_is_a_savetowallet_jwt_when_a_service_account_is_configured(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);

        config()->set('wallet.google.issuer_id', '3388000000000000');
        config()->set('wallet.google.service_account_email', 'sa@example.iam.gserviceaccount.com');
        config()->set('wallet.google.service_account_key', $privatePem);

        Mail::fake();

        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $this->allocation->approveApplication($application);

        $named = $this->attachmentsByName(Mail::sent(ApplicationApprovedMail::class)->sole());

        // Apple stays in the degraded (unsigned) mode — the credentials of the
        // two formats are independent.
        $this->assertValidUnsignedPkpass(
            $named['accreditation-'.$application->id.'.pkpass']['data'],
            $application,
        );

        $jwt = $named['accreditation-'.$application->id.'.json']['data'];
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        $this->assertSame('sa@example.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('google', $claims['aud']);
        $this->assertSame('savetowallet', $claims['typ']);
        $this->assertSame(
            '3388000000000000.accriditation.main-'.$application->id,
            $claims['payload']['eventTicketObjects'][0]['id'],
        );
    }

    /* ---------------------------------------------------------------------
     | The attachment reaches a real MIME message
     | ------------------------------------------------------------------- */

    public function test_attachments_reach_the_symfony_message_on_a_real_transport(): void
    {
        $application = $this->request(
            $this->createAccreditation(['quota' => 5]),
            User::factory()->create(['name' => 'Jane Doe']),
        );
        $application->update(['status' => 'approved']);

        $mailer = Mail::mailer('array');
        $mailer->send(new PassMail($application, 'https://verband-a.test/verify/token'));

        $message = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $attachments = $message->getAttachments();

        $byName = [];
        foreach ($attachments as $attachment) {
            $byName[$attachment->getFilename()] = $attachment;
        }

        $this->assertSame([
            'accreditation-'.$application->id.'.pkpass',
            'accreditation-'.$application->id.'.json',
        ], array_keys($byName));

        $apple = $byName['accreditation-'.$application->id.'.pkpass'];
        $this->assertSame(WalletPassService::APPLE_CONTENT_TYPE, $apple->getContentType());
        $this->assertValidUnsignedPkpass($apple->getBody(), $application);

        $this->assertSame(
            WalletPassService::GOOGLE_CONTENT_TYPE,
            $byName['accreditation-'.$application->id.'.json']->getContentType(),
        );
    }

    /* ---------------------------------------------------------------------
     | Both dispatch points carry the pass
     | ------------------------------------------------------------------- */

    public function test_resend_pass_mail_attaches_the_same_passes(): void
    {
        Mail::fake();

        $user = $this->createMember();
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);
        $this->allocation->approveApplication($application);

        Mail::fake();

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertOk();

        $named = $this->attachmentsByName(Mail::sent(PassMail::class)->sole());

        $this->assertSame([
            'accreditation-'.$application->id.'.pkpass',
            'accreditation-'.$application->id.'.json',
        ], array_keys($named));

        $this->assertValidUnsignedPkpass(
            $named['accreditation-'.$application->id.'.pkpass']['data'],
            $application,
        );
    }

    /* ---------------------------------------------------------------------
     | One contract: download endpoint and attachment
     | ------------------------------------------------------------------- */

    public function test_download_endpoint_and_attachment_share_name_and_mime(): void
    {
        // Position 45: `approveApplication` now queues the approval mail; under
        // `sync` it would dial a real relay. This test asserts the download
        // endpoint, not delivery.
        Mail::fake();

        $user = $this->createMember();
        $application = $this->request($this->createAccreditation(['quota' => 5]), $user);
        $this->allocation->approveApplication($application);

        $download = $this->actingAsApi($user)
            ->get('/api/applications/'.$application->id.'/wallet');

        $download->assertOk()
            ->assertHeader('Content-Type', WalletPassService::APPLE_CONTENT_TYPE)
            ->assertHeaderContains('Content-Disposition', 'filename="accreditation-'.$application->id.'.pkpass"');

        $googleDownload = $this->actingAsApi($user)
            ->getJson('/api/applications/'.$application->id.'/wallet/google');

        $googleDownload->assertOk()
            ->assertHeader('Content-Type', WalletPassService::GOOGLE_CONTENT_TYPE);
    }

    /* ---------------------------------------------------------------------
     | Edge cases
     | ------------------------------------------------------------------- */

    public function test_non_approved_application_yields_no_wallet_attachments(): void
    {
        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        $mail = new PassMail($application, 'https://verband-a.test/verify/token');

        $this->assertSame([], $mail->attachments());
        $this->assertSame([], $mail->walletAttachments);
    }

    public function test_apple_build_failure_is_logged_and_the_mail_still_sends(): void
    {
        // Three existing-but-invalid PEM files make the Apple signature path
        // throw — the one way to force a genuine build failure.
        $dir = $this->tempDir();
        $cert = $dir.'/cert.pem';
        file_put_contents($cert, "not a certificate\n");
        file_put_contents($dir.'/key.pem', "not a key\n");
        file_put_contents($dir.'/wwdr.pem', "not a wwdr\n");

        config()->set('wallet.apple.cert', $cert);
        config()->set('wallet.apple.key', $dir.'/key.pem');
        config()->set('wallet.apple.wwdr', $dir.'/wwdr.pem');

        Log::spy();
        Mail::fake();

        $application = $this->request($this->createAccreditation(['quota' => 5]), User::factory()->create());

        try {
            $this->allocation->approveApplication($application);

            // `Mail::fake()` does not invoke `attachments()`; build them while
            // the (invalid) certificates still exist on disk.
            $named = $this->attachmentsByName(Mail::sent(ApplicationApprovedMail::class)->sole());
        } finally {
            $this->deleteDir($dir);
        }

        // The mail survives; only the Apple attachment is missing, the Google
        // one is still there.
        $this->assertArrayNotHasKey('accreditation-'.$application->id.'.pkpass', $named);
        $this->assertArrayHasKey('accreditation-'.$application->id.'.json', $named);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Wallet pass could not be attached to the mail'
                && $context['attachment'] === 'accreditation-'.$application->id.'.pkpass')
            ->once();
    }

    /* ---------------------------------------------------------------------
     | Assertions
     | ------------------------------------------------------------------- */

    /**
     * @return array<string, array{name: string, mime: string, data: string}>
     */
    private function attachmentsByName(ApplicationApprovedMail|PassMail $mail): array
    {
        // `Mail::fake()` records the mailable without ever invoking
        // `attachments()`; calling it here is what builds (and mirrors) the
        // payloads — exactly what a real send does.
        $mail->attachments();

        $named = [];

        foreach ($mail->walletAttachments as $attachment) {
            $named[$attachment['name']] = $attachment;
        }

        return $named;
    }

    private function assertValidUnsignedPkpass(string $bytes, Application $application): void
    {
        $files = $this->unzip($bytes);

        $this->assertArrayHasKey('pass.json', $files);
        $this->assertArrayHasKey('icon.png', $files);
        $this->assertArrayHasKey('icon@2x.png', $files);
        $this->assertArrayHasKey('manifest.json', $files);

        // Without certificates the bundle stays UNSIGNED — no `signature`.
        $this->assertArrayNotHasKey('signature', $files);

        foreach (['icon.png', 'icon@2x.png'] as $icon) {
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $files[$icon]);
        }

        $manifest = json_decode((string) $files['manifest.json'], true);
        $this->assertIsArray($manifest);

        $hashed = array_keys($manifest);
        sort($hashed);
        $this->assertSame(['icon.png', 'icon@2x.png', 'pass.json'], $hashed);

        foreach ($manifest as $name => $hash) {
            $this->assertArrayHasKey($name, $files);
            $this->assertSame(hash('sha256', $files[$name]), $hash, "manifest hash mismatch for {$name}");
        }

        $pass = json_decode((string) $files['pass.json'], true);
        $this->assertIsArray($pass);
        $this->assertSame('main-'.$application->id, $pass['serialNumber']);
        $this->assertSame('name', $pass['eventTicket']['primaryFields'][0]['key']);
        $this->assertNotSame('', $pass['eventTicket']['primaryFields'][0]['value']);
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

    /* ---------------------------------------------------------------------
     | Fixtures
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private function createAccreditation(array $attributes = []): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function request(Accreditation $accreditation, User $user, array $attributes = []): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    private function createMember(): User
    {
        $user = User::factory()->forMandant($this->mandant)->create(['name' => 'Jane Doe']);
        $role = Role::query()->where('slug', UserRole::USER->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $this->mandant->id,
        ]);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => null,
        ]);

        return $user;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/wallet-mail-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($dir.'/'.$entry);
        }

        @rmdir($dir);
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

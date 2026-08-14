<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\Mandant;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\User;
use App\Services\WalletPassService;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

/**
 * P6 — WalletPassService unit tests.
 *
 * Covers the pure pass-building contract: the unsigned .pkpass bundle
 * (structure, manifest hashes, pass.json fields, verify-URL barcode), the
 * signed bundle when certificates are configured (self-signed PEM generated
 * in the test), the Google EventTicketObject JSON without credentials and
 * the RS256 JWT with a generated service-account key.
 */
class WalletPassServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletPassService $service;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletPassService::class);
        $this->mandant = Mandant::factory()->create(['name' => 'Verband A', 'slug' => 'verband-a']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        // Reset config mutations so no test leaks into the next one (same
        // PHPUnit process).
        config()->set([
            'wallet.apple.team_id' => null,
            'wallet.apple.organization_name' => null,
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
     | Apple — unsigned bundle structure
     | ------------------------------------------------------------------- */

    public function test_apple_bundle_is_a_valid_zip_without_signature(): void
    {
        $application = $this->application();

        $files = $this->unzip($this->service->buildApplePass($application, 'main'));

        $this->assertArrayHasKey('pass.json', $files);
        $this->assertArrayHasKey('icon.png', $files);
        $this->assertArrayHasKey('icon@2x.png', $files);
        $this->assertArrayHasKey('manifest.json', $files);
        $this->assertArrayNotHasKey('signature', $files);

        // Both icons are real PNGs (GD output).
        foreach (['icon.png', 'icon@2x.png'] as $icon) {
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $files[$icon]);
        }
    }

    public function test_apple_pass_json_contains_required_fields(): void
    {
        $application = $this->application();
        $pass = $this->applePass($this->service->buildApplePass($application, 'main'));

        $this->assertSame(1, $pass['formatVersion']);
        $this->assertSame('pass.accriditation.test', $pass['passTypeIdentifier']);
        $this->assertSame('main-'.$application->id, $pass['serialNumber']);
        $this->assertSame('Verband A', $pass['organizationName']);
        $this->assertSame('Presse · Finale', $pass['logoText']);
        $this->assertSame('rgb(20,20,20)', $pass['foregroundColor']);
        $this->assertSame('rgb(240,240,240)', $pass['backgroundColor']);

        $this->assertSame('Jane Doe', $pass['eventTicket']['primaryFields'][0]['value']);
        $this->assertSame('Presse', $pass['eventTicket']['secondaryFields'][0]['value']);
        $this->assertSame('Finale', $pass['eventTicket']['secondaryFields'][1]['value']);
        $this->assertSame('01.09.2026', $pass['eventTicket']['auxiliaryFields'][0]['value']);
        $this->assertSame('Akkreditiert', $pass['eventTicket']['auxiliaryFields'][1]['value']);

        // Barcode: QR over the verify URL (host chain — mandant domain).
        $token = $application->fresh()->qr_token;
        $this->assertNotNull($token);
        $this->assertSame('https://verband-a.test/verify/'.$token, $pass['barcode']['message']);
        $this->assertSame('PKBarcodeFormatQR', $pass['barcode']['format']);
        $this->assertSame('utf-8', $pass['barcode']['messageEncoding']);
    }

    public function test_apple_pass_uses_config_overrides_when_configured(): void
    {
        config()->set('wallet.apple.team_id', 'TEAM123');
        config()->set('wallet.apple.organization_name', 'Org GmbH');

        $pass = $this->applePass($this->service->buildApplePass($this->application(), 'main'));

        $this->assertSame('TEAM123', $pass['teamIdentifier']);
        $this->assertSame('Org GmbH', $pass['organizationName']);
    }

    public function test_apple_pass_without_event_omits_event_fields_and_relevant_date_uses_deadline(): void
    {
        $category = $this->mandant->categories()->create(['name' => 'Presse', 'slug' => 'presse-nolig']);
        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            'deadline_end' => '2026-08-20',
        ]);
        $application = Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        $pass = $this->applePass($this->service->buildApplePass($application, 'main'));

        $this->assertCount(1, $pass['eventTicket']['secondaryFields']);
        $this->assertSame('Presse', $pass['logoText']);
        $this->assertSame('2026-08-20T00:00:00', substr((string) $pass['relevantDate'], 0, 19));
        $this->assertArrayHasKey('relevantDate', $pass);
    }

    public function test_manifest_hashes_match_file_contents(): void
    {
        $files = $this->unzip($this->service->buildApplePass($this->application(), 'main'));

        $manifest = json_decode((string) $files['manifest.json'], true);

        $this->assertIsArray($manifest);

        // Only files actually in the bundle are hashed — the manifest does
        // not hash itself (unsigned → no signature entry either).
        $hashed = array_keys($manifest);
        sort($hashed);
        $this->assertSame(['icon.png', 'icon@2x.png', 'pass.json'], $hashed);

        foreach ($manifest as $name => $hash) {
            $this->assertArrayHasKey($name, $files);
            $this->assertSame(hash('sha256', $files[$name]), $hash, "manifest hash mismatch for {$name}");
        }
    }

    /* ---------------------------------------------------------------------
     | Apple — signed bundle
     | ------------------------------------------------------------------- */

    public function test_apple_bundle_is_signed_when_certificates_are_configured(): void
    {
        $dir = $this->tempDir();

        try {
            $files = $this->signingFiles($dir);

            config()->set('wallet.apple.cert', $files['cert']);
            config()->set('wallet.apple.key', $files['key']);
            config()->set('wallet.apple.wwdr', $files['wwdr']);

            $bundle = $this->unzip($this->service->buildApplePass($this->application(), 'main'));

            $this->assertArrayHasKey('signature', $bundle);
            $this->assertStringStartsWith("\x30", $bundle['signature']);

            // Verify the detached PKCS#7 signature over the manifest by
            // rebuilding the `multipart/signed` envelope around the DER
            // signature (the format `openssl_pkcs7_sign` produces).
            $manifestFile = $dir.'/manifest.json';
            file_put_contents($manifestFile, $bundle['manifest.json']);

            $boundary = '----'.strtoupper(bin2hex(random_bytes(15)));
            $separator = '--'.$boundary;
            $signaturePart = $separator."\n"
                ."Content-Type: application/x-pkcs7-signature; name=\"smime.p7s\"\n"
                ."Content-Transfer-Encoding: base64\n"
                ."Content-Disposition: attachment; filename=\"smime.p7s\"\n\n"
                .chunk_split(base64_encode($bundle['signature']), 64, "\n")
                .$separator."--\n";
            $envelope = "MIME-Version: 1.0\n"
                .'Content-Type: multipart/signed; protocol="application/x-pkcs7-signature"; micalg="sha-256"; boundary="'.$boundary."\"\n\n"
                ."This is an S/MIME signed message\n\n"
                .$separator."\n".$bundle['manifest.json']."\n"
                .$signaturePart;
            $smimeFile = $dir.'/smime.p7s';
            file_put_contents($smimeFile, $envelope);

            $signersFile = $dir.'/signers.p7s';
            $verified = openssl_pkcs7_verify($smimeFile, PKCS7_NOVERIFY, $signersFile, [$files['cert']], null);

            $this->assertTrue($verified);

            // Negative control: a tampered manifest must fail verification.
            $tampered = str_replace($bundle['manifest.json'], $bundle['manifest.json'].'X', $envelope);
            file_put_contents($smimeFile, $tampered);
            $this->assertFalse(openssl_pkcs7_verify($smimeFile, PKCS7_NOVERIFY, $signersFile, [$files['cert']], null));
        } finally {
            $this->deleteDir($dir);
        }
    }

    /* ---------------------------------------------------------------------
     | Apple — sub-applications
     | ------------------------------------------------------------------- */

    public function test_apple_sub_bundle_uses_park_type_label(): void
    {
        $sub = $this->subApplication('park');
        $pass = $this->applePass($this->service->buildApplePass($sub, 'park'));

        $this->assertSame('park-'.$sub->id, $pass['serialNumber']);
        $this->assertSame('Parkkarte', $pass['eventTicket']['auxiliaryFields'][1]['value']);
        $this->assertSame('Typ', $pass['eventTicket']['auxiliaryFields'][1]['label']);
    }

    public function test_apple_sub_bundle_uses_seat_type_label(): void
    {
        $sub = $this->subApplication('seat');
        $pass = $this->applePass($this->service->buildApplePass($sub, 'seat'));

        $this->assertSame('seat-'.$sub->id, $pass['serialNumber']);
        $this->assertSame('Sitzkarte', $pass['eventTicket']['auxiliaryFields'][1]['value']);
        $this->assertSame('Sitzkarte Presse', $pass['description']);
    }

    public function test_apple_sub_barcode_verifies_the_linked_main_application(): void
    {
        $sub = $this->subApplication('park');
        $pass = $this->applePass($this->service->buildApplePass($sub, 'park'));

        $this->assertSame(
            'https://verband-a.test/verify/'.$sub->application->fresh()->qr_token,
            $pass['barcode']['message'],
        );
    }

    /* ---------------------------------------------------------------------
     | Type validation
     | ------------------------------------------------------------------- */

    public function test_invalid_type_is_rejected(): void
    {
        $application = $this->application();

        $this->expectException(InvalidArgumentException::class);
        $this->service->buildApplePass($application, 'banana');
    }

    public function test_main_type_is_rejected_for_sub_applications(): void
    {
        $sub = $this->subApplication('park');

        $this->expectException(InvalidArgumentException::class);
        $this->service->buildApplePass($sub, 'main');
    }

    /* ---------------------------------------------------------------------
     | Google — EventTicketObject JSON without credentials
     | ------------------------------------------------------------------- */

    public function test_google_pass_returns_object_json_without_credentials(): void
    {
        config()->set('wallet.google.issuer_id', '3388000000000000');

        $application = $this->application();
        $json = $this->service->buildGooglePass($application, 'main');

        $this->assertJson($json);
        $object = json_decode($json, true);

        $this->assertSame('3388000000000000.accriditation.main-'.$application->id, $object['id']);
        $this->assertSame('3388000000000000.accriditation', $object['classId']);
        $this->assertSame('main-'.$application->id, $object['passId']);
        $this->assertSame('ACTIVE', $object['state']);
        $this->assertSame('Verband A', $object['issuerName']);
        $this->assertSame('Jane Doe', $object['ticketHolderName']);
        $this->assertSame('Presse', $object['ticketType']);
        $this->assertSame('Finale', $object['eventName']['defaultValue']['value']);
        $this->assertSame('de', $object['eventName']['defaultValue']['language']);

        $this->assertSame(
            'https://verband-a.test/verify/'.$application->fresh()->qr_token,
            $object['barcode']['value'],
        );
        $this->assertSame('QR_CODE', $object['barcode']['type']);
    }

    public function test_google_text_modules_cover_all_fields(): void
    {
        $object = json_decode($this->service->buildGooglePass($this->application(), 'main'), true);

        $headers = array_column($object['textModulesData'], 'header');
        $this->assertSame(['Name', 'Kategorie', 'Event', 'Datum', 'Status'], $headers);

        $modules = array_column($object['textModulesData'], 'body', 'id');
        $this->assertSame('Jane Doe', $modules['name']);
        $this->assertSame('Presse', $modules['category']);
        $this->assertSame('Finale', $modules['event']);
        $this->assertSame('01.09.2026', $modules['date']);
        $this->assertSame('Akkreditiert', $modules['type']);
    }

    public function test_google_sub_pass_uses_park_type_in_text_modules(): void
    {
        $sub = $this->subApplication('park');
        $object = json_decode($this->service->buildGooglePass($sub, 'park'), true);

        $modules = array_column($object['textModulesData'], 'body', 'id');
        $this->assertSame('Parkkarte', $modules['type']);
    }

    /* ---------------------------------------------------------------------
     | Google — JWT with service account credentials
     | ------------------------------------------------------------------- */

    public function test_google_pass_returns_jwt_with_service_account(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);

        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        $publicPem = $details['key'];

        config()->set('wallet.google.issuer_id', '3388000000000000');
        config()->set('wallet.google.service_account_email', 'sa@example.iam.gserviceaccount.com');
        config()->set('wallet.google.service_account_key', $privatePem);

        $application = $this->application();
        $jwt = $this->service->buildGooglePass($application, 'main');

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        [$headerB64, $claimsB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $claims = json_decode($this->base64UrlDecode($claimsB64), true);
        $this->assertSame('sa@example.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('google', $claims['aud']);
        $this->assertSame('savetowallet', $claims['typ']);
        $this->assertIsInt($claims['iat']);

        $object = $claims['payload']['eventTicketObjects'][0];
        $this->assertSame('3388000000000000.accriditation.main-'.$application->id, $object['id']);
        $this->assertSame('QR_CODE', $object['barcode']['type']);

        $signature = $this->base64UrlDecode($signatureB64);
        $publicKey = openssl_pkey_get_public($publicPem);
        $this->assertSame(1, openssl_verify($headerB64.'.'.$claimsB64, $signature, $publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_google_service_account_key_accepts_a_pem_file_path(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);

        $path = $this->tempDir().'/sa-key.pem';
        file_put_contents($path, $privatePem);

        try {
            config()->set('wallet.google.service_account_email', 'sa@example.iam.gserviceaccount.com');
            config()->set('wallet.google.service_account_key', $path);

            $jwt = $this->service->buildGooglePass($this->application(), 'main');
            $this->assertCount(3, explode('.', $jwt));
        } finally {
            unlink($path);
            rmdir(dirname($path));
        }
    }

    public function test_google_service_account_key_accepts_a_jwk(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);

        $jwk = [
            'kty' => 'RSA',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
            'd' => $this->base64Url($details['rsa']['d']),
            'p' => $this->base64Url($details['rsa']['p']),
            'q' => $this->base64Url($details['rsa']['q']),
            // PHP exposes the CRT parameters under their OpenSSL names; JWK
            // uses the PKCS#1 terminology (dmp1=dp, dmq1=dq, iqmp=qi).
            'dp' => $this->base64Url($details['rsa']['dmp1']),
            'dq' => $this->base64Url($details['rsa']['dmq1']),
            'qi' => $this->base64Url($details['rsa']['iqmp']),
        ];

        config()->set('wallet.google.service_account_email', 'sa@example.iam.gserviceaccount.com');
        config()->set('wallet.google.service_account_key', (string) json_encode($jwk));

        $jwt = $this->service->buildGooglePass($this->application(), 'main');
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        // The JWK-derived key must produce a signature the original public
        // key accepts.
        $publicKey = openssl_pkey_get_public($details['key']);
        $this->assertSame(1, openssl_verify(
            $parts[0].'.'.$parts[1],
            $this->base64UrlDecode($parts[2]),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function application(): Application
    {
        $category = $this->mandant->categories()->create(['name' => 'Presse', 'slug' => 'presse-'.bin2hex(random_bytes(4))]);
        $event = $this->mandant->events()->create(['title' => 'Finale', 'date' => '2026-09-01']);
        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'event_id' => $event->id,
            'scope' => 'event',
            'quota' => 5,
            'deadline_end' => '2026-08-20',
        ]);

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create(['name' => 'Jane Doe'])->id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    private function subApplication(string $type): SubApplication
    {
        $application = $this->application();
        $sub = SubAccreditation::create([
            'accreditation_id' => $application->accreditation_id,
            'type' => $type,
            'quota' => 5,
            'deadline_end' => '2026-08-20',
        ]);

        return SubApplication::create([
            'sub_accreditation_id' => $sub->id,
            'application_id' => $application->id,
            'user_id' => $application->user_id,
            'status' => 'approved',
            'priority' => false,
        ]);
    }

    /**
     * @return array{pass: mixed, files: array<string, string>}
     */
    private function applePass(string $binary): array
    {
        $files = $this->unzip($binary);
        $pass = json_decode((string) $files['pass.json'], true);
        $this->assertIsArray($pass);

        return $pass;
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

    /**
     * Generate a self-signed cert + key pair used as Apple pass-signing
     * certificates (cert/key/wwdr all point to it).
     *
     * @return array{cert: string, key: string, wwdr: string}
     */
    private function signingFiles(string $dir): array
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL key generation unavailable.');
        }

        $csr = openssl_csr_new(['commonName' => 'wallet.test', 'organizationName' => 'Test Org'], $key, $config);

        if ($csr === false) {
            $this->markTestSkipped('OpenSSL CSR generation unavailable.');
        }

        $cert = openssl_csr_sign($csr, null, $key, 365, $config, random_int(0, PHP_INT_MAX));

        if ($cert === false) {
            $this->markTestSkipped('OpenSSL certificate signing unavailable.');
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        $certFile = $dir.'/cert.pem';
        $keyFile = $dir.'/key.pem';
        $wwdrFile = $dir.'/wwdr.pem';
        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);
        file_put_contents($wwdrFile, $certPem);

        return ['cert' => $certFile, 'key' => $keyFile, 'wwdr' => $wwdrFile];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/pkpass-test-'.bin2hex(random_bytes(6));
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

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

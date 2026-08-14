<?php

namespace App\Services;

use App\Models\Application;
use App\Models\SubApplication;
use App\Support\VerifyLink;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * P6 — Apple Wallet (.pkpass) and Google Wallet passes for approved
 * applications and sub-applications (Park-/Sitzkarte).
 *
 * Credentials degrade as documented in `config/wallet.php` — **no real
 * certificates/keys are ever committed**:
 *
 *   Apple:  without `WALLET_CERT`/`WALLET_KEY`/`WALLET_WWDR` the service
 *           builds an UNSIGNED .pkpass bundle. The structure (pass.json,
 *           icons, manifest.json, zip) is fully valid, the `signature` file
 *           is omitted — iOS refuses to install it, but the bundle stays
 *           usable for structure/debug tooling and the download contract.
 *           When all three cert files exist, the manifest is signed with
 *           `openssl_pkcs7_sign` (`PKCS7_BINARY | PKCS7_DETACHED`, DER
 *           payload extracted from the S/MIME output) into the `signature`
 *           file Apple expects.
 *   Google: without a service account the service answers the raw
 *           EventTicketObject JSON (a preview representation). With a
 *           service account email + key (PEM string, PEM path or JWK) it
 *           answers a JWT (RS256, `typ: savetowallet`), which is what the
 *           Google Wallet API requires.
 *
 * The pass data is derived from the application graph (user name, category,
 * event, date, status/type) and carries no secrets; the barcode encodes the
 * public verify URL (host chain via `VerifyLink`).
 */
final class WalletPassService
{
    private const ALLOWED_TYPES = ['main', 'park', 'seat'];

    public function __construct(private readonly QrTokenService $tokens) {}

    /**
     * Build the Apple Wallet pass binary (.pkpass ZIP) for one application
     * (`type = 'main'`) or sub-application (`type = 'park'|'seat'`).
     */
    public function buildApplePass(Application|SubApplication $subject, string $type = 'main'): string
    {
        $this->assertType($subject, $type);
        $context = $this->context($subject, $type);

        $files = [
            'pass.json' => (string) json_encode($this->applePassData($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'icon.png' => $this->icon(116),
            'icon@2x.png' => $this->icon(232),
        ];

        $manifest = [];

        foreach ($files as $name => $content) {
            $manifest[$name] = hash('sha256', $content);
        }

        ksort($manifest);
        $files['manifest.json'] = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES);

        $signature = $this->signature($files['manifest.json']);

        if ($signature !== null) {
            $files['signature'] = $signature;
        }

        return $this->zip($files);
    }

    /**
     * Build the Google Wallet payload for one application/sub-application:
     * the EventTicketObject JSON without credentials, or the `savetowallet`
     * JWT when a service account is configured.
     */
    public function buildGooglePass(Application|SubApplication $subject, string $type = 'main'): string
    {
        $this->assertType($subject, $type);
        $context = $this->context($subject, $type);
        $object = $this->googleObject($context);

        $credentials = $this->googleCredentials();

        if ($credentials !== null) {
            return $this->googleJwt($credentials, $object);
        }

        return (string) json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ---------------------------------------------------------------------
     | Apple pass payload
     | ------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function applePassData(array $context): array
    {
        $data = [
            'formatVersion' => 1,
            'passTypeIdentifier' => (string) config('wallet.apple.pass_type_id'),
            'serialNumber' => $context['serial'],
            'description' => $context['description'],
            'organizationName' => $this->organizationName($context),
            'logoText' => $this->logoText($context),
            'foregroundColor' => 'rgb(20,20,20)',
            'backgroundColor' => 'rgb(240,240,240)',
            'eventTicket' => [
                'primaryFields' => [
                    ['key' => 'name', 'label' => 'Name', 'value' => $context['user_name']],
                ],
                'secondaryFields' => $this->appleSecondaryFields($context),
                'auxiliaryFields' => $this->appleAuxiliaryFields($context),
            ],
            'barcode' => [
                'message' => $this->verifyUrl($context['application']),
                'format' => 'PKBarcodeFormatQR',
                'messageEncoding' => 'utf-8',
            ],
        ];

        $teamId = (string) (config('wallet.apple.team_id') ?? '');

        if ($teamId !== '') {
            $data['teamIdentifier'] = $teamId;
        }

        if ($context['relevant_date'] !== null) {
            $data['relevantDate'] = $context['relevant_date'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, string>>
     */
    private function appleSecondaryFields(array $context): array
    {
        $fields = [
            ['key' => 'category', 'label' => 'Kategorie', 'value' => $context['category']],
        ];

        if ($context['event'] !== null) {
            $fields[] = ['key' => 'event', 'label' => 'Event', 'value' => $context['event']];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, string>>
     */
    private function appleAuxiliaryFields(array $context): array
    {
        $fields = [];

        if ($context['date'] !== null) {
            $fields[] = ['key' => 'date', 'label' => 'Datum', 'value' => $context['date']];
        }

        $fields[] = [
            'key' => $context['type'] === 'main' ? 'status' : 'type',
            'label' => $context['type'] === 'main' ? 'Status' : 'Typ',
            'value' => $context['type_label'],
        ];

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function organizationName(array $context): string
    {
        $configured = (string) (config('wallet.apple.organization_name') ?? '');

        if ($configured !== '') {
            return $configured;
        }

        return $context['mandant_name'] !== '' ? $context['mandant_name'] : 'Accriditation';
    }

    /**
     * Kategorie + Event-Titel, gekürzt (40 Zeichen).
     *
     * @param  array<string, mixed>  $context
     */
    private function logoText(array $context): string
    {
        $text = $context['category'];

        if ($context['event'] !== null) {
            $text .= ' · '.$context['event'];
        }

        return mb_strimwidth($text, 0, 40, '…', 'UTF-8');
    }

    /* ---------------------------------------------------------------------
     | Google pass payload
     | ------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function googleObject(array $context): array
    {
        $issuerId = (string) (config('wallet.google.issuer_id') ?? '');
        $classId = $issuerId.'.'.(string) config('wallet.google.class_id', 'accriditation');

        $object = [
            'id' => $classId.'.'.$context['serial'],
            'classId' => $classId,
            'state' => 'ACTIVE',
            'passId' => $context['serial'],
            'issuerName' => $context['mandant_name'],
            'ticketHolderName' => $context['user_name'],
            'ticketType' => $context['category'],
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $this->verifyUrl($context['application']),
                'alternateText' => $context['type_label'],
            ],
            'textModulesData' => $this->googleTextModules($context),
        ];

        if ($context['event'] !== null) {
            $object['eventName'] = ['defaultValue' => ['language' => 'de', 'value' => $context['event']]];
        }

        if ($context['relevant_date'] !== null) {
            $object['dateTime'] = ['start' => $context['relevant_date']];
        }

        return $object;
    }

    /**
     * The locale-free (German) data fields of the Google object.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, string>>
     */
    private function googleTextModules(array $context): array
    {
        $modules = [
            ['id' => 'name', 'header' => 'Name', 'body' => $context['user_name']],
            ['id' => 'category', 'header' => 'Kategorie', 'body' => $context['category']],
        ];

        if ($context['event'] !== null) {
            $modules[] = ['id' => 'event', 'header' => 'Event', 'body' => $context['event']];
        }

        if ($context['date'] !== null) {
            $modules[] = ['id' => 'date', 'header' => 'Datum', 'body' => $context['date']];
        }

        $modules[] = [
            'id' => 'type',
            'header' => $context['type'] === 'main' ? 'Status' : 'Typ',
            'body' => $context['type_label'],
        ];

        return $modules;
    }

    /* ---------------------------------------------------------------------
     | Google JWT (service account)
     | ------------------------------------------------------------------- */

    /**
     * @return array{email: string, key: string}|null
     */
    private function googleCredentials(): ?array
    {
        $email = (string) (config('wallet.google.service_account_email') ?? '');
        $key = $this->googlePrivateKey();

        if ($email === '' || $key === null) {
            return null;
        }

        return ['email' => $email, 'key' => $key];
    }

    /**
     * Normalize the service account key config (PEM string, path to a PEM
     * file, or a JWK JSON) into a PEM private key, or null when absent.
     */
    private function googlePrivateKey(): ?string
    {
        $value = config('wallet.google.service_account_key');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (is_file($value)) {
            $value = (string) file_get_contents($value);
        }

        $trimmed = trim($value);

        if (str_starts_with($trimmed, '-----BEGIN')) {
            return $trimmed;
        }

        $jwk = json_decode($value, true);

        if (is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA') {
            return $this->jwkToPem($jwk);
        }

        return null;
    }

    /**
     * Convert an RSA JWK into a PKCS#1 PEM private key. All eight JWK
     * numbers must be present — OpenSSL only uses `n`/`e`/`d` for RS256
     * signing, so the CRT parameters are carried along as-is.
     *
     * @param  array<string, mixed>  $jwk
     */
    private function jwkToPem(array $jwk): ?string
    {
        foreach (['n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi'] as $field) {
            if (! is_string($jwk[$field] ?? null) || $jwk[$field] === '') {
                return null;
            }
        }

        $sequence = "\x02\x01\x00";

        foreach (['n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi'] as $field) {
            $bytes = $this->base64UrlDecode((string) $jwk[$field]);

            if ($bytes === '') {
                return null;
            }

            $sequence .= $this->derInteger($bytes);
        }

        $der = "\x30".$this->derLength(strlen($sequence)).$sequence;

        return "-----BEGIN RSA PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            .'-----END RSA PRIVATE KEY-----';
    }

    /**
     * @param  array{email: string, key: string}  $credentials
     * @param  array<string, mixed>  $object
     */
    private function googleJwt(array $credentials, array $object): string
    {
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $credentials['email'],
            'aud' => 'google',
            'iat' => now()->timestamp,
            'typ' => 'savetowallet',
            'payload' => ['eventTicketObjects' => [$object]],
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header.'.'.$claims;
        $signature = '';
        $key = openssl_pkey_get_private($credentials['key']);

        if ($key === false || ! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Google wallet JWT.');
        }

        return $signingInput.'.'.$this->base64Url($signature);
    }

    /* ---------------------------------------------------------------------
     | Apple signature + archive
     | ------------------------------------------------------------------- */

    /**
     * Detached PKCS#7 signature over the manifest (DER), or null when the
     * signing certificates are not configured. Only files that actually exist
     * on disk participate.
     */
    private function signature(string $manifestJson): ?string
    {
        $cert = $this->configFile('wallet.apple.cert');
        $key = $this->configFile('wallet.apple.key');
        $wwdr = $this->configFile('wallet.apple.wwdr');

        if ($cert === null || $key === null || $wwdr === null) {
            return null;
        }

        $dir = $this->tempDirectory();
        $manifestFile = $dir.'/manifest.json';
        $smimeFile = $dir.'/smime.p7s';

        try {
            File::put($manifestFile, $manifestJson);

            // Load cert/key into OpenSSL objects (the file-path form of
            // `openssl_pkcs7_sign` is unreliable on some OpenSSL 3 builds).
            $certObject = openssl_x509_read((string) File::get($cert));
            $keyPassword = (string) (config('wallet.apple.key_password') ?? '');
            $keyObject = openssl_pkey_get_private((string) File::get($key), $keyPassword === '' ? null : $keyPassword);

            if ($certObject === false || $keyObject === false) {
                throw new RuntimeException('Could not load the wallet pass signing certificate.');
            }

            // `PKCS7_BINARY` preserves the exact manifest bytes, `PKCS7_DETACHED`
            // produces a detached signature. The S/MIME output is a
            // `multipart/signed` message whose last part carries the
            // base64-encoded DER PKCS#7 — extracted below.
            $signed = openssl_pkcs7_sign(
                $manifestFile,
                $smimeFile,
                $certObject,
                $keyObject,
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
                $wwdr,
            );

            if ($signed === false) {
                throw new RuntimeException('Could not sign the wallet pass manifest.');
            }

            $smime = (string) File::get($smimeFile);
            $parts = explode('application/x-pkcs7-signature', $smime);
            $tail = $parts[count($parts) - 1];
            $body = preg_split('/\r?\n\r?\n/', $tail, 2)[1] ?? '';
            $body = explode("\n--", $body, 2)[0];
            $base64Body = preg_replace('/\s+/', '', $body);

            return $base64Body === '' ? null : $this->base64Decode($base64Body);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Resolve a config value that must point to an existing file (returns
     * null for unset/empty/non-existent paths, degrading to unsigned).
     */
    private function configFile(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value) || $value === '' || ! is_file($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'pkpass');
        $tmpDir = $this->tempDirectory();
        $zip = new ZipArchive;

        if ($tmpZip === false || $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the wallet pass archive.');
        }

        try {
            foreach ($files as $name => $content) {
                File::put($tmpDir.'/'.$name, $content);
                $zip->addFile($tmpDir.'/'.$name, $name);

                // Apple expects the signature stored uncompressed.
                if ($name === 'signature') {
                    $zip->setCompressionName($name, ZipArchive::CM_STORE);
                }
            }

            $zip->close();

            return (string) File::get($tmpZip);
        } finally {
            @unlink($tmpZip);
            File::deleteDirectory($tmpDir);
        }
    }

    /**
     * A monochrome square icon (foreground color, no user content).
     */
    private function icon(int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        $color = imagecolorallocate($image, 20, 20, 20);
        imagefill($image, 0, 0, $color);

        $stream = fopen('php://temp', 'wb');
        imagepng($image, $stream);
        rewind($stream);
        $png = (string) stream_get_contents($stream);
        fclose($stream);
        imagedestroy($image);

        return $png;
    }

    /* ---------------------------------------------------------------------
     | Shared context
     | ------------------------------------------------------------------- */

    /**
     * Normalize one application/sub-application into the field set both pass
     * formats render from.
     *
     * @return array<string, mixed>
     */
    private function context(Application|SubApplication $subject, string $type): array
    {
        if ($subject instanceof SubApplication) {
            $main = $subject->application;
            $subAccreditation = $subject->subAccreditation;
            $accreditation = $subAccreditation?->accreditation;

            $category = (string) ($accreditation?->category?->name ?? '');
            $eventTitle = $accreditation?->event?->title;
            $event = is_string($eventTitle) && $eventTitle !== '' ? $eventTitle : null;
            $eventDate = $accreditation?->event?->date;
            $deadline = $subAccreditation?->deadline_end;
            $date = $eventDate ?? $deadline;
            $relevantDate = $deadline ?? $eventDate;
            $typeLabel = $type === 'seat' ? 'Sitzkarte' : 'Parkkarte';
            $description = $typeLabel.($category !== '' ? ' '.$category : '');
        } else {
            $main = $subject;
            $accreditation = $subject->accreditation;

            $category = (string) ($accreditation?->category?->name ?? '');
            $eventTitle = $accreditation?->event?->title;
            $event = is_string($eventTitle) && $eventTitle !== '' ? $eventTitle : null;
            $eventDate = $accreditation?->event?->date;
            $deadline = $accreditation?->deadline_end;
            $date = $eventDate ?? $deadline;
            $relevantDate = $deadline ?? $eventDate;
            $typeLabel = $this->statusLabel((string) $subject->status);
            $description = 'Akkreditierung'.($category !== '' ? ' '.$category : '');
        }

        return [
            'application' => $main,
            'user_name' => (string) ($subject->user?->name ?? ''),
            'category' => $category,
            'event' => $event,
            'date' => $date?->format('d.m.Y'),
            'relevant_date' => $relevantDate?->toIso8601ZuluString(),
            'mandant_name' => (string) ($accreditation?->mandant?->name ?? ''),
            'type' => $type,
            'type_label' => $typeLabel,
            'description' => $description,
            'serial' => $type.'-'.$subject->getKey(),
        ];
    }

    /**
     * The verify URL for a pass QR: deterministic token + host chain from
     * `VerifyLink`. The main application is always the bearer of the token —
     * for sub-applications the QR verifies the linked approved main
     * application.
     */
    private function verifyUrl(Application $application): string
    {
        $token = $this->tokens->make($application);
        $application->setAttribute('qr_token', $token);

        return VerifyLink::for($application);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Akkreditiert',
            'requested' => 'Beantragt',
            'denied' => 'Abgelehnt',
            'blacklisted' => 'Gesperrt',
            default => $status,
        };
    }

    private function assertType(Application|SubApplication $subject, string $type): void
    {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Unknown wallet pass type '{$type}'.");
        }

        if ($subject instanceof Application && $type !== 'main') {
            throw new InvalidArgumentException('Application wallet passes are always of type "main".');
        }

        if ($subject instanceof SubApplication && $type === 'main') {
            throw new InvalidArgumentException('Sub-application wallet passes require type "park" or "seat".');
        }
    }

    /* ---------------------------------------------------------------------
     | Small helpers
     | ------------------------------------------------------------------- */

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return $this->base64Decode(strtr($data, '-_', '+/'));
    }

    private function base64Decode(string $data): string
    {
        return (string) base64_decode($data, true);
    }

    /**
     * DER-encode one positive big-endian integer (with the 0x00 prefix
     * whenever the high bit is set, so the value stays unsigned).
     */
    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function tempDirectory(): string
    {
        $dir = sys_get_temp_dir().'/pkpass-'.bin2hex(random_bytes(6));

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}

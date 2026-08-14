<?php

namespace App\Services;

use App\Models\Application;

/**
 * P4 QR verification tokens. A token is a stateless, signed reference to one
 * application:
 *
 *     token = base64url(applicationId . '.' . hmac_sha256(app_key, applicationId))
 *
 * - deterministic: the same application always yields the same token
 *   (idempotent — `make()` writes it once and reuses the stored value),
 * - self-contained: `parse()` recomputes the HMAC with `config('app.key')` and
 *   returns the application id, or null for tampered/malformed tokens,
 * - mandant-agnostic by design: the application row (and with it the owning
 *   mandant) is resolved after parsing, so a token never leaks tenant data.
 *
 * The public `/api/verify/{token}` surface reads the token via `parse()` and
 * looks the application up by the recovered id — the HMAC is the security
 * boundary, the stored `qr_token` column is an optimisation/index for admin
 * views, not a source of truth.
 */
final class QrTokenService
{
    /**
     * @param  string|null  $secret  HMAC key override (tests inject a foreign
     *                               secret to prove tamper detection).
     */
    public function __construct(private readonly ?string $secret = null) {}

    /**
     * The token for one application. Persists it on the row when missing
     * (deterministic, so a repeated call is a no-op).
     */
    public function make(Application $application): string
    {
        $token = $this->tokenFor((int) $application->getKey());

        if ($application->qr_token === null) {
            $application->update(['qr_token' => $token]);
        }

        return $application->qr_token ?? $token;
    }

    /**
     * Recover the application id from a token, or null when the token is
     * malformed or its HMAC does not match the configured secret.
     */
    public function parse(string $token): ?int
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $separator = strpos($decoded, '.');

        if ($separator === false) {
            return null;
        }

        $idPart = substr($decoded, 0, $separator);
        $signature = substr($decoded, $separator + 1);

        if ($idPart === '' || ! ctype_digit($idPart)) {
            return null;
        }

        $id = (int) $idPart;
        $expected = $this->signatureFor($id);

        return hash_equals($expected, $signature) ? $id : null;
    }

    private function tokenFor(int $id): string
    {
        return rtrim(strtr(base64_encode($id.'.'.$this->signatureFor($id)), '+/', '-_'), '=');
    }

    private function signatureFor(int $id): string
    {
        return hash_hmac('sha256', (string) $id, $this->secret(), true);
    }

    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        return (string) config('app.key');
    }
}

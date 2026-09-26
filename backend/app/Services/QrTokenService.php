<?php

namespace App\Services;

use App\Models\Accreditation;
use App\Models\Application;
use LogicException;

/**
 * P4 QR verification tokens. A token is a stateless, signed reference to ONE
 * application of ONE mandant (Verband).
 *
 * ## Format v2 (current, R-D3)
 *
 *     token = base64url(applicationId . '.' . hmac_sha256(secret, "v2:"+applicationId+":"+mandantId) . '.' . mandantId)
 *
 * The HMAC covers **both** claims and the mandant claim is additionally part of
 * the base64 payload, so a token is only valid for the tenant that issued it —
 * a badge of mandant A can no longer validate on the host of mandant B (before
 * v2 the token carried the id alone and the tenant came only from the DB row,
 * which made `/api/verify` a cross-tenant disclosure).
 *
 * ## Format v1 (legacy, still verifiable)
 *
 *     token = base64url(applicationId . '.' . hmac_sha256(secret, applicationId))
 *
 * A v1 token is accepted **only if** its HMAC verifies against a known key, but
 * it carries no mandant claim: it authenticates the application id, never the
 * tenant. Callers that resolve an application from a token (the public
 * `/api/verify/{token}`) must therefore scope their query to the current
 * mandant — that DB scope, not the token, is the isolation boundary for legacy
 * badges. Any write path (approval, resend, export, wallet pass) and
 * `accreditation:backfill-qr-tokens` upgrade a stored v1 token to v2.
 *
 * `parse()` never rejects a v1 token because of the binary shape of its
 * signature: a signature may contain '.' bytes, so the "is the last segment a
 * mandant claim" test is tried first and the legacy check over the whole
 * remaining payload always follows.
 *
 * ## Keys and rotation
 *
 * Minting always uses the CURRENT key, as the CONFIGURED string (never a
 * normalized form — that would invalidate every badge already in the wild).
 * Verification walks the current key first and then every entry of
 * `config('app.previous_keys')` (`APP_PREVIOUS_KEYS`), so a key rotation does
 * not invalidate the badges already in the wild. Each configured key is matched
 * under EVERY encoding this project has ever signed with — the literal string,
 * the canonical `base64:` form and the encrypter's raw bytes — because the
 * encrypter and this service did not agree on what a key string is
 * (F5, see `signingKeys()`).
 * `make()` re-mints a stored token that no longer verifies (or that is not a
 * tenant-bound token of this application), which makes stored tokens
 * self-healing: the row is repaired on the next touch instead of 404-ing
 * forever.
 *
 * The public `/api/verify/{token}` surface reads the token via `parse()` and
 * looks the application up mandant-scoped — the HMAC is the security boundary,
 * the stored `qr_token` column is an optimisation/index for admin views, not a
 * source of truth.
 *
 * Two entry points:
 *   - `token()`  — PURE, never touches the DB. Use it in READ paths (JSON
 *     serialization) where a write-on-read would be a side effect.
 *   - `make()`   — persists the token on the row when missing or outdated
 *     (self-healing). Use it in WRITE paths: approval, resend, export, backfill.
 */
final class QrTokenService
{
    /**
     * The token format this service mints. `parse()` also understands older
     * formats (see `QrTokenClaims::TENANT_BOUND_VERSION`).
     */
    public const VERSION = QrTokenClaims::TENANT_BOUND_VERSION;

    /**
     * @param  string|null  $secret  HMAC key override (tests inject a foreign
     *                               secret to prove tamper detection). An
     *                               override REPLACES the whole key set — the
     *                               configured previous keys are not appended.
     */
    public function __construct(private readonly ?string $secret = null) {}

    /**
     * The deterministic v2 token for one application WITHOUT persisting it.
     *
     * Read paths (JSON serialization) must use this — a DB write during
     * serialization is a side effect and must be avoided. Write paths
     * (approval, resend, export, backfill) use `make()` instead.
     */
    public function token(Application $application): string
    {
        $applicationId = (int) $application->getKey();
        $mandantId = $this->mandantIdOf($application);

        return $this->encode(
            $applicationId,
            $this->signatureFor($applicationId, $mandantId),
            $mandantId,
        );
    }

    /**
     * The token for one application, persisted on the row. Idempotent: a stored
     * token that is already valid for this application is returned unchanged.
     *
     * A stored token is re-minted when it is missing, unverifiable against ANY
     * known key (e.g. after an `APP_KEY` rotation without `APP_PREVIOUS_KEYS`),
     * not tenant-bound (legacy v1), or bound to another mandant. Re-minting is
     * what makes stored tokens self-healing: the next approval, resend, export
     * or wallet pass repairs the row instead of leaving a dead badge behind.
     */
    public function make(Application $application): string
    {
        $stored = $application->qr_token;

        if (is_string($stored) && $stored !== '' && $this->isValidFor($application, $stored)) {
            return $stored;
        }

        $token = $this->token($application);
        $application->update(['qr_token' => $token]);

        return $token;
    }

    /**
     * Whether a token is a valid, tenant-bound token of exactly this
     * application (and its mandant) under a known key. The predicate behind
     * `make()`'s idempotency and behind the read paths that must decide
     * between a stored and a freshly computed token without writing.
     */
    public function isValidFor(Application $application, string $token): bool
    {
        $claims = $this->parse($token);

        if ($claims === null || ! $claims->isTenantBound()) {
            return false;
        }

        return $claims->applicationId === (int) $application->getKey()
            && $claims->mandantId === $this->mandantIdOf($application);
    }

    /**
     * Recover the verified claims of a token, or null when the token is
     * malformed, carries no known signature, or its claims are inconsistent.
     *
     * Callers MUST treat the mandant claim as the tenant boundary:
     *   - tenant-bound token → `QrTokenClaims::mandantId` names the only tenant
     *     that may resolve it (`matchesMandant()`),
     *   - legacy token → `mandantId` is null, the token authenticates the
     *     application id only and the consumer's mandant-scoped query is the
     *     isolation boundary.
     */
    public function parse(string $token): ?QrTokenClaims
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
        $payload = substr($decoded, $separator + 1);

        if ($idPart === '' || ! ctype_digit($idPart) || $payload === '') {
            return null;
        }

        $applicationId = (int) $idPart;

        // The raw HMAC is binary and may itself contain a '.' byte, so the
        // segments are taken from the outside in: the id is the FIRST segment, a
        // trailing all-digit segment is a mandant claim, everything between them
        // is the signature.
        //
        // That split is AMBIGUOUS for a v1 token whose signature happens to end
        // in a '.' followed by digits only (rare, measured: 4 of 40 000 issued
        // tokens) — the digit run then reads as a mandant claim and the
        // v2 check fails, which used to reject the token outright. Both
        // readings are therefore tried: the tenant-bound check first, and
        // unconditionally afterwards the legacy check over the WHOLE remaining
        // payload (a v1 signature is the payload verbatim, dots included). The
        // legacy check is keyed on the full HMAC of the application id, so
        // falling back to it cannot be abused: a v2 token with a tampered or
        // corrupt mandant segment still fails it and is rejected.
        $lastSeparator = strrpos($payload, '.');
        $mandantPart = $lastSeparator === false ? null : substr($payload, $lastSeparator + 1);

        if ($lastSeparator !== false && $mandantPart !== '' && ctype_digit($mandantPart)) {
            $claims = $this->verifyTenantBound($applicationId, substr($payload, 0, $lastSeparator), (int) $mandantPart);

            if ($claims !== null) {
                return $claims;
            }
        }

        return $this->verifyLegacy($applicationId, $payload);
    }

    /**
     * A v1 token (`id . hmac(secret, id)`): accepted when the signature matches
     * a known key, but it carries no mandant claim.
     */
    private function verifyLegacy(int $applicationId, string $signature): ?QrTokenClaims
    {
        foreach ($this->signingKeys() as $key) {
            $expected = hash_hmac('sha256', (string) $applicationId, $key, true);

            if (hash_equals($expected, $signature)) {
                return QrTokenClaims::legacy($applicationId);
            }
        }

        return null;
    }

    /**
     * A v2 token (`id . hmac(secret, "v2:id:mandantId") . mandantId`): the
     * signature must cover BOTH claims under a known key.
     */
    private function verifyTenantBound(int $applicationId, string $signature, int $mandantId): ?QrTokenClaims
    {
        if ($applicationId < 1 || $mandantId < 1) {
            return null;
        }

        foreach ($this->signingKeys() as $key) {
            $expected = hash_hmac('sha256', $this->signedPayload($applicationId, $mandantId), $key, true);

            if (hash_equals($expected, $signature)) {
                return QrTokenClaims::tenantBound($applicationId, $mandantId);
            }
        }

        return null;
    }

    private function encode(int $applicationId, string $signature, int $mandantId): string
    {
        $payload = $applicationId.'.'.$signature.'.'.$mandantId;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function signatureFor(int $applicationId, int $mandantId): string
    {
        return hash_hmac('sha256', $this->signedPayload($applicationId, $mandantId), $this->mintingKey(), true);
    }

    /**
     * The signed message. The `v2:` prefix is the format marker: it makes a v2
     * signature impossible to confuse with a v1 signature over the same id.
     */
    private function signedPayload(int $applicationId, int $mandantId): string
    {
        return 'v2:'.$applicationId.':'.$mandantId;
    }

    /**
     * The mandant an application belongs to — the tenant claim of its token.
     *
     * Read from the (already eager-loaded, where the caller can) accreditation;
     * a restricted eager load that omitted the column falls back to one
     * explicit read, because minting a token without a tenant claim would
     * silently degrade to the legacy trust model.
     */
    private function mandantIdOf(Application $application): int
    {
        $mandantId = $application->accreditation?->getAttribute('mandant_id');

        if ($mandantId === null) {
            $mandantId = Accreditation::query()
                ->whereKey($application->accreditation_id)
                ->value('mandant_id');
        }

        if ($mandantId === null) {
            throw new LogicException(
                'Cannot mint a QR token: application '.$application->getKey().' has no accreditation mandant.'
            );
        }

        return (int) $mandantId;
    }

    /**
     * The key tokens are signed with: always the CURRENT key, so a rotation
     * heals stored tokens on their next touch.
     */
    private function mintingKey(): string
    {
        return (string) config('app.key');
    }

    /**
     * Every key a token may have been signed with: the current key first, then
     * the configured previous keys (`APP_PREVIOUS_KEYS`). Verification walks
     * this list, so a rotation never invalidates an already-issued badge.
     *
     * ## Key normalization (F5)
     *
     * `APP_KEY`/`APP_PREVIOUS_KEYS` have TWO consumers and they did not agree on
     * what a key string is. The encrypter runs `parseKey()`: it strips the
     * `base64:` prefix and base64-decodes, so it signs/encrypts with the RAW 32
     * bytes. This service HMACs the CONFIGURED STRING verbatim. That is fine
     * while every value carries the prefix `php artisan key:generate` prints —
     * and silently broken the moment an operator pastes the value without it (a
     * raw base64 blob, or whatever the encrypter itself accepts): the stored
     * `smtp_config` kept decrypting, so nothing looked wrong, while every badge
     * signed with that key 404s and `accreditation:backfill-qr-tokens` cannot
     * repair it (the token is fine; the key list is not).
     *
     * So each configured key contributes every ENCODING of it this project has
     * ever HMAC'd with, not just the literal string: the literal value (what the
     * `APP_KEY` in force at mint time produced), the canonical `base64:`-prefixed
     * form, and the raw decoded bytes the encrypter works with. All three are the
     * SAME secret material in different encodings, so this widens nothing — an
     * unrelated secret still fails every alias.
     *
     * The bare-base64 alias is the F5 trigger: `php artisan key:generate` prints
     * `base64:XXXX`, an operator who pastes `XXXX` into `APP_PREVIOUS_KEYS` would
     * otherwise verify nothing, and no backfill repairs it.
     *
     * @return list<string>
     */
    private function signingKeys(): array
    {
        if ($this->secret !== null) {
            return [$this->secret];
        }

        $keys = [];

        foreach ([$this->mintingKey(), ...(array) config('app.previous_keys')] as $configured) {
            if (! is_string($configured) || $configured === '') {
                continue;
            }

            foreach ($this->keyAliases($configured) as $alias) {
                if (! in_array($alias, $keys, true)) {
                    $keys[] = $alias;
                }
            }
        }

        return $keys;
    }

    /**
     * Every encoding of one configured key: the literal value, the canonical
     * `base64:` form and the raw decoded bytes.
     *
     * A value that is neither prefixed nor valid strict base64 (a legacy raw
     * passphrase) yields exactly one alias — itself — and stays usable verbatim.
     *
     * @return list<string>
     */
    private function keyAliases(string $key): array
    {
        $aliases = [$key];
        $raw = $this->decodeKey($key);

        if (! str_starts_with($key, 'base64:') && $raw !== null) {
            $aliases[] = 'base64:'.$key;
        }

        if ($raw !== null) {
            $aliases[] = $raw;
        }

        return array_values(array_unique($aliases));
    }

    /**
     * The raw key bytes a configured value stands for — `Encrypter::parseKey()`
     * semantics, extended to a value that carries no `base64:` prefix (that is
     * the F5 trigger, so a prefixed spelling must be recoverable from a bare
     * one). Null when the value is not valid strict base64.
     */
    private function decodeKey(string $key): ?string
    {
        $encoded = str_starts_with($key, 'base64:') ? substr($key, 7) : $key;
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }
}

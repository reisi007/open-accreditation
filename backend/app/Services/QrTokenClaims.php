<?php

namespace App\Services;

/**
 * The verified claims of one P4 QR verification token (see `QrTokenService`).
 *
 * A v2 token carries BOTH the application id and the id of the mandant that
 * issued it, and its HMAC covers both claims — so a token is bound to exactly
 * one tenant and can only be resolved on that tenant's host. A legacy v1 token
 * (`id . hmac(id)`, no tenant claim) yields `mandantId = null`; such a token is
 * NOT tenant-bound and its isolation relies entirely on the mandant-scoped
 * database lookup of the consumer (`VerifyController`) — see
 * `QrTokenService::parse()` and `features/badges-qr.md`.
 *
 * Value object: constructed only by the two named constructors below, never
 * from unverified input.
 */
final readonly class QrTokenClaims
{
    /**
     * The first token version that carries a mandant claim. Tokens below it are
     * the legacy (pre-R-D3) format and are accepted for verification only.
     */
    public const TENANT_BOUND_VERSION = 2;

    private function __construct(
        public int $applicationId,
        public ?int $mandantId,
        public int $version,
    ) {}

    /**
     * A legacy token: application id only, no tenant claim.
     */
    public static function legacy(int $applicationId): self
    {
        return new self($applicationId, null, 1);
    }

    /**
     * A tenant-bound token: application id + the mandant that issued it.
     */
    public static function tenantBound(int $applicationId, int $mandantId): self
    {
        return new self($applicationId, $mandantId, self::TENANT_BOUND_VERSION);
    }

    /**
     * Whether the token carries a signed mandant claim (v2+). A `false` here
     * means the token authenticates the application id but says nothing about
     * the tenant — the consumer MUST scope its own lookup to the current
     * mandant instead of trusting the token for isolation.
     */
    public function isTenantBound(): bool
    {
        return $this->version >= self::TENANT_BOUND_VERSION && $this->mandantId !== null;
    }

    /**
     * Whether the claims are valid for the given mandant.
     *
     * A tenant-bound token must name exactly this mandant (any other value is a
     * rejection, never a fallback). A legacy token has no claim to check, so it
     * is accepted here and the mandant scope is enforced by the caller's
     * mandant-scoped query instead.
     */
    public function matchesMandant(int $mandantId): bool
    {
        return $this->isTenantBound() ? $this->mandantId === $mandantId : true;
    }
}

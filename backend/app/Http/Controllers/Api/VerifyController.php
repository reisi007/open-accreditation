<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VerifyResource;
use App\Models\Application;
use App\Services\QrTokenService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public QR verification (P4), guarded only by the dedicated `throttle:verify`
 *
 *   GET /api/verify/{token}
 *       parses the token (HMAC) and looks the application up by the recovered
 *       id. Valid + approved → full identity payload; valid + any other status
 *       → bare `{status}`. Invalid/tampered/unknown → 404 `{message}`.
 *   GET /api/verify/{token}/photo
 *       inline portrait from the private disk, ONLY for approved applications
 *       that own a portrait; otherwise 404. The portrait is never leaked for a
 *       revoked (denied) badge.
 *
 * ## Tenant isolation (R-D3)
 *
 * The route is public and host-routed, so `MandantContextMiddleware` has already
 * resolved the mandant from the request host — and answers 404 itself for an
 * unknown host in production. Two independent guards keep the surface single
 * tenant even so:
 *
 * 1. The token's signed mandant claim must name the current mandant
 *    (`QrTokenClaims::matchesMandant()`). A token minted for another Verband
 *    can never validate here, even though it is a perfectly valid signature.
 * 2. Defence in depth: the application lookup is mandant-scoped
 *    (`Application::scopeForMandant`) on top of the id. A legacy v1 token
 *    carries no mandant claim at all — for those this DB scope IS the isolation
 *    boundary, which is why the scope is unconditional.
 *
 * Without a resolved mandant the lookup is refused (404, fail closed): a
 * verification request that cannot be attributed to a tenant resolves nothing
 * rather than resolving globally. In production this branch is unreachable
 * (the middleware 404s an unknown host first); it only guards the console/test
 * contexts that run without a host.
 */
class VerifyController extends Controller
{
    public function __construct(private readonly QrTokenService $tokens) {}

    public function verify(string $token): VerifyResource|JsonResponse
    {
        $application = $this->resolveApplication($token);

        if ($application === null) {
            return response()->json(['message' => 'Invalid verification token.'], 404);
        }

        return new VerifyResource($application, $token);
    }

    public function photo(string $token): StreamedResponse|JsonResponse
    {
        $application = $this->resolveApplication($token);

        if ($application === null || $application->status !== 'approved' || $application->user === null) {
            return response()->json(['message' => 'Invalid verification token.'], 404);
        }

        $portrait = $application->user->media->firstWhere('type', 'portrait');

        if ($portrait === null || ! Storage::disk('private')->exists($portrait->path)) {
            return response()->json(['message' => 'Invalid verification token.'], 404);
        }

        return Storage::disk('private')->response(
            $portrait->path,
            $portrait->original_name,
            ['Content-Type' => $portrait->mime],
        );
    }

    private function resolveApplication(string $token): ?Application
    {
        $claims = $this->tokens->parse($token);

        if ($claims === null) {
            return null;
        }

        // Fail closed: no resolved mandant (console/test context) resolves
        // nothing — see the class docblock.
        $mandantId = MandantContext::currentId();

        if ($mandantId === null) {
            return null;
        }

        // Tenant claim (v2 tokens) must name the current mandant. Legacy tokens
        // carry no claim and pass here — the mandant-scoped query below is
        // their isolation boundary.
        if (! $claims->matchesMandant($mandantId)) {
            return null;
        }

        return Application::query()
            ->forMandant($mandantId)
            ->whereKey($claims->applicationId)
            ->with(['user.media', 'accreditation.category', 'accreditation.event'])
            ->first();
    }
}

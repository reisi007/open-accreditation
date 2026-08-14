<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VerifyResource;
use App\Models\Application;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public QR verification (P4), guarded only by `throttle:public`.
 *
 *   GET /api/verify/{token}
 *       parses the token (HMAC) and looks the application up by the recovered
 *       id. Valid + approved → full identity payload; valid + any other status
 *       → bare `{status}`. Invalid/tampered/unknown → 404 `{message}`.
 *   GET /api/verify/{token}/photo
 *       inline portrait from the private disk, ONLY for approved applications
 *       that own a portrait; otherwise 404. The portrait is never leaked for a
 *       revoked (denied) badge.
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
        $id = $this->tokens->parse($token);

        if ($id === null) {
            return null;
        }

        return Application::query()
            ->with(['user.media', 'accreditation.category', 'accreditation.event'])
            ->find($id);
    }
}

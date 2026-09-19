<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\MandantMediaService;
use App\Services\MediaStorage;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public (auth-free) logo/header delivery for the current mandant (P3a).
 * Files live on the public `media` disk in the W1 layout; legacy `private`
 * files stay readable until the W6 backfill moved them. A mandant without an
 * uploaded image is 404.
 *
 * W11: delivery runs through `MediaStorage::accelResponse` — with a configured
 * accel prefix the backend answers empty + `X-Accel-Redirect` (Caddy serves
 * the file from MEDIA_ROOT), otherwise it streams as before.
 */
class PortalMediaController extends Controller
{
    public function __construct(
        private readonly MandantMediaService $service,
        private readonly MediaStorage $storage,
    ) {}

    public function logo(Request $request): Response|JsonResponse
    {
        return $this->deliver($request, 'logo');
    }

    public function header(Request $request): Response|JsonResponse
    {
        return $this->deliver($request, 'header');
    }

    private function deliver(Request $request, string $kind): Response|JsonResponse
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        // Re-read the row: the container instance may predate a concurrent
        // admin upload (fresh instance per request in production).
        $mandant->refresh();

        $path = $this->service->path($mandant, $kind);

        if ($path === null || ! $this->storage->exists($path)) {
            return response()->json([
                'message' => 'Kein Bild hinterlegt.',
            ], 404);
        }

        return $this->storage->accelResponse(
            $path,
            (string) $request->header('Accept', ''),
            null,
            ['Content-Type' => $this->storage->mimeType($path)],
        );
    }
}

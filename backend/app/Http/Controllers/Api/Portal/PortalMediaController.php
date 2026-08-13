<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\MandantMediaService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public (auth-free) logo/header delivery for the current mandant (P3a).
 * Files stay on the private disk and are streamed through these routes —
 * never exposed as public URLs. A mandant without an uploaded image is 404.
 */
class PortalMediaController extends Controller
{
    public function __construct(private readonly MandantMediaService $service) {}

    public function logo(): StreamedResponse|JsonResponse
    {
        return $this->deliver('logo');
    }

    public function header(): StreamedResponse|JsonResponse
    {
        return $this->deliver('header');
    }

    private function deliver(string $kind): StreamedResponse|JsonResponse
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        // Re-read the row: the container instance may predate a concurrent
        // admin upload (fresh instance per request in production).
        $mandant->refresh();

        $path = $this->service->path($mandant, $kind);

        if ($path === null || ! Storage::disk('private')->exists($path)) {
            return response()->json([
                'message' => 'Kein Bild hinterlegt.',
            ], 404);
        }

        return Storage::disk('private')->response(
            $path,
            null,
            ['Content-Type' => (string) Storage::disk('private')->mimeType($path)],
        );
    }
}

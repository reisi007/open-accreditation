<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MandantResource;
use App\Models\Mandant;
use App\Services\MandantMediaService;
use App\Services\MediaStorage;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandant self-service logo/header management (`can:mandant.media.manage`,
 * P8b). Mirrors the Super Admin `MandantMediaController`, but resolves the
 * target mandant from the CURRENT context (MandantContext) instead of
 * route-model binding — a request parameter never selects the mandant, so no
 * IDOR is possible. Files live on the public `media` disk in the W1 layout and
 * are streamed through these routes; legacy `private` files stay readable
 * until the W6 backfill moved them.
 */
class MandantMediaSelfServiceController extends Controller
{
    private const KIND_LOGO = 'logo';

    private const KIND_HEADER = 'header';

    public function __construct(
        private readonly MandantMediaService $service,
        private readonly MediaStorage $storage,
    ) {}

    public function showLogo(Request $request): Response|JsonResponse
    {
        return $this->deliver($request, self::KIND_LOGO);
    }

    public function showHeader(Request $request): Response|JsonResponse
    {
        return $this->deliver($request, self::KIND_HEADER);
    }

    public function storeLogo(Request $request): JsonResponse
    {
        return $this->store($request, self::KIND_LOGO);
    }

    public function storeHeader(Request $request): JsonResponse
    {
        return $this->store($request, self::KIND_HEADER);
    }

    public function destroyLogo(): Response
    {
        return $this->destroy(self::KIND_LOGO);
    }

    public function destroyHeader(): Response
    {
        return $this->destroy(self::KIND_HEADER);
    }

    private function store(Request $request, string $kind): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $mandant = $this->mandant();

        $this->service->store($mandant, $kind, $file);

        return (new MandantResource($mandant))->response();
    }

    private function destroy(string $kind): Response
    {
        $this->service->destroy($this->mandant(), $kind);

        return response()->noContent();
    }

    private function deliver(Request $request, string $kind): Response|JsonResponse
    {
        $mandant = $this->mandant();
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

    /**
     * The mandant this request operates on — resolved once from the current
     * context (falling back to the primary mandant), never from user input.
     */
    private function mandant(): Mandant
    {
        $mandant = MandantContext::current() ?? MandantContext::default();

        if ($mandant === null) {
            abort(404, 'Kein Mandant im Kontext.');
        }

        return $mandant;
    }
}

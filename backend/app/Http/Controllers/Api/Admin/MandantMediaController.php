<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MandantResource;
use App\Models\Mandant;
use App\Services\MandantMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auth-gated logo/header delivery and upload for mandants (Super Admin API,
 * `can:mandants.manage`). Files live on the private disk and are streamed
 * through these routes — never exposed as public URLs.
 */
class MandantMediaController extends Controller
{
    private const KIND_LOGO = 'logo';

    private const KIND_HEADER = 'header';

    public function __construct(private readonly MandantMediaService $service) {}

    public function showLogo(Mandant $mandant): StreamedResponse|JsonResponse
    {
        return $this->deliver($mandant, self::KIND_LOGO);
    }

    public function showHeader(Mandant $mandant): StreamedResponse|JsonResponse
    {
        return $this->deliver($mandant, self::KIND_HEADER);
    }

    public function storeLogo(Request $request, Mandant $mandant): JsonResponse
    {
        return $this->store($request, $mandant, self::KIND_LOGO);
    }

    public function storeHeader(Request $request, Mandant $mandant): JsonResponse
    {
        return $this->store($request, $mandant, self::KIND_HEADER);
    }

    public function destroyLogo(Mandant $mandant): Response
    {
        return $this->destroy($mandant, self::KIND_LOGO);
    }

    public function destroyHeader(Mandant $mandant): Response
    {
        return $this->destroy($mandant, self::KIND_HEADER);
    }

    private function store(Request $request, Mandant $mandant, string $kind): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->service->store($mandant, $kind, $file);

        return (new MandantResource($mandant))->response();
    }

    private function destroy(Mandant $mandant, string $kind): Response
    {
        $this->service->destroy($mandant, $kind);

        return response()->noContent();
    }

    private function deliver(Mandant $mandant, string $kind): StreamedResponse|JsonResponse
    {
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

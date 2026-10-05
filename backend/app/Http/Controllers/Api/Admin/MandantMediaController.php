<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesMandantRouteParameter;
use App\Http\Controllers\Controller;
use App\Http\Resources\MandantResource;
use App\Models\Mandant;
use App\Services\MandantMediaService;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth-gated logo/header delivery and upload for mandants (Super Admin API,
 * `can:mandants.manage`). Files live on the public `media` disk in the W1
 * layout and are streamed through these routes; legacy `private` files stay
 * readable until the W6 backfill moved them. W11 delivery runs through
 * `MediaStorage::accelResponse` (dual-mode, see `PortalMediaController`).
 *
 * The `{mandant}` route parameter is checked per call against the caller via
 * `assertMandantRouteParameter()`: super_admin may read and replace any
 * mandant's brand media from any host, nobody else. A mandant_admin has his own
 * separate, host-derived surface for exactly this (`/api/mandant/logo|header`,
 * `MandantMediaSelfServiceController`, gate `mandant.media.manage`) — he must
 * not reach another mandant's logo through the super-admin route.
 */
class MandantMediaController extends Controller
{
    use ResolvesMandantRouteParameter;

    private const KIND_LOGO = 'logo';

    private const KIND_HEADER = 'header';

    public function __construct(
        private readonly MandantMediaService $service,
        private readonly MediaStorage $storage,
    ) {}

    public function showLogo(Request $request, Mandant $mandant): Response|JsonResponse
    {
        return $this->deliver($request, $mandant, self::KIND_LOGO);
    }

    public function showHeader(Request $request, Mandant $mandant): Response|JsonResponse
    {
        return $this->deliver($request, $mandant, self::KIND_HEADER);
    }

    public function storeLogo(Request $request, Mandant $mandant): JsonResponse
    {
        return $this->store($request, $mandant, self::KIND_LOGO);
    }

    public function storeHeader(Request $request, Mandant $mandant): JsonResponse
    {
        return $this->store($request, $mandant, self::KIND_HEADER);
    }

    public function destroyLogo(Request $request, Mandant $mandant): Response
    {
        return $this->destroy($request, $mandant, self::KIND_LOGO);
    }

    public function destroyHeader(Request $request, Mandant $mandant): Response
    {
        return $this->destroy($request, $mandant, self::KIND_HEADER);
    }

    private function store(Request $request, Mandant $mandant, string $kind): JsonResponse
    {
        $this->assertMandantRouteParameter($request, $mandant);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->service->store($mandant, $kind, $file);

        return (new MandantResource($mandant))->response();
    }

    private function destroy(Request $request, Mandant $mandant, string $kind): Response
    {
        $this->assertMandantRouteParameter($request, $mandant);

        $this->service->destroy($mandant, $kind);

        return response()->noContent();
    }

    private function deliver(Request $request, Mandant $mandant, string $kind): Response|JsonResponse
    {
        $this->assertMandantRouteParameter($request, $mandant);

        $path = $this->service->path($mandant, $kind);

        if ($path === null || ! $this->storage->exists($path)) {
            return response()->json([
                'message' => __('messages.media.no_image'),
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

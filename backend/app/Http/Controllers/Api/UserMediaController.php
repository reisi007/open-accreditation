<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserMediaResource;
use App\Models\UserMedia;
use App\Services\UserMediaService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserMediaController extends Controller
{
    public function __construct(private readonly UserMediaService $service) {}

    /**
     * GET /api/user/media — the authenticated user's own media files.
     */
    public function index(): AnonymousResourceCollection
    {
        $media = auth('api')->user()->media()->orderByDesc('id')->get();

        return UserMediaResource::collection($media);
    }

    /**
     * POST /api/user/media — multipart upload. `portrait` and `press_id`
     * replace a previous file of the same type; `attachment` is multi-valued.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::enum(MediaType::class)],
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $media = $this->service->store(
            auth('api')->user(),
            MediaType::from($validated['type']),
            $file,
            MandantContext::current()?->slug ?? 'default',
        );

        return (new UserMediaResource($media))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/user/media/{media} — auth-gated inline delivery of the file.
     * The owner streams the original bytes; anyone else gets 403 (404 for
     * unknown ids via route model binding).
     */
    public function show(UserMedia $media): StreamedResponse|JsonResponse
    {
        if ($media->user_id !== auth('api')->id()) {
            return response()->json([
                'message' => 'Zugriff verweigert.',
            ], 403);
        }

        return Storage::disk('private')->response(
            $media->path,
            $media->original_name,
            ['Content-Type' => $media->mime],
        );
    }

    /**
     * DELETE /api/user/media/{media} — owner-only removal (file + row).
     */
    public function destroy(UserMedia $media): JsonResponse
    {
        if ($media->user_id !== auth('api')->id()) {
            return response()->json([
                'message' => 'Zugriff verweigert.',
            ], 403);
        }

        $this->service->destroy($media);

        return response()->json([
            'message' => 'Medium gelöscht.',
        ]);
    }
}

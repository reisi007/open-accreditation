<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeImageResource;
use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Models\RoleUser;
use App\Services\BadgeImageService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mandant-owned badge images (features/badge-template-editor.md, "Elementtyp
 * `image`" / "Upload-Infrastruktur") — the upload half of the freely placed
 * `image` badge layout entries.
 *
 *   GET    /api/admin/badge-images                    mandant-scoped list
 *   POST   /api/admin/badge-images                    upload (`file`, multipart)
 *   GET    /api/admin/badge-images/{id}/file          auth-gated inline delivery
 *   DELETE /api/admin/badge-images/{id}               remove row + private file
 *
 * Guarded by `can:accreditations.manage` — the same surface as the badge
 * templates they decorate. Like templates, images are a mandant-level
 * resource: super_admin and mandant_admin manage them; team_admin may only
 * read (every write answers 403). The target mandant is derived from
 * MandantContext (never a request parameter) and the route-model binding is
 * tenant-guarded (`BadgeImage::resolveRouteBindingQuery`), so rows/files of a
 * foreign mandant are 404.
 *
 * Upload validation mirrors the self-service media (`MandantMediaService`):
 * `file` required, `image`, `mimes:jpeg,png,webp`, `max:2048` KB plus the
 * 2000×2000 px dimension limit; the extension on disk derives from the
 * validated MIME type, never from the client filename. Files live ONLY on the
 * private disk and are streamed through these routes.
 *
 * Deleting an image does NOT rewrite template layouts referencing it — the
 * entry keeps its `image_id` and renders as an empty box (documented
 * behavior); re-uploading creates a NEW id by design.
 */
class BadgeImageController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly BadgeImageService $images) {}

    /**
     * The current mandant's uploads, newest first (the editor's "existing
     * images" picker).
     */
    public function index(): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();

        return BadgeImageResource::collection(
            BadgeImage::query()
                ->forMandant($mandantId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $this->assertMayWrite($request);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $image = $this->images->store(Mandant::findOrFail($mandantId), $file);

        return (new BadgeImageResource($image))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Auth-gated inline delivery of the stored file (editor thumbnails /
     * previews). A foreign-mandant id never binds (tenant-guarded route model).
     */
    public function showFile(BadgeImage $badgeImage): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($badgeImage->path), 404);

        return Storage::disk('private')->response(
            $badgeImage->path,
            $badgeImage->original_name,
            ['Content-Type' => $badgeImage->mime],
        );
    }

    public function destroy(Request $request, BadgeImage $badgeImage): Response
    {
        $this->currentMandantId();
        $this->assertMandantScope($badgeImage, MandantContext::currentId() ?? 0);
        $this->assertMayWrite($request);

        $this->images->destroy($badgeImage);

        return response()->noContent();
    }

    /**
     * Badge images are mandant-level like the templates they decorate: any
     * team_admin assignment in the current mandant turns a write into 403
     * (read stays allowed). Mirrors `BadgeTemplateController::assertMayWrite`.
     */
    private function assertMayWrite(Request $request): void
    {
        $user = $request->user();
        $mandantId = MandantContext::currentId();

        if ($user === null || $mandantId === null) {
            return;
        }

        $assignments = $user->roleAssignmentsForMandant($mandantId);

        $isTeamAdmin = $assignments->contains(
            static fn (RoleUser $assignment): bool => $assignment->role->slug === UserRole::TEAM_ADMIN->value,
        );

        abort_if($isTeamAdmin, 403, 'Badge images are managed by the Verband admin.');
    }
}

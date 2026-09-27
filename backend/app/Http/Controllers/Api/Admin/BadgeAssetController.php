<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\BadgePhotoPlaceholder;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivery of the **bundled** badge assets (features/badge-template-editor.md,
 * "Platzhalter für ein fehlendes Porträt").
 *
 *   GET /api/admin/badge-assets/photo-placeholder    the person silhouette
 *
 * This is not a mandant-owned upload (those live in `badge_images` and travel
 * through `BadgeImageController::showFile`) — it is a file that ships with the
 * application and belongs to nobody. It nevertheless goes out through the same
 * auth-gated admin surface instead of the web root:
 *
 * - The editor has no build step of its own for this icon and no second copy of
 *   it may exist in the repo, so it fetches the very same bytes the PDF embeds
 *   (`BadgePhotoPlaceholder::dataUri()`), from the very same file.
 * - `resources/img/badge/…` deliberately lives outside `public/` (AGENTS.md §11:
 *   auth-gated file delivery). A bundled asset is safe by construction, but the
 *   *editor's* delivery path is a real decision, and the house convention for
 *   "bytes the editor shows" is an auth-gated API route — `logo_url`, `header_url`
 *   and `GET /api/admin/badge-images/{id}/file` all work that way.
 *
 * The gate is the badge-template surface (`can:accreditations.manage`) so a
 * plain `user` without that right never fetches it, and the whole `/api/admin/*`
 * group is mandant-scoped by `MandantContextMiddleware`. The asset carries no
 * tenant data at all — two mandants receive byte-identical responses, which the
 * test asserts rather than claims.
 *
 * A missing bundled file answers 500: it is a deployment defect (the build
 * excluded `resources/`), and `BadgePhotoPlaceholder` has already logged it with
 * the command that regenerates the file.
 */
class BadgeAssetController extends Controller
{
    public function __construct(private readonly BadgePhotoPlaceholder $placeholder) {}

    public function photoPlaceholder(): Response
    {
        $bytes = $this->placeholder->bytes();

        abort_if($bytes === null, 500, 'The bundled badge photo placeholder is missing.');

        return response($bytes, 200, [
            'Content-Type' => BadgePhotoPlaceholder::MIME,
            // A build artifact: identical for every mandant, and it only changes
            // with a deploy. `private` keeps it out of shared caches (the route
            // is auth-gated), the revalidation window lets a redeploy propagate.
            'Cache-Control' => 'private, max-age=3600, must-revalidate',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                'badge-photo-placeholder.png',
            ),
        ]);
    }
}

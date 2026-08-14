<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMediaResource;
use App\Models\Application;
use App\Models\UserMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin media access (P3e, P3b-F3 decision: live retrieval, no snapshot).
 *
 *   GET /api/admin/applications/{application}/media
 *       the applicant's UserMedia (portrait, press_id, attachments) of one
 *       application, auth-gated via `can:accreditations.manage`; foreign
 *       applications are 404.
 *   GET /api/admin/user-media/{media}
 *       auth-gated inline delivery of one media file. The owner-only delivery
 *       route (`api.user.media.show`) stays untouched — admins use this
 *       route. Independently scoped so a media row of a user without any
 *       (team-scoped) application in the current mandant is 404.
 */
class AdminMediaController extends Controller
{
    use ResolvesAdminTeamScope;

    public function index(Request $request, Application $application): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $this->assertApplicationAccessible($application, $mandantId, $teamIds);

        $media = UserMedia::query()
            ->where('user_media.user_id', $application->user_id)
            ->orderBy('user_media.type')
            ->orderBy('user_media.id')
            ->get();

        return AdminMediaResource::collection($media);
    }

    public function show(Request $request, UserMedia $media): StreamedResponse|JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $this->assertMediaAccessible($media, $mandantId, $teamIds);

        return Storage::disk('private')->response(
            $media->path,
            $media->original_name,
            ['Content-Type' => $media->mime],
        );
    }

    /**
     * The media file is reachable when its owner holds an application in the
     * current mandant — for a team_admin restricted to his own team's
     * accreditations. Independent of the list endpoint so delivery never
     * leaks a foreign row (404).
     */
    private function assertMediaAccessible(UserMedia $media, int $mandantId, array $teamIds): void
    {
        $query = Application::query()
            ->forMandant($mandantId)
            ->where('user_id', $media->user_id);

        if ($teamIds !== []) {
            $query->whereHas('accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
        }

        abort_unless($query->exists(), 404);
    }

    /**
     * A route-bound application is reachable when it lies in the current
     * mandant (404 otherwise) and, for a team_admin, sits on one of his own
     * team's accreditations (403 otherwise).
     */
    private function assertApplicationAccessible(Application $application, int $mandantId, array $teamIds): void
    {
        $query = Application::query()->forMandant($mandantId)->whereKey($application->id);

        if ($teamIds !== []) {
            $query->whereHas('accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
            abort_unless($query->exists(), 403);
        } else {
            abort_unless($query->exists(), 404);
        }
    }
}

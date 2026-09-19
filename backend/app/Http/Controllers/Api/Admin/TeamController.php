<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Mandant;
use App\Models\Team;
use App\Rules\ValidUtf8;
use App\Services\MediaStorage;
use App\Services\TeamMediaService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Team CRUD (Super Admin) plus the W4 team logo surface.
 *
 * The read endpoint (`index`) is guarded by `can:teams.view` (P2b-F1): a
 * mandant_admin may list all teams of his mandant, a team_admin only his own
 * team(s). Team *CRUD* writes stay on `can:teams.manage`, which in the
 * permission matrix is also granted to a team_admin *within his own team
 * scope*. This admin surface manages teams across arbitrary mandants, so every
 * CRUD write additionally requires the global super admin role — keeping the
 * tenant-CRUD semantics of this API and closing the cross-mandant
 * manipulation gap.
 *
 * W4 adds the team logo surface (`/api/admin/teams/{team}/logo`): auth-gated
 * delivery (`teams.view`) plus hierarchical writes (`teams.media.manage`,
 * W4-F1) — super_admin globally, mandant_admin for every team of his own
 * mandant (MandantContext), team_admin for his own team(s) only. Stored under
 * the W1 media layout (`<host>/teams/<slug>/logo.<ext>`).
 */
class TeamController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(
        private readonly TeamMediaService $media,
        private readonly MediaStorage $storage,
    ) {}

    public function index(Request $request, Mandant $mandant): AnonymousResourceCollection
    {
        $this->authorizeView($request, $mandant);

        $query = $mandant->teams()->orderBy('name');

        $teamIds = $this->teamIds($request);

        if ($teamIds !== []) {
            // team_admin: only his own team(s).
            $query->whereIn('id', $teamIds);
        }

        return TeamResource::collection($query->get());
    }

    public function store(Request $request, Mandant $mandant): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->assertTeamsEnabled($mandant);
        $validated = $request->validate($this->rules($mandant, forCreate: true));

        $team = $mandant->teams()->create($validated);

        return (new TeamResource($team))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Mandant $mandant, string $team): TeamResource
    {
        $this->authorizeSuperAdmin($request);
        $this->assertTeamsEnabled($mandant);
        $teamModel = $mandant->teams()->findOrFail((int) $team);
        $validated = $request->validate($this->rules($mandant, $teamModel));

        $previousSlug = $teamModel->slug;

        $teamModel->update($validated);

        // W2-F2 L2: a slug change moves the logo file along, so the stored
        // path never points at a directory of the previous slug.
        $this->media->moveForSlugChange($teamModel->fresh(), $previousSlug);

        return new TeamResource($teamModel->fresh());
    }

    public function destroy(Request $request, Mandant $mandant, string $team): Response
    {
        $this->authorizeSuperAdmin($request);
        $teamModel = $mandant->teams()->findOrFail((int) $team);

        // The DB FK does not touch files — drop the logo from the public media
        // disk explicitly before the row disappears.
        $this->media->purge($teamModel);

        $teamModel->delete();

        return response()->noContent();
    }

    /**
     * Auth-gated delivery of the team logo (inline). Read access follows
     * `teams.view` (route gate): super_admin, mandant_admin and team_admin may
     * open the image; the team is resolved through the tenant-guarded binding.
     * Writes (`storeLogo`/`destroyLogo`) are hierarchical (W4-F1) — see
     * `authorizeLogoWrite()`.
     */
    public function showLogo(Request $request, Team $team): StreamedResponse|JsonResponse
    {
        $this->assertTeamOfCurrentMandant($team);

        $path = $team->logo_path;

        if ($path === null || ! $this->storage->exists($path)) {
            return response()->json(['message' => 'Kein Bild hinterlegt.'], 404);
        }

        return $this->storage->response($path, null, [
            'Content-Type' => $this->storage->mimeType($path),
        ]);
    }

    /**
     * Upload/replace the team logo. Validation mirrors the brand media:
     * `image`, `mimes:jpeg,png,webp`, `max:2048` KB plus the 2000×2000 px
     * dimension limit. The extension derives from the validated MIME type,
     * never from the client filename. The file lands on the public `media` disk
     * under the W1 layout (`<host>/teams/<slug>/logo.<ext>`, host-neutral
     * `_tenants/<id>/teams/<slug>/logo.<ext>` without a domain); the previous
     * file is removed only after the new one is stored (`TeamMediaService`).
     *
     * Authorization is hierarchical (W4-F1): `authorizeLogoWrite()`.
     */
    public function storeLogo(Request $request, Team $team): TeamResource
    {
        $this->assertTeamOfCurrentMandant($team);
        $this->authorizeLogoWrite($request, $team);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->media->store($team, $file);

        return new TeamResource($team->fresh());
    }

    /**
     * Delete the team logo file and reset `logo_path`. Authorization mirrors
     * `storeLogo` (W4-F1): `authorizeLogoWrite()`.
     */
    public function destroyLogo(Request $request, Team $team): Response
    {
        $this->assertTeamOfCurrentMandant($team);
        $this->authorizeLogoWrite($request, $team);

        $this->media->destroy($team);

        return response()->noContent();
    }

    /**
     * The team must belong to the current mandant context. The route-model
     * binding already scopes to it; this guard also covers the no-context case
     * (where the binding stays unscoped).
     */
    private function assertTeamOfCurrentMandant(Team $team): void
    {
        abort_unless(
            MandantContext::currentId() !== null
                && (int) $team->mandant_id === MandantContext::currentId(),
            404,
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Mandant $mandant, ?Team $team = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        return [
            'name' => [$main, 'string', 'max:255', new ValidUtf8],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('teams', 'slug')
                    ->where('mandant_id', $mandant->id)
                    ->ignore($team?->id),
            ],
            'home_venue' => ['nullable', 'string', 'max:255', new ValidUtf8],
        ];
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    /**
     * Authorization for logo writes (W4-F1), mirroring the self-service media
     * pattern: super_admin globally, mandant_admin for every team of his own
     * mandant (`MandantContext`, never a request parameter), team_admin for
     * his own team(s) only (`role_user.team_id`). The team is resolved through
     * the mandant-scoped route binding and re-checked via
     * `assertTeamOfCurrentMandant()`, so a foreign mandant yields 404 and a
     * sibling team of the same mandant yields 403. user/verifier never reach
     * this method (route gate `teams.media.manage`).
     */
    private function authorizeLogoWrite(Request $request, Team $team): void
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($user->isSuperAdmin() || $user->isMandantAdmin($this->currentMandantId())) {
            return;
        }

        abort_unless(
            in_array((int) $team->id, $this->teamIds($request), true),
            403,
            'You may only manage the logo of your own team.',
        );
    }

    /**
     * Read access beyond super_admin (P2b-F1: `teams.view` route gate already
     * passed). Non-super admins may only read the teams of *their own* mandant
     * — the URL mandant must equal the current MandantContext, else 404
     * (cross-mandant leak guard).
     */
    private function authorizeView(Request $request, Mandant $mandant): void
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        abort_unless((int) $mandant->id === MandantContext::currentId(), 404, 'Team does not belong to the current mandant.');
    }

    /**
     * Teams are an opt-in feature per mandant (`teams_enabled`). Both store
     * and update refuse to touch teams while the feature is disabled.
     */
    private function assertTeamsEnabled(Mandant $mandant): void
    {
        abort_unless((bool) $mandant->teams_enabled, 422, 'Teams are not enabled for this mandant.');
    }
}

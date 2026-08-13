<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Mandant;
use App\Models\Team;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin CRUD for the teams (Vereine) of a mandant.
 *
 * The read endpoint (`index`) is guarded by `can:teams.view` (P2b-F1): a
 * mandant_admin may list all teams of his mandant, a team_admin only his own
 * team(s). Write endpoints stay on `can:teams.manage`, which in the permission
 * matrix is also granted to a team_admin *within his own team scope*. This
 * admin surface manages teams across arbitrary mandants, so every write
 * additionally requires the global super admin role — keeping the tenant-CRUD
 * semantics of this API and closing the cross-mandant manipulation gap.
 */
class TeamController extends Controller
{
    use ResolvesAdminTeamScope;

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

        $teamModel->update($validated);

        return new TeamResource($teamModel->fresh());
    }

    public function destroy(Request $request, Mandant $mandant, string $team): Response
    {
        $this->authorizeSuperAdmin($request);
        $teamModel = $mandant->teams()->findOrFail((int) $team);
        $teamModel->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Mandant $mandant, ?Team $team = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        return [
            'name' => [$main, 'string', 'max:255'],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('teams', 'slug')
                    ->where('mandant_id', $mandant->id)
                    ->ignore($team?->id),
            ],
            'home_venue' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
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

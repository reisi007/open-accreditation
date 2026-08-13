<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Mandant;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin CRUD for the teams (Vereine) of a mandant.
 *
 * The route group is guarded by `can:teams.manage`, which in the permission
 * matrix is also granted to a team_admin *within his own team scope*. This
 * admin surface manages teams across arbitrary mandants, so every action
 * additionally requires the global super admin role — keeping the tenant-CRUD
 * semantics of this API and closing the cross-mandant manipulation gap.
 */
class TeamController extends Controller
{
    public function index(Request $request, Mandant $mandant): AnonymousResourceCollection
    {
        $this->authorizeSuperAdmin($request);

        return TeamResource::collection(
            $mandant->teams()->orderBy('name')->get(),
        );
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
     * Teams are an opt-in feature per mandant (`teams_enabled`). Both store
     * and update refuse to touch teams while the feature is disabled.
     */
    private function assertTeamsEnabled(Mandant $mandant): void
    {
        abort_unless((bool) $mandant->teams_enabled, 422, 'Teams are not enabled for this mandant.');
    }
}

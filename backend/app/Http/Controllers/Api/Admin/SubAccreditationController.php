<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubAccreditationResource;
use App\Models\Accreditation;
use App\Models\SubAccreditation;
use App\Services\SubAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for sub-accreditations (Park-/Sitzkarten, D9) of the current
 * mandant. Guarded by `can:accreditations.manage` (super_admin,
 * mandant_admin, team_admin) like the main accreditations.
 *
 * The sub-accreditation hangs off one main accreditation, so the ownership
 * chain is: sub → accreditation → mandant/team. super_admin / mandant_admin
 * are unrestricted within the current mandant; team_admin may only manage
 * sub-accreditations of his own team's accreditations (mandant-level rows are
 * read-only for him, foreign rows 404/403).
 */
class SubAccreditationController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly SubAllocationService $subAllocationService) {}

    public function index(Request $request, Accreditation $accreditation): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $subs = $accreditation->subAccreditations()
            ->withCount('subApplications')
            ->orderBy('id')
            ->get();

        return SubAccreditationResource::collection($subs);
    }

    public function store(Request $request, Accreditation $accreditation): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $validated = $request->validate($this->rules($request, forCreate: true));

        $sub = $accreditation->subAccreditations()->create($validated);

        // DB defaults (`active`, `auto_approve`) only exist in the row — the
        // in-memory instance needs a refresh before it is serialized.
        $sub->refresh();

        return (new SubAccreditationResource($sub->loadCount('subApplications')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, SubAccreditation $sub): SubAccreditationResource
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $sub = $this->assertSubAccessible($sub, $mandantId, $teamIds);

        $validated = $request->validate($this->rules($request));

        $sub->update($validated);

        return new SubAccreditationResource($sub->fresh()->loadCount('subApplications'));
    }

    public function destroy(Request $request, SubAccreditation $sub): Response
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $sub = $this->assertSubAccessible($sub, $mandantId, $teamIds);

        $sub->delete();

        return response()->noContent();
    }

    /**
     * Run the P3d sub-allocation engine on one sub-accreditation (manual
     * trigger): `mode=all` approves every eligible sub-application up to the
     * quota, `mode=first` approves only the first `limit` candidates. A
     * `limit` is required for `mode=first` and ignored for `mode=all` —
     * identical contract to the P3c main allocation endpoint.
     */
    public function allocate(Request $request, SubAccreditation $sub): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $sub = $this->assertSubAccessible($sub, $mandantId, $teamIds);

        $rules = ['mode' => ['required', Rule::in(['all', 'first'])]];

        if ($request->input('mode') === 'first') {
            $rules['limit'] = ['required', 'integer', 'min:1'];
        }

        $validated = $request->validate($rules);

        $result = $validated['mode'] === 'all'
            ? $this->subAllocationService->approveAllEligible($sub)
            : $this->subAllocationService->approveSelection($sub, (int) $validated['limit']);

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        $rules = [
            'type' => [$main, Rule::in(['park', 'seat'])],
            'quota' => [$main, 'integer', 'min:1'],
            'deadline_start' => ['nullable', 'date'],
            'deadline_end' => ['nullable', 'date'],
            'auto_approve' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];

        // `deadline_end` must follow `deadline_start` — equal dates are allowed
        // (a single-day window). Enforced only when both arrive together (the
        // P2b EventController pattern).
        if ($request->filled('deadline_start')) {
            $rules['deadline_end'][] = 'after_or_equal:deadline_start';
        }

        return $rules;
    }

    /**
     * A sub-accreditation is reachable when its main accreditation lies in
     * the current mandant (404 otherwise) and, for a team_admin, belongs to
     * one of his own teams (403 otherwise — mandant-level rows are read-only
     * for team_admin, mirroring the main accreditation controller).
     */
    private function assertSubAccessible(SubAccreditation $sub, int $mandantId, array $teamIds): SubAccreditation
    {
        $sub->loadMissing('accreditation:id,mandant_id,team_id');

        abort_unless((int) $sub->accreditation->mandant_id === $mandantId, 404);

        $this->assertOwnership($sub->accreditation, $teamIds);

        return $sub;
    }
}

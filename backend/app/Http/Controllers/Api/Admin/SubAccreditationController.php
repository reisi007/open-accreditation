<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubAccreditationResource;
use App\Models\Accreditation;
use App\Models\SubAccreditation;
use App\Services\SubAllocationService;
use App\Support\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * P3e-B4: mandant-wide sub-accreditation list with server-side filters —
     * one request instead of N parallel per-accreditation requests from the
     * admin approval view. Same resource shape as `index()`
     * (SubAccreditationResource incl. applications_count/available), scoped
     * to the current mandant via MandantContext; a team_admin only sees the
     * sub-accreditations of his own teams' accreditations.
     *
     *   GET /api/admin/sub-accreditations
     *       ?accreditation_id=&category_id=&event_id=&team_id=
     *       &type=park|seat&active=0|1&search=
     *
     * A foreign `?accreditation_id` is rejected (422, or 403 for a
     * team_admin) like in AdminApplicationController; the remaining filters
     * are plain portable query-builder conditions (no raw PG SQL).
     */
    public function indexAll(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $validated = $request->validate([
            'accreditation_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'event_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['park', 'seat'])],
            'active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string'],
        ]);

        $query = SubAccreditation::query()
            ->forMandant($mandantId)
            ->withCount('subApplications');

        if ($teamIds !== []) {
            $query->whereHas('accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
        }

        if (array_key_exists('accreditation_id', $validated)) {
            $accreditationId = (int) $validated['accreditation_id'];
            $this->assertAccreditationFilter($accreditationId, $mandantId, $teamIds);
            $query->where('sub_accreditations.accreditation_id', $accreditationId);
        }

        // Parent-accreditation attribute filters (category/event/team) share
        // one whereHas so several filters combine into a single EXISTS.
        $parentFilters = array_intersect_key($validated, array_flip(['category_id', 'event_id', 'team_id']));

        if ($parentFilters !== []) {
            $query->whereHas('accreditation', function (Builder $q) use ($parentFilters): void {
                foreach ($parentFilters as $column => $value) {
                    $q->where("accreditations.{$column}", (int) $value);
                }
            });
        }

        if (array_key_exists('type', $validated)) {
            $query->where('sub_accreditations.type', (string) $validated['type']);
        }

        if (array_key_exists('active', $validated)) {
            $query->where('sub_accreditations.active', (bool) $validated['active']);
        }

        if (array_key_exists('search', $validated) && $validated['search'] !== '') {
            $term = LikeSearch::escape((string) $validated['search']);
            $query->where(function (Builder $q) use ($term): void {
                // CC-R1: `LOWER()` on both sides pins case-insensitive search
                // and keeps Postgres (LIKE is case-sensitive) in sync with
                // SQLite (LIKE is case-insensitive by default) — portable.
                $q->whereHas(
                    'accreditation.category',
                    fn (Builder $cq) => $cq->whereRaw("LOWER(categories.name) like LOWER(?) escape '\\'", ["%{$term}%"]),
                )->orWhereHas(
                    'accreditation.event',
                    fn (Builder $eq) => $eq->whereRaw("LOWER(events.title) like LOWER(?) escape '\\'", ["%{$term}%"]),
                );
            });
        }

        // P3e-B4-F3: grouped by parent accreditation first, then by id within
        // each accreditation — the admin dropdown lists sub-accreditations per
        // accreditation, a flat `orderBy('id')` scrambles that grouping.
        return SubAccreditationResource::collection(
            $query->orderBy('accreditation_id')->orderBy('id')->get(),
        );
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

    /**
     * An `?accreditation_id` filter of `indexAll()` must reference an
     * accreditation of the current mandant (422 otherwise); a team_admin may
     * only filter within his own teams (403 otherwise). Mirrors
     * AdminApplicationController::assertAccreditationFilter().
     */
    private function assertAccreditationFilter(int $accreditationId, int $mandantId, array $teamIds): void
    {
        $query = Accreditation::query()->forMandant($mandantId)->whereKey($accreditationId);

        if ($teamIds !== []) {
            $query->whereIn('team_id', $teamIds);
            abort_unless($query->exists(), 403);

            return;
        }

        abort_unless($query->exists(), 422);
    }
}

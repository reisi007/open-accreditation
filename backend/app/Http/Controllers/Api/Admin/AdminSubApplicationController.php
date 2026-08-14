<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminSubApplicationResource;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Services\SubAllocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admin approval view (P3e) for sub-applications (Park-/Sitzkarte, D9) of the
 * current mandant. Guarded by `can:accreditations.manage`; team_admin is
 * scoped to his own team's accreditations (the mandant of a sub-application
 * derives from its main accreditation).
 *
 *   GET /api/admin/sub-applications?sub_accreditation_id=&status=
 *   PUT /api/admin/sub-applications/{id}   {status?: 'approved'|'denied',
 *                                           reason?: string, priority?: bool}
 *
 * Every status change goes through `SubAllocationService` — the controller
 * only validates the request and resolves the resource scope.
 */
class AdminSubApplicationController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly SubAllocationService $subAllocationService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $validated = $request->validate([
            'sub_accreditation_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['requested', 'approved', 'denied', 'blacklisted'])],
        ]);

        $query = SubApplication::query()
            ->forMandant($mandantId)
            ->with([
                'user:id,email,name',
                // The eager-load closure receives the relation instance (not a
                // Builder) — untyped, mirroring the existing controllers.
                'subAccreditation' => fn ($q) => $q
                    ->with(['accreditation.category:id,name', 'accreditation.event:id,title,date'])
                    ->withCount(['subApplications as approved_count' => fn (Builder $q2) => $q2->where('status', 'approved')]),
            ]);

        if ($teamIds !== []) {
            $query->whereHas('subAccreditation.accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
        }

        if (array_key_exists('sub_accreditation_id', $validated)) {
            $subAccreditationId = (int) $validated['sub_accreditation_id'];
            $this->assertSubAccreditationFilter($subAccreditationId, $mandantId, $teamIds);
            $query->where('sub_applications.sub_accreditation_id', $subAccreditationId);
        }

        if (array_key_exists('status', $validated)) {
            $query->where('sub_applications.status', (string) $validated['status']);
        }

        return AdminSubApplicationResource::collection(
            $query->orderByDesc('sub_applications.created_at')->orderByDesc('sub_applications.id')->get(),
        );
    }

    public function update(Request $request, SubApplication $subApplication): AdminSubApplicationResource
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $subApplication = $this->assertSubApplicationAccessible($subApplication, $mandantId, $teamIds);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['approved', 'denied'])],
            'reason' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'boolean'],
        ]);

        // Status first, then priority: the service may reject the transition
        // (422) — priority is only touched when the status write succeeded.
        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'approved') {
                $this->subAllocationService->approveSubApplication($subApplication);
            } else {
                $this->subAllocationService->denySubApplication($subApplication, (string) ($validated['reason'] ?? ''));
            }
        }

        if (array_key_exists('priority', $validated)) {
            $this->subAllocationService->setPriority($subApplication, (bool) $validated['priority']);
        }

        $subApplication->refresh();

        return new AdminSubApplicationResource(
            $subApplication->load([
                'user:id,email,name',
                'subAccreditation' => fn ($q) => $q
                    ->with(['accreditation.category:id,name', 'accreditation.event:id,title,date'])
                    ->withCount(['subApplications as approved_count' => fn (Builder $q2) => $q2->where('status', 'approved')]),
            ]),
        );
    }

    /**
     * A route-bound sub-application is reachable when it lies in the current
     * mandant (via its main accreditation, 404 otherwise) and, for a
     * team_admin, sits on one of his own team's accreditations (403
     * otherwise).
     */
    private function assertSubApplicationAccessible(SubApplication $subApplication, int $mandantId, array $teamIds): SubApplication
    {
        $query = SubApplication::query()->forMandant($mandantId)->whereKey($subApplication->id);

        if ($teamIds !== []) {
            $query->whereHas('subAccreditation.accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
            abort_unless($query->exists(), 403);
        } else {
            abort_unless($query->exists(), 404);
        }

        return $subApplication;
    }

    /**
     * A `?sub_accreditation_id` filter must reference a sub-accreditation of
     * the current mandant (422 otherwise); a team_admin may only filter
     * within his own teams (403 otherwise).
     */
    private function assertSubAccreditationFilter(int $subAccreditationId, int $mandantId, array $teamIds): void
    {
        $query = SubAccreditation::query()->forMandant($mandantId)->whereKey($subAccreditationId);

        if ($teamIds !== []) {
            $query->whereHas('accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
            abort_unless($query->exists(), 403);

            return;
        }

        abort_unless($query->exists(), 422);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminApplicationResource;
use App\Mail\ApplicationDeniedMail;
use App\Mail\PassMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Services\AllocationService;
use App\Services\MandantMailerService;
use App\Services\QrTokenService;
use App\Support\VerifyLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admin approval view (P3e) for main applications (Anträge) of the current
 * mandant. Guarded by `can:accreditations.manage` (super_admin,
 * mandant_admin, team_admin — the latter scoped to his own team's
 * accreditations via `ResolvesAdminTeamScope`).
 *
 *   GET /api/admin/applications?accreditation_id=&status=&search=
 *   PUT /api/admin/applications/{id}   {status?: 'approved'|'denied',
 *                                      reason?: string, priority?: bool}
 *
 * Every status change goes through `AllocationService` (the central status
 * writer) — the controller only validates the request and resolves the
 * resource scope. Quota/blacklist/reason guards live in the service.
 */
class AdminApplicationController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(
        private readonly AllocationService $allocationService,
        private readonly MandantMailerService $mandantMailer,
        private readonly QrTokenService $qrTokenService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $validated = $request->validate([
            'accreditation_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['requested', 'approved', 'denied', 'blacklisted'])],
            'search' => ['nullable', 'string'],
        ]);

        $query = Application::query()
            ->forMandant($mandantId)
            ->with([
                'user:id,email,name',
                // The eager-load closure receives the relation instance (not a
                // Builder) — untyped, mirroring the existing controllers.
                'accreditation' => fn ($q) => $q
                    ->with(['category:id,name', 'event:id,title,date', 'team:id,name'])
                    ->withCount(['applications as approved_count' => fn (Builder $q2) => $q2->where('status', 'approved')]),
            ]);

        if ($teamIds !== []) {
            $query->whereHas('accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
        }

        if (array_key_exists('accreditation_id', $validated)) {
            $accreditationId = (int) $validated['accreditation_id'];
            $this->assertAccreditationFilter($accreditationId, $mandantId, $teamIds);
            $query->where('applications.accreditation_id', $accreditationId);
        }

        if (array_key_exists('status', $validated)) {
            $query->where('applications.status', (string) $validated['status']);
        }

        if (array_key_exists('search', $validated) && $validated['search'] !== '') {
            $term = $this->escapeLike((string) $validated['search']);
            $query->where(function (Builder $q) use ($term) {
                $q->whereHas('user', fn (Builder $uq) => $uq->whereRaw("users.email like ? escape '\\'", ["%{$term}%"]))
                    ->orWhereHas('user', fn (Builder $uq) => $uq->whereRaw("users.name like ? escape '\\'", ["%{$term}%"]));
            });
        }

        return AdminApplicationResource::collection(
            $query->orderByDesc('applications.created_at')->orderByDesc('applications.id')->get(),
        );
    }

    public function update(Request $request, Application $application): AdminApplicationResource
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);
        $application = $this->assertApplicationAccessible($application, $mandantId, $teamIds);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['approved', 'denied'])],
            'reason' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'boolean'],
        ]);

        // Status first, then priority: the service may reject the transition
        // (422) — priority is only touched when the status write succeeded.
        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'approved') {
                $this->allocationService->approveApplication($application);
            } else {
                $this->allocationService->denyApplication($application, (string) ($validated['reason'] ?? ''));
            }
        }

        if (array_key_exists('priority', $validated)) {
            $this->allocationService->setPriority($application, (bool) $validated['priority']);
        }

        $application->refresh();

        return new AdminApplicationResource(
            $application->load([
                'user:id,email,name',
                'accreditation' => fn ($q) => $q
                    ->with(['category:id,name', 'event:id,title,date', 'team:id,name'])
                    ->withCount(['applications as approved_count' => fn (Builder $q2) => $q2->where('status', 'approved')]),
            ]),
        );
    }

    /**
     * POST /api/admin/applications/{id}/resend
     *
     * P5: re-send the status-passing notification mail to the applicant
     * (approved → pass mail, denied → denial mail). `requested`/`blacklisted`
     * applications have no mailable status (422); a denied application
     * without a reason cannot be mailed (422). The route is guarded by
     * `can:accreditations.manage` like the other application actions; the
     * mandant/team scope is resolved identically to `update` (foreign mandant
     * 404, foreign team 403).
     */
    public function resend(Request $request, Application $application): JsonResponse
    {
        $application = $this->assertApplicationAccessible(
            $application,
            $this->currentMandantId(),
            $this->teamIds($request),
        );

        $application->loadMissing('accreditation.mandant');
        $mandant = $application->accreditation->mandant;

        if ($application->status === 'approved') {
            $this->qrTokenService->make($application);

            $this->mandantMailer->send(
                $mandant,
                new PassMail($application, VerifyLink::for($application)),
            );

            return response()->json(['message' => 'E-Mail wurde erneut gesendet.']);
        }

        if ($application->status === 'denied') {
            $reason = $application->reason;

            if ($reason === null || trim($reason) === '') {
                return response()->json(['message' => 'Application has no mailable reason.'], 422);
            }

            $this->mandantMailer->send(
                $mandant,
                new ApplicationDeniedMail($application, $reason),
            );

            return response()->json(['message' => 'E-Mail wurde erneut gesendet.']);
        }

        return response()->json(['message' => 'Application has no mailable status.'], 422);
    }

    /**
     * A route-bound application is reachable when it lies in the current
     * mandant (404 otherwise) and, for a team_admin, sits on one of his own
     * team's accreditations (403 otherwise).
     */
    private function assertApplicationAccessible(Application $application, int $mandantId, array $teamIds): Application
    {
        $query = Application::query()->forMandant($mandantId)->whereKey($application->id);

        if ($teamIds !== []) {
            $query->whereHas('accreditation', fn (Builder $q) => $q->whereIn('accreditations.team_id', $teamIds));
            abort_unless($query->exists(), 403);
        } else {
            abort_unless($query->exists(), 404);
        }

        return $application;
    }

    /**
     * A `?accreditation_id` filter must reference an accreditation of the
     * current mandant (422 otherwise); a team_admin may only filter within
     * his own teams (403 otherwise).
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

    /**
     * Escape LIKE wildcards so a search for literal `%`/`_` does not act as a
     * pattern. Applied with an explicit `ESCAPE '\'` clause (portable across
     * Postgres and SQLite — see the PortalController pattern).
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminSubApplicationResource;
use App\Mail\SubApplicationApprovedMail;
use App\Mail\SubApplicationDeniedMail;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Rules\ValidUtf8;
use App\Services\MandantMailerService;
use App\Services\SubAllocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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
 *   POST /api/admin/sub-applications/{id}/resend
 *
 * Every status change goes through `SubAllocationService` — the controller
 * only validates the request and resolves the resource scope.
 */
class AdminSubApplicationController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(
        private readonly SubAllocationService $subAllocationService,
        private readonly MandantMailerService $mandantMailer,
    ) {}

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
            'reason' => ['sometimes', 'nullable', 'string', new ValidUtf8],
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
     * POST /api/admin/sub-applications/{id}/resend
     *
     * P6 follow-up: re-send the Park-/Sitzkarte notification to the applicant
     * — the sub counterpart of `AdminApplicationController::resend`, with the
     * same gates, the same 422 shape and the same wording. `approved` → the sub
     * approval mail (pass attachment included), `denied` → the sub denial mail
     * with the PERSISTED reason verbatim. A `requested` row has no mailable
     * status and a `denied` row without a reason cannot be mailed: 422 each,
     * and in both cases nothing is dispatched.
     *
     * ## Scope and isolation are the ones of `update`
     *
     * `assertSubApplicationAccessible()` is the same helper the show/update path
     * uses: the row must lie in the current mandant (via its sub-accreditation's
     * accreditation → 404 otherwise) and, for a `team_admin`, on one of his own
     * team's accreditations (403 otherwise). The route sits in the same
     * `can:accreditations.manage` group and carries the same strict
     * `throttle:resend` limiter (10/min) as the main endpoint — it is a mail
     * trigger, so it must not share the 300/min admin write budget.
     *
     * ## The dispatch is the engine's, not a second mechanism
     *
     * `MandantMailerService::send()` → `SendMandantMail` is the same queue write
     * the allocation paths perform (idempotency claim, retry cap,
     * `failed_jobs.mandant_id`), with the same mailable classes and the same
     * relation graph — `AbstractSubApplicationMail::prepare()` loads what the
     * view needs, exactly as it does when the engine builds the mail. There is
     * no second delivery path and nothing is swallowed: the job is written or
     * the request fails.
     *
     * The mandant is the SUB-accreditation's mandant, not the request context's
     * — the sub-quota decision is the one being notified, and that is the rule
     * `SubAllocationService::mandantFor()` and
     * `AbstractSubApplicationMail` already pin. It cannot be null here:
     * `assertSubApplicationAccessible()` proved the sub-accreditation, its
     * accreditation AND that accreditation's mandant (`forMandant()`), which is
     * the mandant the request context was resolved from.
     *
     * ## What this endpoint deliberately does NOT touch
     *
     * The row's status, its reason and its `qr_token` are left exactly as they
     * are — a resend is a delivery action, not a second decision. In particular
     * the verify link in the sub approval mail belongs to the MAIN application
     * (`AbstractSubApplicationMail::verifyUrl()`) and its token is owned by
     * `AllocationService` / `QrTokenService::make()`; this endpoint neither
     * mints nor invalidates it, so a resend can never break a pass that was
     * already handed out. That is also why it does not repair a missing token
     * the way `AdminApplicationController::resend()` does: the sub APPROVAL path
     * (`SubAllocationService::dispatchApprovedMails()`) does not repair it
     * either, and a resend must duplicate the mail it repeats, not behave
     * differently from the original dispatch.
     */
    public function resend(Request $request, SubApplication $subApplication): JsonResponse
    {
        $subApplication = $this->assertSubApplicationAccessible(
            $subApplication,
            $this->currentMandantId(),
            $this->teamIds($request),
        );

        $subApplication->loadMissing('subAccreditation.accreditation.mandant');
        $mandant = $subApplication->subAccreditation->accreditation->mandant;

        if ($subApplication->status === 'approved') {
            $this->mandantMailer->send(
                $mandant,
                new SubApplicationApprovedMail($subApplication),
            );

            return response()->json(['message' => 'E-Mail wurde erneut in die Warteschlange gestellt.']);
        }

        if ($subApplication->status === 'denied') {
            $reason = $subApplication->reason;

            if ($reason === null || trim($reason) === '') {
                return response()->json(['message' => 'Sub-application has no mailable reason.'], 422);
            }

            $this->mandantMailer->send(
                $mandant,
                new SubApplicationDeniedMail($subApplication, $reason),
            );

            return response()->json(['message' => 'E-Mail wurde erneut in die Warteschlange gestellt.']);
        }

        return response()->json(['message' => 'Sub-application has no mailable status.'], 422);
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

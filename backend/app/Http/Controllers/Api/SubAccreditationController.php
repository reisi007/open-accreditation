<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubAccreditationResource;
use App\Http\Resources\SubApplicationResource;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public sub-accreditation API (P3d, D9) plus the authenticated apply action.
 *
 * Public (auth-free, like the accreditation API):
 *   GET /api/accreditations/{id}/sub-accreditations   active sub-accreditations
 *                                                     of one active main
 *                                                     accreditation; inactive/
 *                                                     foreign main → 404
 *
 * Auth (auth:api, `throttle:apply` — the same per-user bucket as the main
 * apply):
 *   POST /api/sub-accreditations/{id}/apply  create one requested
 *                                            sub-application — main
 *                                            dependency (approved main
 *                                            application required), deadline
 *                                            window, duplicate guard, quota
 *                                            NOT enforced (overbooking
 *                                            allowed, the P3d allocation
 *                                            decides)
 */
class SubAccreditationController extends Controller
{
    public function index(Request $request, Accreditation $accreditation): AnonymousResourceCollection
    {
        $mandant = $this->currentMandant();

        $accreditation = Accreditation::query()
            ->forMandant($mandant->id)
            ->active()
            ->findOrFail($accreditation->id);

        $subs = SubAccreditation::query()
            ->where('accreditation_id', $accreditation->id)
            ->active()
            ->withCount('subApplications')
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        return SubAccreditationResource::collection($subs);
    }

    public function apply(Request $request, SubAccreditation $sub): JsonResponse
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        // (1) The sub-accreditation must exist in the current mandant (its
        // main accreditation decides the mandant) and both it and its main
        // accreditation must be active, otherwise it does not exist here
        // (404).
        $sub = SubAccreditation::query()
            ->whereKey($sub->id)
            ->whereHas('accreditation', fn (Builder $q) => $q->forMandant($mandant->id)->active())
            ->first();

        abort_if($sub === null || ! $sub->active, 404, 'Sub-accreditation not found.');

        // (2) Main dependency (D9): a sub-application is only possible on top
        // of an approved main application for the sub's accreditation. With
        // several approved rows for the same accreditation the earliest by id
        // wins.
        $application = Application::query()
            ->where('accreditation_id', $sub->accreditation_id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->orderBy('id')
            ->first();

        abort_if($application === null, 422, 'Approve the main accreditation first.');

        // (3) Deadline window (Carbon, no SQL date arithmetic). A window runs
        // from 00:00:00 of `deadline_start` through 23:59:59 of
        // `deadline_end` (the day counts in full).
        if ($sub->deadline_start !== null && now()->lt($sub->deadline_start->startOfDay())) {
            abort(422, 'Applications for this sub-accreditation are not open yet.');
        }

        if ($sub->deadline_end !== null && now()->gt($sub->deadline_end->endOfDay())) {
            abort(422, 'The application deadline for this sub-accreditation has passed.');
        }

        // (4) Duplicate guard: the unique (sub_accreditation_id,
        // application_id) constraint is the authoritative stop — the explicit
        // check yields a clean 422, the catch covers the race where both
        // queries slip through.
        $duplicate = SubApplication::query()
            ->where('sub_accreditation_id', $sub->id)
            ->where('application_id', $application->id)
            ->exists();

        if ($duplicate) {
            abort(422, 'You have already applied for this sub-accreditation.');
        }

        // (5) Quota is deliberately NOT enforced here — overbooking is
        // allowed, the P3d allocation engine decides who receives a slot.
        try {
            $subApplication = SubApplication::create([
                'sub_accreditation_id' => $sub->id,
                'application_id' => $application->id,
                'user_id' => $user->id,
                'status' => 'requested',
                'priority' => false,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                abort(422, 'You have already applied for this sub-accreditation.');
            }

            throw $e;
        }

        $subApplication->load([
            'subAccreditation',
            'subAccreditation.accreditation.category',
            'subAccreditation.accreditation.event',
        ]);

        return (new SubApplicationResource($subApplication))
            ->response()
            ->setStatusCode(201);
    }

    private function currentMandant(): Mandant
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        return $mandant;
    }
}

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
 *
 * ## The five `apply` refusals are catalog strings
 *
 * `sub_accreditations.*` in `lang/{de,en}/messages.php`, negotiated by
 * `SetRequestLocale`, same mechanism as the main-row twin in
 * `AccreditationController`. All five were English literals while its sibling
 * three hundred lines away had already been localized — this action was written
 * as a near-copy, in a different session, and the SPA reads both through ONE
 * call site (`MyAccreditationsPage` renders `err.message` verbatim on the sub
 * button), so the miss was invisible in the main-row specs.
 *
 * A SEPARATE group rather than `accreditations.*`: every sentence here is about
 * the sub row, and the main-row keys would name the wrong one. The duplicate
 * guard uses ONE key at both of its `abort()`s (the explicit check and the
 * `UNIQUE` race catch below it) for the same reason as over there — they state
 * one fact, and two keys would let the two answers drift apart.
 *
 * `not_found` is not a refusal the applicant caused: it answers 404 for a sub
 * that is inactive, or whose main accreditation is. It is a catalog string
 * anyway, because that body reaches the same `err.message` the user reads.
 *
 * It is NOT the body for a foreign sub or a non-existent id — `SubAccreditation`
 * scopes its route binding to the current mandant, so those two 404 from the
 * binding with Laravel's own wording and never reach this method (measured; see
 * `SubAccreditationTest::test_sub_apply_inactive_or_foreign_sub_is_404`).
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

        abort_if($sub === null || ! $sub->active, 404, __('messages.sub_accreditations.not_found'));

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

        abort_if($application === null, 422, __('messages.sub_accreditations.main_not_approved'));

        // (3) Deadline window (Carbon, no SQL date arithmetic). A window runs
        // from 00:00:00 of `deadline_start` through 23:59:59 of
        // `deadline_end` (the day counts in full).
        if ($sub->deadline_start !== null && now()->lt($sub->deadline_start->startOfDay())) {
            abort(422, __('messages.sub_accreditations.not_open_yet'));
        }

        if ($sub->deadline_end !== null && now()->gt($sub->deadline_end->endOfDay())) {
            abort(422, __('messages.sub_accreditations.deadline_passed'));
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
            abort(422, __('messages.sub_accreditations.already_applied'));
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
                abort(422, __('messages.sub_accreditations.already_applied'));
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

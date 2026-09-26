<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Meine Akkreditierungen" (P3b): the current user's applications within the
 * current mandant.
 *
 *   GET /api/applications          own applications, newest first
 *   DELETE /api/applications/{id}  withdraw an own application while it is
 *                                  still `requested`; an already-decided
 *                                  application cannot be withdrawn (422), one
 *                                  that gets decided WHILE the request is in
 *                                  flight answers 409 (the row is kept, the
 *                                  caller has to reload), foreign ids are 404.
 */
class ApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        $applications = Application::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->with([
                'accreditation' => fn ($query) => $query
                    ->with(['category', 'event', 'team'])
                    ->withCount('applications'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ApplicationResource::collection($applications);
    }

    public function destroy(Request $request, Application $application): Response
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        // Ownership/mandant scope first, so a foreign id is a 404 and never a
        // 409 (the conflict answer must not become an existence oracle).
        $scoped = Application::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->findOrFail($application->id);

        // The plain, non-concurrent case: the row is already decided, so the
        // withdraw is a client error (422, unchanged contract).
        if ($scoped->status !== 'requested') {
            abort(422, 'Only pending (requested) applications can be withdrawn.');
        }

        DB::transaction(function () use ($scoped): void {
            // Withdraw is a WRITE on the very rows the allocation engine
            // decides on, so it takes the engine's serialisation point: the
            // `lockForUpdate()` on the accreditation that owns the quota (R-D4).
            // Without it a concurrent approve/deny could slip between the
            // check below and the delete, and the applicant would silently lose
            // a row that was meanwhile decided.
            Accreditation::query()
                ->lockForUpdate()
                ->findOrFail($scoped->accreditation_id);

            // Re-read under that lock — the check above is already stale by the
            // time we get here. The row lock on the application itself is belt
            // and braces: the accreditation lock is the one every writer shares.
            $locked = Application::query()
                ->lockForUpdate()
                ->find($scoped->getKey());

            if ($locked === null) {
                // Already gone (withdrawn concurrently) — the caller's goal is
                // met, so this is a success, not an error.
                return;
            }

            if ($locked->status !== 'requested') {
                // 409, not 422: the row WAS withdrawable when the request
                // arrived and was decided while it was in flight. 422 would
                // blame the client for a decision the server made.
                abort(
                    Response::HTTP_CONFLICT,
                    'Die Akkreditierung wurde zwischenzeitlich entschieden und kann nicht mehr zurückgezogen werden.',
                );
            }

            $locked->delete();
        });

        return response()->noContent();
    }

    private function currentMandant(): Mandant
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        return $mandant;
    }
}

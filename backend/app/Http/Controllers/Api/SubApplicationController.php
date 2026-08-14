<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubApplicationResource;
use App\Models\Mandant;
use App\Models\SubApplication;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Meine Sub-Akkreditierungen" (P3d, D9): the current user's sub-applications
 * within the current mandant.
 *
 *   GET /api/sub-applications          own sub-applications, newest first
 *   DELETE /api/sub-applications/{id}  withdraw an own sub-application while
 *                                      it is still `requested`; approved/
 *                                      denied sub-applications cannot be
 *                                      withdrawn (422), foreign ids are 404.
 */
class SubApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        $subApplications = SubApplication::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->with([
                'subAccreditation',
                'subAccreditation.accreditation.category',
                'subAccreditation.accreditation.event',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return SubApplicationResource::collection($subApplications);
    }

    public function destroy(Request $request, SubApplication $subApplication): Response
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        $subApplication = SubApplication::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->findOrFail($subApplication->id);

        if ($subApplication->status !== 'requested') {
            abort(422, 'Only pending (requested) sub-applications can be withdrawn.');
        }

        $subApplication->delete();

        return response()->noContent();
    }

    private function currentMandant(): Mandant
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        return $mandant;
    }
}

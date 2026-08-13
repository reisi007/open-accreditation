<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Meine Akkreditierungen" (P3b): the current user's applications within the
 * current mandant.
 *
 *   GET /api/applications          own applications, newest first
 *   DELETE /api/applications/{id}  withdraw an own application while it is
 *                                  still `requested`; approved/denied
 *                                  applications cannot be withdrawn (422),
 *                                  foreign ids are 404.
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

        $application = Application::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->findOrFail($application->id);

        if ($application->status !== 'requested') {
            abort(422, 'Only pending (requested) applications can be withdrawn.');
        }

        $application->delete();

        return response()->noContent();
    }

    private function currentMandant(): Mandant
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        return $mandant;
    }
}

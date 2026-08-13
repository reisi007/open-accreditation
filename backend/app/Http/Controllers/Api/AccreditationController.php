<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationResource;
use App\Http\Resources\ApplicationResource;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Category;
use App\Models\Event;
use App\Models\Mandant;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public accreditation API (P3b) plus the authenticated apply action.
 *
 * Public (auth-free, like the portal):
 *   GET /api/accreditations            active accreditations of the current
 *                                      mandant, ordered by category name;
 *                                      optional `event_id` filter (foreign
 *                                      event → 422)
 *   GET /api/accreditations/{id}       active detail; inactive/foreign → 404
 *
 * Auth (auth:api):
 *   POST /api/accreditations/{id}/apply  create one requested application —
 *                                        deadline window, duplicate guard,
 *                                        quota NOT enforced (overbooking
 *                                        allowed, P3c allocation decides)
 */
class AccreditationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $mandant = $this->currentMandant();

        $query = Accreditation::query()
            ->forMandant($mandant->id)
            ->active()
            ->with(['category', 'event', 'team'])
            ->withCount('applications');

        if ($request->filled('event_id')) {
            $eventId = (int) $request->input('event_id');

            if (! Event::query()->forMandant($mandant->id)->whereKey($eventId)->exists()) {
                abort(422, "Event {$eventId} does not belong to the current mandant.");
            }

            $query->where('event_id', $eventId);
        }

        // Portable `ORDER BY category.name` via a correlated subquery (Postgres
        // and SQLite both support it) — no join that would duplicate rows.
        $query->orderBy(Category::select('name')->whereColumn('categories.id', 'accreditations.category_id'))
            ->orderBy('accreditations.id');

        return AccreditationResource::collection($query->get());
    }

    public function show(Accreditation $accreditation): AccreditationResource
    {
        $mandant = $this->currentMandant();

        $accreditation = Accreditation::query()
            ->forMandant($mandant->id)
            ->active()
            ->with(['category', 'event', 'team'])
            ->withCount('applications')
            ->findOrFail($accreditation->id);

        return new AccreditationResource($accreditation);
    }

    public function apply(Request $request, Accreditation $accreditation): JsonResponse
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        // (1) Accreditation must be active and live in the current mandant,
        // otherwise it does not exist here (404).
        $accreditation = Accreditation::query()
            ->forMandant($mandant->id)
            ->active()
            ->findOrFail($accreditation->id);

        // (2) Deadline window (Carbon, no SQL date arithmetic). A window runs
        // from 00:00:00 of `deadline_start` through 23:59:59 of
        // `deadline_end` (the day counts in full).
        if ($accreditation->deadline_start !== null && now()->lt($accreditation->deadline_start->startOfDay())) {
            abort(422, 'Applications for this accreditation are not open yet.');
        }

        if ($accreditation->deadline_end !== null && now()->gt($accreditation->deadline_end->endOfDay())) {
            abort(422, 'The application deadline for this accreditation has passed.');
        }

        // (3) Duplicate guard: the unique (accreditation_id, user_id) constraint
        // is the authoritative stop — the explicit check yields a clean 422,
        // the catch covers the race where both queries slip through.
        $duplicate = Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($duplicate) {
            abort(422, 'You have already applied for this accreditation.');
        }

        // (4) Quota is deliberately NOT enforced here — overbooking is allowed,
        // the P3c allocation engine decides who receives a slot.
        try {
            $application = Application::create([
                'accreditation_id' => $accreditation->id,
                'user_id' => $user->id,
                'status' => 'requested',
                'priority' => false,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                abort(422, 'You have already applied for this accreditation.');
            }

            throw $e;
        }

        $application->load([
            'accreditation' => fn ($query) => $query
                ->with(['category', 'event', 'team'])
                ->withCount('applications'),
        ]);

        return (new ApplicationResource($application))
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

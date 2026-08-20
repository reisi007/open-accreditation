<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\MandantPublicResource;
use App\Http\Resources\PortalEventDetailResource;
use App\Http\Resources\PortalEventResource;
use App\Models\Event;
use App\Models\Mandant;
use App\Models\Team;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public portal API (P3a): mandant overview, event calendar and event detail.
 * Auth-free by design — the portal is the public landing surface. Every
 * action is scoped to the current mandant from `MandantContext`; an
 * unknown/absent mandant is a 404 (production: MandantContextMiddleware).
 */
class PortalController extends Controller
{
    /**
     * Public mandant + team overview of the current mandant.
     */
    public function overview(): JsonResponse
    {
        $mandant = $this->currentMandant();

        $teamsEnabled = (bool) $mandant->teams_enabled && (bool) $mandant->is_active;

        return response()->json([
            'data' => [
                'mandant' => new MandantPublicResource($mandant),
                'teams' => $teamsEnabled
                    ? $mandant->teams
                        ->map(fn (Team $team): array => [
                            'id' => $team->id,
                            'name' => $team->name,
                            'home_venue' => $team->home_venue,
                        ])
                        ->values()
                        ->all()
                    : [],
            ],
        ]);
    }

    /**
     * All active events of the current mandant, ordered by date ASC.
     * Filterable by `team_id` (must belong to the current mandant, else 422)
     * and `competition` (partial, portably LIKE-escaped).
     */
    public function events(Request $request): AnonymousResourceCollection
    {
        $mandant = $this->currentMandant();

        $query = Event::query()
            ->forMandant($mandant->id)
            ->active()
            ->with('team');

        if ($request->filled('team_id')) {
            $teamId = (int) $request->input('team_id');

            if (! Team::query()->forMandant($mandant->id)->whereKey($teamId)->exists()) {
                abort(422, "Team {$teamId} does not belong to the current mandant.");
            }

            $query->forTeam($teamId);
        }

        if ($request->filled('competition')) {
            $term = $this->escapeLike((string) $request->input('competition'));
            // Portable LIKE: `LOWER()` on both sides pins case-insensitive
            // search (Postgres LIKE is case-sensitive, SQLite is not), and
            // `ESCAPE '\'` keeps wildcard escaping identical on both engines
            // (SQLite has no default escape character). The user input stays
            // a bound parameter — the raw part is the constant clause only.
            $query->whereRaw("LOWER(competition) like LOWER(?) escape '\\'", ["%{$term}%"]);
        }

        return PortalEventResource::collection(
            $query->orderBy('date')->orderBy('id')->get(),
        );
    }

    /**
     * Detail of one active event of the current mandant. Inactive events and
     * events of another mandant are 404.
     */
    public function show(Event $event): PortalEventDetailResource
    {
        $mandant = $this->currentMandant();

        $event = Event::query()
            ->forMandant($mandant->id)
            ->active()
            ->with('team')
            ->findOrFail($event->id);

        return new PortalEventDetailResource($event, $mandant);
    }

    private function currentMandant(): Mandant
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        return $mandant;
    }

    /**
     * Escape LIKE wildcards so a `competition` search for literal `%`/`_`
     * does not act as a pattern. The caller applies the escaped term with an
     * explicit `ESCAPE '\'` clause (portable across Postgres and SQLite).
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

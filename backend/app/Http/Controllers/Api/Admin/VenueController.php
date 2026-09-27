<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\VenueResource;
use App\Models\Venue;
use App\Rules\ValidUtf8;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * W12 admin CRUD for venues (Spielstätten) — mandant-wide master data shared by
 * teams and events. Guarded by `can:venues.manage` (super_admin,
 * mandant_admin, team_admin), mirroring the categories surface: a venue row is
 * never team-owned, so the surface is mandant-scoped rather than team-scoped.
 * The team_admin grant exists for the *picker*, not as a privilege — the
 * combobox in the team and the event form reads this index and creates inline,
 * and a team_admin may already edit both forms (`teams.manage` /
 * `events.manage`).
 *
 * Lifecycle, the whole point of the table:
 * - **Deactivate, never delete, while referenced.** A venue that a team or an
 *   event still points at stays in the database with `is_active = false`; the
 *   name keeps resolving, so historical rows are never orphaned.
 * - **Delete is the escape hatch** for an unreferenced (typically mistyped
 *   empty) entry: 204 when nothing references it, 409 naming the reference
 *   counts when something does.
 * - The name is unique per mandant and a *deactivated* name stays taken — the
 *   row is reactivated, never duplicated. Mirrored by the controller's
 *   `Rule::unique`, so a duplicate answers a 422 with a German message instead
 *   of surfacing a raw DB constraint violation.
 */
class VenueController extends Controller
{
    use ResolvesAdminTeamScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Venue::query()
            ->forMandant($this->currentMandantId())
            ->withCount(['teams', 'events']);

        return VenueResource::collection($query->orderBy('name')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(forCreate: true));

        $venue = Venue::create([
            ...$validated,
            'mandant_id' => $this->currentMandantId(),
        ]);

        // `refresh()` so the resource serializes the *stored* row: `is_active`
        // is a DB column default, so the freshly created instance does not
        // carry it yet. Reading the default back keeps the schema the single
        // source of truth instead of hardcoding `true` in the controller.
        return (new VenueResource($venue->refresh()->loadCount(['teams', 'events'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Venue $venue): VenueResource
    {
        $venue = $this->assertMandantScope($venue, $this->currentMandantId());

        $venue->update($request->validate($this->rules(venue: $venue)));

        return new VenueResource($venue->fresh());
    }

    public function destroy(Request $request, Venue $venue): Response
    {
        $venue = $this->assertMandantScope($venue, $this->currentMandantId());

        $teamsCount = $venue->teams()->count();
        $eventsCount = $venue->events()->count();

        // "Deactivate instead of delete" is the policy; this is the first line
        // of it. The DB `restrict` on both FKs is the last one.
        if ($teamsCount > 0 || $eventsCount > 0) {
            return response()->json([
                'message' => $this->referencedMessage($teamsCount, $eventsCount),
            ], 409);
        }

        $venue->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $forCreate = false, ?Venue $venue = null): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        return [
            'name' => [
                $main,
                'string',
                'max:255',
                new ValidUtf8,
                Rule::unique('venues', 'name')
                    ->where('mandant_id', $venue?->mandant_id ?? $this->currentMandantId())
                    ->ignore($venue?->id),
            ],
            // `is_active: false` deactivates, `true` reactivates — the same row,
            // never a new one.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function referencedMessage(int $teamsCount, int $eventsCount): string
    {
        $parts = [];

        if ($teamsCount > 0) {
            $parts[] = $teamsCount === 1 ? '1 Verein' : $teamsCount.' Vereine';
        }

        if ($eventsCount > 0) {
            $parts[] = $eventsCount === 1 ? '1 Event' : $eventsCount.' Events';
        }

        // "Spielort", not "Spielstätte": this string is shown verbatim by the
        // admin UI, whose nav entry, page heading and every one of the 27 new
        // i18n keys say "Spielort". "Spielstätte" is the model-docblock
        // wording. Mixed vocabulary here would surface as a heading reading
        // "Spielorte" above an error reading "Spielstätte wird noch von …".
        return 'Spielort wird noch von '.implode(' und ', $parts)
            .' verwendet und kann nicht gelöscht werden. Bitte stattdessen deaktivieren.';
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesMandantRouteParameter;
use App\Http\Controllers\Controller;
use App\Http\Resources\VenueResource;
use App\Models\Mandant;
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
 *
 * ## Two surfaces, one scope rule
 *
 * The host-scoped routes (`/api/admin/venues`) resolve their mandant from
 * `MandantContext` — the request HOST. That is right for every page that lives
 * *on* a mandant's domain (categories, events, accreditations, `VenuesPage`)
 * and wrong for the one admin page that explicitly addresses a mandant by URL:
 * `/admin/mandants/{id}` loads its teams through `{mandant}` but its venue
 * combobox went through the host. The picker then offered the host mandant's
 * venues and its inline create WROTE the row into the host mandant without an
 * error — only the team save afterwards failed (404, `TeamController::
 * assertVenueOfMandant`). A silent write into another tenant is the defect the
 * `{mandant}`-addressed routes below close.
 *
 * The addressed surface changes the SCOPE, not the reach:
 * - super_admin may address any mandant from any host (early return in
 *   `assertMandantRouteParameter()`) — that branch is the reason the mandant
 *   detail page works for him at all, and it must not be tightened.
 * - everyone else may only address the mandant he is already on, else 404. So a
 *   mandant_admin / team_admin gets exactly the venues the host-scoped route
 *   already gave him, and the `venues.manage` gate keeps its meaning (it is
 *   still held for the picker, see the class docblock).
 *
 * Both surfaces share the private `*ForMandantId()` workers below, so the
 * derived counts, the unique rule and the 409 delete policy cannot drift apart
 * between them.
 */
class VenueController extends Controller
{
    use ResolvesAdminTeamScope;
    use ResolvesMandantRouteParameter;

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listForMandantId($this->currentMandantId());
    }

    public function indexForMandant(Request $request, Mandant $mandant): AnonymousResourceCollection
    {
        $this->assertMandantRouteParameter($request, $mandant);

        return $this->listForMandantId((int) $mandant->id);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->createForMandantId($request, $this->currentMandantId());
    }

    public function storeForMandant(Request $request, Mandant $mandant): JsonResponse
    {
        $this->assertMandantRouteParameter($request, $mandant);

        return $this->createForMandantId($request, (int) $mandant->id);
    }

    public function update(Request $request, Venue $venue): VenueResource
    {
        return $this->updateVenue($request, $this->assertMandantScope($venue, $this->currentMandantId()));
    }

    public function updateForMandant(Request $request, Mandant $mandant, string $venue): VenueResource
    {
        $this->assertMandantRouteParameter($request, $mandant);

        // Resolved THROUGH the mandant, so a venue of another tenant is a 404
        // by construction — not a check that could be forgotten.
        return $this->updateVenue($request, $mandant->venues()->findOrFail((int) $venue));
    }

    public function destroy(Request $request, Venue $venue): Response
    {
        return $this->deleteVenue($this->assertMandantScope($venue, $this->currentMandantId()));
    }

    public function destroyForMandant(Request $request, Mandant $mandant, string $venue): Response
    {
        $this->assertMandantRouteParameter($request, $mandant);

        return $this->deleteVenue($mandant->venues()->findOrFail((int) $venue));
    }

    /**
     * The venues of one mandant, with the reference counts a delete would
     * destroy. Ordered by name — the combobox filters client-side and the admin
     * page paginates the same array.
     */
    private function listForMandantId(int $mandantId): AnonymousResourceCollection
    {
        $query = Venue::query()
            ->forMandant($mandantId)
            ->withCount(['teams', 'events']);

        return VenueResource::collection($query->orderBy('name')->orderBy('id')->get());
    }

    /**
     * Creates a venue IN `$mandantId` — the host on the host-scoped route, the
     * route parameter on the addressed one. A payload `mandant_id` is never
     * fillable-visible here (only `name` / `is_active` are validated), and the
     * unique rule is scoped to the same id, so a duplicate answers 422 instead
     * of hitting the composite unique index.
     */
    private function createForMandantId(Request $request, int $mandantId): JsonResponse
    {
        $validated = $request->validate($this->rules($mandantId, forCreate: true));

        $venue = Venue::create([
            ...$validated,
            'mandant_id' => $mandantId,
        ]);

        // `refresh()` so the resource serializes the *stored* row: `is_active`
        // is a DB column default, so the freshly created instance does not
        // carry it yet. Reading the default back keeps the schema the single
        // source of truth instead of hardcoding `true` in the controller.
        return (new VenueResource($venue->refresh()->loadCount(['teams', 'events'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename / (de)activate an already mandant-scoped row. The unique rule
     * reads the row's OWN mandant, so a rename can never be judged against
     * another tenant's names.
     */
    private function updateVenue(Request $request, Venue $venue): VenueResource
    {
        $venue->update($request->validate($this->rules((int) $venue->mandant_id, venue: $venue)));

        return new VenueResource($venue->fresh());
    }

    /**
     * "Deactivate instead of delete" (see the class docblock): 409 with the
     * reference counts while a team or an event points at the row, 204 once
     * nothing does.
     */
    private function deleteVenue(Venue $venue): Response
    {
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
    private function rules(int $mandantId, bool $forCreate = false, ?Venue $venue = null): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        return [
            'name' => [
                $main,
                'string',
                'max:255',
                new ValidUtf8,
                Rule::unique('venues', 'name')
                    ->where('mandant_id', $venue?->mandant_id ?? $mandantId)
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

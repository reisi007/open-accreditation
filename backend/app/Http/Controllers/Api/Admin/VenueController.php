<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesMandantRouteParameter;
use App\Http\Controllers\Controller;
use App\Http\Resources\VenueResource;
use App\Models\Mandant;
use App\Models\Team;
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
 * mandant_admin, team_admin), mirroring the categories surface: a venue row
 * carries no team level of its own, so the surface is mandant-scoped. The
 * team_admin grant exists for the *picker*, not as a privilege — the combobox
 * in the team and the event form reads this index and creates inline, and a
 * team_admin may already edit both forms (`teams.manage` / `events.manage`).
 * What that grant does NOT carry is the right to modify a venue his own teams
 * do not use: see "The team_admin write scope" below.
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
 *
 * ## The team_admin write scope (read + create stay mandant-wide)
 *
 * `venues.manage` is held by team_admin for the PICKER, not as a privilege, and
 * the scope follows that split unevenly on purpose:
 *
 * - **index / store — mandant-wide, for every holder of the gate.** The
 *   combobox in the team form has to offer the whole Verband's venues (a club
 *   plays its derby somewhere else), and the inline create next to it must not
 *   dead-end a Verband that has no venues yet. This is why the gate mirrors
 *   `categories.manage` row for row.
 * - **update / destroy — only venues the caller's own team(s) use**
 *   (`assertWritableBy()` below). A venue row is SHARED master data that
 *   `venues` itself does not team-own; renaming or deactivating a neighbour
 *   club's venue changes what that neighbour sees on its own pages without ever
 *   having asked it.
 *
 * The two guards are deliberately asymmetric: an unreferenced venue is *not*
 * let through as "harmless", because a deactivated name stays taken for good
 * and would let a team_admin squat names mandant-wide. That power stays at the
 * mandant level. See `assertWritableBy()` for the predicate, the 403 choice and
 * why only `teams.venue_id` counts.
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
        return $this->deleteVenue($request, $this->assertMandantScope($venue, $this->currentMandantId()));
    }

    public function destroyForMandant(Request $request, Mandant $mandant, string $venue): Response
    {
        $this->assertMandantRouteParameter($request, $mandant);

        return $this->deleteVenue($request, $mandant->venues()->findOrFail((int) $venue));
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
        $this->assertWritableBy($request, $venue);

        $venue->update($request->validate($this->rules((int) $venue->mandant_id, venue: $venue)));

        return new VenueResource($venue->fresh());
    }

    /**
     * "Deactivate instead of delete" (see the class docblock): 409 with the
     * reference counts while a team or an event points at the row, 204 once
     * nothing does.
     */
    private function deleteVenue(Request $request, Venue $venue): Response
    {
        $this->assertWritableBy($request, $venue);

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
     * A team_admin may only MODIFY a venue one of his own teams uses. The
     * `assertOwnership()` analogue of `ResolvesAdminTeamScope` — same shape,
     * same empty-means-unrestricted short-circuit, same 403 on the team axis —
     * but a DIFFERENT predicate, because the data model is a different one:
     *
     * - A `Category` carries its level in a `team_id` column, so "mine" is
     *   `team_id ∈ teamIds` and mandant-level rows (`team_id = null`) are
     *   read-only for him.
     * - A `Venue` has NO `team_id` at all. It is mandant-wide master data that
     *   teams point at (`teams.venue_id`). Handing `assertOwnership()` a venue
     *   would read a `null` attribute and refuse EVERY write — including the
     *   one on the venue he legitimately owns. So "mine" here is the
     *   intersection of the venue's team set with the caller's teams.
     *
     * Only `teams.venue_id` counts, never `events.venue_id`: the former is the
     * club's standing home ground ("this venue is my club's"), the latter a
     * single fixture's assignment. An event at a stadium must not hand its
     * admin a rename/deactivate right over the whole Verband's venue.
     *
     * Unreferenced venues are NOT let through as "harmless": a deactivated name
     * stays taken for good (`unique(mandant_id, name)` deliberately ignores
     * `is_active`), so touching one would let a team_admin squat names
     * mandant-wide. That stays the mandant level's job.
     *
     * ## Why 403 and not 404
     *
     * The mandant axis is already settled before this runs and answers 404
     * (`assertMandantScope()` on the host-scoped routes, the `findOrFail()`
     * through `$mandant->venues()` on the addressed ones). What fails here is
     * the *team* axis, and the codebase reserves 404 for the mandant axis and
     * 403 for the team/role axis (`assertOwnership()`,
     * `assertAccreditationFilter()`, `authorizeSuperAdmin()`). A venue in his
     * own mandant is not hidden from him — he can see it in the index, that is
     * the point of the mandant-wide read — so 404 would be a lie about a row
     * he demonstrably can see, and would collide with the 404 the foreign-mandant
     * case must keep. 403 is the honest code and the consistent one.
     *
     * ## Both surfaces, by construction
     *
     * Called from the two private workers `updateVenue()` / `deleteVenue()`,
     * which BOTH the host-scoped routes and the mandant-adressed routes below
     * delegate to. A guard in the worker cannot drift between the surfaces the
     * way a per-action-method guard could — which is exactly the bypass a
     * narrower placement would have left open.
     */
    private function assertWritableBy(Request $request, Venue $venue): void
    {
        $teamIds = $this->teamIds($request);

        if ($teamIds === []) {
            return;
        }

        // `forMandant()` is redundant while `teams.venue_id` can only point
        // inside the team row's own mandant, and stays that way if that
        // invariant is ever loosened: the caller's teams are in the current
        // mandant, so the mandant column is stated rather than assumed.
        abort_unless(
            Team::query()
                ->forMandant((int) $venue->mandant_id)
                ->whereIn('id', $teamIds)
                ->where('venue_id', $venue->id)
                ->exists(),
            403,
            'You may only manage venues your own teams use.',
        );
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

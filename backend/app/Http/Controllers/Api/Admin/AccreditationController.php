<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationResource;
use App\Models\Accreditation;
use App\Models\Category;
use App\Models\Event;
use App\Services\AllocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for accreditations (Akkreditierungen) of the current mandant.
 * Guarded by `can:accreditations.manage` (super_admin, mandant_admin,
 * team_admin).
 *
 * `team_id = null` → mandant-level, set → team-level. super_admin /
 * mandant_admin manage both levels; team_admin only accreditations of his own
 * team(s) (a `?team_id` param must match one of them, otherwise 403; a foreign
 * payload `team_id` is forced back onto his team, see `resolveTeamId`).
 *
 * Cross-field ownership: `category_id` must be a mandant-level category or —
 * for a team_admin — one of his own team's categories; `event_id` is required
 * for `scope=event`, forbidden otherwise, and must belong to the mandant (or
 * the team_admin's own team/Verband level).
 */
class AccreditationController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly AllocationService $allocationService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $query = Accreditation::query()
            ->forMandant($mandantId)
            ->with(['category', 'event', 'team'])
            ->withCount('applications');

        if ($teamIds !== []) {
            $query->whereIn('team_id', $teamIds);

            if ($request->filled('team_id')) {
                abort_unless(in_array((int) $request->input('team_id'), $teamIds, true), 403);
            }
        } elseif ($request->filled('team_id')) {
            $teamId = (int) $request->input('team_id');
            $this->assertTeamOfMandant($teamId, $mandantId);
            $query->forTeam($teamId);
        }

        if (($active = $this->activeFilter($request)) !== null) {
            $query->active($active);
        }

        return AccreditationResource::collection($query->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $validated = $request->validate($this->rules($request, forCreate: true));

        $this->assertCategoryAvailable((int) $validated['category_id'], $mandantId, $teamIds);
        $this->assertEventPayload($validated, $mandantId, $teamIds);

        $accreditation = Accreditation::create([
            ...$validated,
            'mandant_id' => $mandantId,
            'team_id' => $this->resolveTeamId($validated, $mandantId, $teamIds),
        ]);

        // DB defaults (`active`, `auto_approve`) only exist in the row — the
        // in-memory instance needs a refresh before it is serialized.
        $accreditation->refresh();

        return (new AccreditationResource(
            $accreditation->load(['category', 'event', 'team'])->loadCount('applications'),
        ))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Accreditation $accreditation): AccreditationResource
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $validated = $request->validate($this->rules($request, $accreditation));

        if (array_key_exists('category_id', $validated)) {
            $this->assertCategoryAvailable((int) $validated['category_id'], $mandantId, $teamIds);
        }

        $this->assertEventPayload($validated, $mandantId, $teamIds, $accreditation);

        $payload = [...$validated, 'team_id' => $this->resolveTeamId($validated, $mandantId, $teamIds, $accreditation)];

        // Switching away from `scope=event` must clear the orphaned event link.
        if (array_key_exists('scope', $validated) && $validated['scope'] !== 'event') {
            $payload['event_id'] = null;
        }

        $accreditation->update($payload);

        return new AccreditationResource(
            $accreditation->fresh(['category', 'event', 'team'])->loadCount('applications'),
        );
    }

    public function destroy(Request $request, Accreditation $accreditation): Response
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $accreditation->delete();

        return response()->noContent();
    }

    /**
     * Run the P3c allocation engine on one accreditation (manual trigger):
     * `mode=all` approves every eligible application up to the quota,
     * `mode=first` approves only the first `limit` candidates. A `limit` is
     * required for `mode=first` and ignored for `mode=all`.
     */
    public function allocate(Request $request, Accreditation $accreditation): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $rules = ['mode' => ['required', Rule::in(['all', 'first'])]];

        if ($request->input('mode') === 'first') {
            $rules['limit'] = ['required', 'integer', 'min:1'];
        }

        $validated = $request->validate($rules);

        $result = $validated['mode'] === 'all'
            ? $this->allocationService->approveAllEligible($accreditation)
            : $this->allocationService->approveSelection($accreditation, (int) $validated['limit']);

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, ?Accreditation $accreditation = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        $rules = [
            'category_id' => [$main, 'integer'],
            'scope' => [$main, Rule::in(['event', 'league', 'season'])],
            'event_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'quota' => [$main, 'integer', 'min:1'],
            'deadline_start' => ['nullable', 'date'],
            'deadline_end' => ['nullable', 'date'],
            'auto_approve' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];

        // `deadline_end` must follow `deadline_start` — equal dates are allowed
        // (a single-day window). Enforced only when both arrive together (the
        // P2b EventController pattern).
        if ($request->filled('deadline_start')) {
            $rules['deadline_end'][] = 'after_or_equal:deadline_start';
        }

        return $rules;
    }

    /**
     * A category is usable when it belongs to the current mandant and, for a
     * team_admin, is either a mandant-level category or one of his own team's.
     */
    private function assertCategoryAvailable(int $categoryId, int $mandantId, array $teamIds): void
    {
        $query = Category::query()->forMandant($mandantId)->whereKey($categoryId);

        if ($teamIds !== []) {
            $query->where(fn (Builder $q) => $q->whereNull('team_id')->orWhereIn('team_id', $teamIds));
        }

        if (! $query->exists()) {
            throw ValidationException::withMessages([
                'category_id' => 'The selected category is not available for this mandant/team scope.',
            ]);
        }
    }

    /**
     * Scope-dependent event validation: `scope=event` requires an `event_id`
     * that belongs to the mandant (team_admin: Verband-level or own team);
     * `league`/`season` reject a set `event_id`. For partial updates the
     * effective scope falls back to the existing row.
     */
    private function assertEventPayload(array $validated, int $mandantId, array $teamIds, ?Accreditation $accreditation = null): void
    {
        $scope = $validated['scope'] ?? $accreditation?->scope;

        if ($scope === 'event') {
            if (! array_key_exists('event_id', $validated) || $validated['event_id'] === null) {
                throw ValidationException::withMessages([
                    'event_id' => 'An event (event_id) is required when scope is "event".',
                ]);
            }

            $query = Event::query()->forMandant($mandantId)->whereKey((int) $validated['event_id']);

            if ($teamIds !== []) {
                $query->where(fn (Builder $q) => $q->whereNull('team_id')->orWhereIn('team_id', $teamIds));
            }

            if (! $query->exists()) {
                throw ValidationException::withMessages([
                    'event_id' => 'The selected event is not available for this mandant/team scope.',
                ]);
            }

            return;
        }

        if (array_key_exists('event_id', $validated) && $validated['event_id'] !== null) {
            throw ValidationException::withMessages([
                'event_id' => 'event_id must be empty for scope "'.$scope.'".',
            ]);
        }
    }

    private function activeFilter(Request $request): ?bool
    {
        if (! $request->has('active')) {
            return null;
        }

        return filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);
    }
}

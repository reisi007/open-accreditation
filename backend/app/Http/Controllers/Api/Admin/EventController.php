<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for events (Events/Spiele) of the current mandant. Guarded by
 * `can:events.manage` (super_admin, mandant_admin, team_admin).
 *
 * team_admin is restricted to his own team's events for every action
 * (index/store/update/delete); mandant-level events (`team_id = null`) are
 * super_admin/mandant_admin only. A `?team_id` param from a team_admin must
 * match one of his own teams, otherwise 403.
 */
class EventController extends Controller
{
    use ResolvesAdminTeamScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $query = Event::query()
            ->forMandant($mandantId)
            ->with('team');

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

        return EventResource::collection($query->orderBy('title')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $validated = $request->validate($this->rules($request, forCreate: true));

        $event = Event::create([
            ...$validated,
            'mandant_id' => $mandantId,
            'team_id' => $this->resolveTeamId($validated, $mandantId, $teamIds),
        ]);

        return (new EventResource($event->fresh('team')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Event $event): EventResource
    {
        $mandantId = $this->currentMandantId();
        $event = $this->assertMandantScope($event, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($event, $teamIds);

        $validated = $request->validate($this->rules($request, $event));

        $event->update([
            ...$validated,
            'team_id' => $this->resolveTeamId($validated, $mandantId, $teamIds, $event),
        ]);

        return new EventResource($event->fresh('team'));
    }

    public function destroy(Request $request, Event $event): Response
    {
        $mandantId = $this->currentMandantId();
        $event = $this->assertMandantScope($event, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($event, $teamIds);

        $event->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, ?Event $event = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        $rules = [
            'title' => [$main, 'string', 'max:255'],
            'team_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'competition' => ['nullable', 'string', 'max:255'],
            'deadline_start' => ['nullable', 'date'],
            'deadline_end' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ];

        // P2b-F3: `deadline_end` must follow `deadline_start` — equal dates
        // are allowed (a single-day registration window). Enforced only when
        // both arrive in the same payload — a partial update touching one of
        // them cannot be compared without reading the row.
        if ($request->filled('deadline_start')) {
            $rules['deadline_end'][] = 'after_or_equal:deadline_start';
        }

        return $rules;
    }

    private function activeFilter(Request $request): ?bool
    {
        if (! $request->has('active')) {
            return null;
        }

        return filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);
    }
}

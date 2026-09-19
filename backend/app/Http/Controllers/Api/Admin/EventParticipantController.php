<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventParticipantResource;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\RoleUser;
use App\Rules\ValidUtf8;
use App\Support\MandantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for the participant slots of an event (W4).
 *
 *   GET    /api/admin/events/{event}/participants
 *   POST   /api/admin/events/{event}/participants   {team_id?, name?, sort_order?}
 *   PUT    /api/admin/events/{event}/participants/{participant}
 *   DELETE /api/admin/events/{event}/participants/{participant}
 *
 * Guarded by `can:events.manage` (super_admin, mandant_admin, team_admin).
 * Participants are mandant-level content: a team_admin may read them (useful
 * for the event view) but every write answers 403 (`assertMayWrite`, mirrors
 * `EventTypeController`). The event is resolved through the tenant-guarded
 * binding; the participant additionally has to belong to the event in the URL
 * (`assertParticipantOfEvent`), so a foreign mandant or a sibling event's
 * participant is 404.
 *
 * The model is cardinality-free — the same endpoints build Single (1),
 * Versus (2) and tournament (N) line-ups. A participant needs at least a
 * `team_id` or a `name`; `name` overrides the team label
 * (`name_effective` = explicit > team name > null). `team_id` must reference a
 * team of the current mandant (foreign → 404). `sort_order` defaults to the
 * next free slot; `(event_id, sort_order)` and `(event_id, team_id)` are
 * unique (both portable indexes; the latter treats null team ids as distinct,
 * so any number of placeholders coexist).
 */
class EventParticipantController extends Controller
{
    use ResolvesAdminTeamScope;

    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $this->assertEventOfMandant($event, $mandantId);

        return EventParticipantResource::collection(
            $event->participants()->with('team')->get(),
        );
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $this->assertEventOfMandant($event, $mandantId);
        $this->assertMayWrite($request);

        $validated = $this->validatePayload($request, $event);

        try {
            $participant = $event->participants()->create([
                ...$validated,
                'sort_order' => $validated['sort_order'] ?? $this->nextSortOrder($event),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // W4-F2: two concurrent requests may compute the same next free
            // slot (and the team duplicate pre-check races the same way). The
            // portable unique indexes then fire — surface a 422 instead of a
            // generic 500. The constraint name/message names the offending
            // column on both Postgres and SQLite.
            $key = str_contains($exception->getMessage(), 'team_id') ? 'team_id' : 'sort_order';

            throw ValidationException::withMessages([
                $key => $key === 'team_id'
                    ? 'This team is already a participant of the event.'
                    : 'This sort order is already taken.',
            ]);
        }

        return (new EventParticipantResource($participant->fresh('team')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Event $event, EventParticipant $participant): EventParticipantResource
    {
        $mandantId = $this->currentMandantId();
        $this->assertEventOfMandant($event, $mandantId);
        $this->assertMayWrite($request);
        $this->assertParticipantOfEvent($participant, $event);

        $participant->update($this->validatePayload($request, $event, $participant));

        return new EventParticipantResource($participant->fresh('team'));
    }

    public function destroy(Request $request, Event $event, EventParticipant $participant): Response
    {
        $mandantId = $this->currentMandantId();
        $this->assertEventOfMandant($event, $mandantId);
        $this->assertMayWrite($request);
        $this->assertParticipantOfEvent($participant, $event);

        $participant->delete();

        return response()->noContent();
    }

    /**
     * Validate the participant payload and enforce the invariants that span
     * multiple fields (at least one label source, mandant-owned team, unique
     * team per event, unique sort slot).
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, Event $event, ?EventParticipant $participant = null): array
    {
        $forCreate = $participant === null;

        $validated = $request->validate([
            'name' => $forCreate
                ? ['nullable', 'string', 'max:255', 'required_without:team_id', new ValidUtf8]
                : ['sometimes', 'nullable', 'string', 'max:255', new ValidUtf8],
            'team_id' => $forCreate
                ? ['nullable', 'integer', 'required_without:name']
                : ['sometimes', 'nullable', 'integer'],
            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                Rule::unique('event_participants', 'sort_order')
                    ->where('event_id', $event->id)
                    ->ignore($participant?->id),
            ],
        ]);

        $teamId = array_key_exists('team_id', $validated) ? $validated['team_id'] : $participant?->team_id;
        $name = array_key_exists('name', $validated) ? $validated['name'] : $participant?->name;

        if ($teamId === null && ($name === null || $name === '')) {
            throw ValidationException::withMessages([
                'name' => 'A participant needs either a team or a name.',
            ]);
        }

        if ($teamId !== null) {
            $this->assertTeamOfMandant((int) $teamId, $this->currentMandantId());
            $this->assertTeamNotYetParticipating($event, (int) $teamId, $participant);
        }

        return $validated;
    }

    /**
     * A team takes part at most once per event — mirrors the portable
     * `(event_id, team_id)` unique index with a 422 instead of a DB error.
     */
    private function assertTeamNotYetParticipating(Event $event, int $teamId, ?EventParticipant $participant): void
    {
        $duplicate = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('team_id', $teamId)
            ->when($participant !== null, static fn ($query) => $query->whereKeyNot($participant->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'team_id' => 'This team is already a participant of the event.',
            ]);
        }
    }

    /**
     * The next free display slot for a newly appended participant.
     */
    private function nextSortOrder(Event $event): int
    {
        return (int) $event->participants()->max('sort_order') + 1;
    }

    /**
     * The URL event must belong to the current mandant (the binding already
     * scopes it; this also covers the no-context case).
     */
    private function assertEventOfMandant(Event $event, int $mandantId): void
    {
        abort_unless((int) $event->mandant_id === $mandantId, 404);
    }

    /**
     * The bound participant must belong to the event in the URL — a sibling
     * event's participant is 404 (nested-resource isolation).
     */
    private function assertParticipantOfEvent(EventParticipant $participant, Event $event): void
    {
        abort_unless((int) $participant->event_id === (int) $event->id, 404);
    }

    /**
     * Participants are mandant-level: any team_admin assignment in the current
     * mandant turns a write into 403 (read stays allowed). Mirrors
     * `EventTypeController::assertMayWrite`.
     */
    private function assertMayWrite(Request $request): void
    {
        $user = $request->user();
        $mandantId = MandantContext::currentId();

        if ($user === null || $mandantId === null) {
            return;
        }

        $assignments = $user->roleAssignmentsForMandant($mandantId);

        $isTeamAdmin = $assignments->contains(
            static fn (RoleUser $assignment): bool => $assignment->role->slug === UserRole::TEAM_ADMIN->value,
        );

        abort_if($isTeamAdmin, 403, 'Event participants are managed by the Verband admin.');
    }
}

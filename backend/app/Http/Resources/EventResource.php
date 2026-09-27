<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event (Event/Spiel) for the admin API. Dates serialize as `Y-m-d`.
 *
 * W12: the location is the `venue_id` FK to the mandant's venue master data.
 * `venue` is the resolved `{id, name}` object (null when the event has no
 * location of its own — the effective location then falls back to the team's).
 */
class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mandant_id' => $this->mandant_id,
            'team_id' => $this->team_id,
            'event_type_id' => $this->event_type_id,
            'title' => $this->title,
            'date' => $this->date?->format('Y-m-d'),
            'venue_id' => $this->venue_id,
            'competition' => $this->competition,
            'deadline_start' => $this->deadline_start?->format('Y-m-d'),
            'deadline_end' => $this->deadline_end?->format('Y-m-d'),
            'active' => $this->active,
            'team' => $this->teamData(),
            'venue' => $this->venueData(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function venueData(): ?array
    {
        if ($this->venue_id === null || ! $this->relationLoaded('venue') || $this->venue === null) {
            return null;
        }

        return [
            'id' => $this->venue->id,
            'name' => $this->venue->name,
        ];
    }

    private function teamData(): ?array
    {
        if ($this->team_id === null || ! $this->relationLoaded('team') || $this->team === null) {
            return null;
        }

        return [
            'id' => $this->team->id,
            'name' => $this->team->name,
        ];
    }
}

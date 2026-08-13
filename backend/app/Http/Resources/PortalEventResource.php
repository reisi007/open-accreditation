<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event (Event/Spiel) for the public portal event calendar (P3a). Only
 * active, mandant-scoped events ever reach this resource. Dates serialize as
 * `Y-m-d`; `team` is `{id, name}` when the event belongs to a team.
 */
class PortalEventResource extends JsonResource
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
            'team_id' => $this->team_id,
            'title' => $this->title,
            'date' => $this->date?->format('Y-m-d'),
            'venue' => $this->venue,
            'competition' => $this->competition,
            'deadline_end' => $this->deadline_end?->format('Y-m-d'),
            'active' => $this->active,
            'team' => $this->teamData(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
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

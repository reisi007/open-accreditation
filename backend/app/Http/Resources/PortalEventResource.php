<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event (Event/Spiel) for the public portal event calendar (P3a). Only
 * active, mandant-scoped events ever reach this resource. Dates serialize as
 * `Y-m-d`; `team` is `{id, name}` when the event belongs to a team.
 *
 * W12: `venue` is the RESOLVED name of the event's `venue_id`, or null when
 * the event has no location of its own and the effective location falls back
 * to the team's (see `PortalEventDetailResource::venue_effective`). The portal
 * payload keeps the plain string, so a deactivated venue's name still renders
 * for historical events instead of blanking out.
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
            'venue' => $this->venue?->name,
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

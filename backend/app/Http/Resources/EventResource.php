<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event (Event/Spiel) for the admin API. Dates serialize as `Y-m-d`.
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
            'title' => $this->title,
            'date' => $this->date?->format('Y-m-d'),
            'venue' => $this->venue,
            'competition' => $this->competition,
            'deadline_start' => $this->deadline_start?->format('Y-m-d'),
            'deadline_end' => $this->deadline_end?->format('Y-m-d'),
            'active' => $this->active,
            'team' => $this->teamData(),
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

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Category (Kategorie) for the admin API. `is_team_override` marks team-level
 * rows (a team's own category that shadows a mandant-level slug).
 */
class CategoryResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_team_override' => $this->team_id !== null,
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

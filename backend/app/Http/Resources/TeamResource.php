<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a team (Verein) for the Super Admin API.
 *
 * W12: the location is the `venue_id` FK to the mandant's venue master data;
 * `venue` is the resolved `{id, name}` object (null when the team has no
 * default venue).
 */
class TeamResource extends JsonResource
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
            'slug' => $this->slug,
            'name' => $this->name,
            'venue_id' => $this->venue_id,
            'venue' => $this->venueData(),
            'logo_url' => $this->logo_path !== null
                ? route('api.admin.teams.logo', ['team' => $this->id])
                : null,
            'created_at' => $this->created_at,
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
}

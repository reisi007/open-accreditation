<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a team (Verein) for the Super Admin API.
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
            'home_venue' => $this->home_venue,
            'logo_url' => $this->logo_path !== null
                ? route('api.admin.teams.logo', ['team' => $this->id])
                : null,
            'created_at' => $this->created_at,
        ];
    }
}

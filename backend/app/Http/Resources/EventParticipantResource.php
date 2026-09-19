<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event participant (W4) for the admin API. The storage path is never exposed
 * — `logo_url` points at the auth-gated delivery surfaces (`teams.logo` for a
 * referenced club; the participant-own image follows with the W6 media
 * service). `name_effective` resolves the label server-side: explicit `name`
 * wins, otherwise the team name, otherwise null.
 */
class EventParticipantResource extends JsonResource
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
            'event_id' => $this->event_id,
            'team_id' => $this->team_id,
            'name' => $this->name,
            'name_effective' => $this->nameEffective(),
            'logo_url' => $this->logoUrl(),
            'sort_order' => $this->sort_order,
            'team' => $this->teamData(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The effective participant image: the referenced team's logo when a team
     * is linked. A participant-own `logo_path` (non-team image) is reserved
     * for the W6 media service and is not yet delivered.
     */
    private function logoUrl(): ?string
    {
        if ($this->relationLoaded('team') && $this->team?->logo_path !== null) {
            return route('api.admin.teams.logo', ['team' => $this->team->id]);
        }

        return null;
    }

    /**
     * @return array{id: int, name: string, logo_url: string|null}|null
     */
    private function teamData(): ?array
    {
        if ($this->team_id === null || ! $this->relationLoaded('team') || $this->team === null) {
            return null;
        }

        return [
            'id' => $this->team->id,
            'name' => $this->team->name,
            'logo_url' => $this->team->logo_path !== null
                ? route('api.admin.teams.logo', ['team' => $this->team->id])
                : null,
        ];
    }
}

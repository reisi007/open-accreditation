<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event type (W2) for the admin API. The storage path is never exposed — the
 * logo is delivered exclusively through the auth-gated
 * `api.admin.event-types.logo` route (`logo_url`, null when no file exists).
 * `presets` is returned as the decoded JSON envelope.
 */
class EventTypeResource extends JsonResource
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
            'logo_url' => $this->logo_path !== null
                ? route('api.admin.event-types.logo', ['eventType' => $this->id])
                : null,
            'presets' => $this->presets,
            'active' => $this->active,
            'created_at' => $this->created_at,
        ];
    }
}

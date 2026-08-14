<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of the current mandant (Verband) for the portal
 * overview (P3a). Never exposes storage paths — logo/header resolve to the
 * public portal delivery routes (`api.portal.mandant.logo|header`).
 */
class MandantPublicResource extends JsonResource
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
            'slug' => $this->slug,
            'name' => $this->name,
            'logo_url' => $this->logo_path !== null ? route('api.portal.mandant.logo') : null,
            'header_url' => $this->header_path !== null ? route('api.portal.mandant.header') : null,
            'impressum_text' => $this->impressum_text,
            'privacy_text' => $this->privacy_text,
            'teams_enabled' => (bool) $this->teams_enabled,
        ];
    }
}

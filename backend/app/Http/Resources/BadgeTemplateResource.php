<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A badge template (Ausweis-Vorlage) of the P4 admin surface.
 */
class BadgeTemplateResource extends JsonResource
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
            'name' => $this->name,
            'layout' => $this->layout,
            'is_default' => (bool) $this->is_default,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

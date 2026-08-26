<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mandant-owned badge image of the P4 admin surface. The shape is the
 * frontend contract (`frontend/src/api/client.ts` `BadgeImage`): the editor
 * lists uploads by name and addresses them via `id` — the private-disk `path`
 * is deliberately never serialized.
 */
class BadgeImageResource extends JsonResource
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
            'original_name' => $this->original_name,
            'mime' => $this->mime,
        ];
    }
}

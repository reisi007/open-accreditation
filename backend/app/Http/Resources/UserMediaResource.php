<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a user media file. Exposes only the authenticated
 * delivery URL, never the private storage path.
 */
class UserMediaResource extends JsonResource
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
            'type' => $this->type,
            'mime' => $this->mime,
            'size' => $this->size,
            'original_name' => $this->original_name,
            'created_at' => $this->created_at,
            'url' => $this->url(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mandant-scoped blacklist entry (P3e admin view).
 */
class BlacklistResource extends JsonResource
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
            'email' => $this->email,
            'domain' => $this->domain,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

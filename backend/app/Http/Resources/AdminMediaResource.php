<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A media file of an applicant as shown to the admin (P3e). `url` points at
 * the auth-gated admin delivery route — the owner-only delivery route
 * (`api.user.media.show`) stays untouched for the "Meine Medien" surface.
 */
class AdminMediaResource extends JsonResource
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
            'url' => route('api.admin.user-media.show', ['media' => $this->id]),
            'mime' => $this->mime,
        ];
    }
}

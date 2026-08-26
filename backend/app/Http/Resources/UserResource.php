<?php

namespace App\Http\Resources;

use App\Support\MandantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an authenticated user. Never serializes secrets
 * (password, activation_token) or storage paths.
 */
class UserResource extends JsonResource
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
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,

            'title' => $this->title,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date,
            'street' => $this->street,
            'zip' => $this->zip,
            'city' => $this->city,
            'country' => $this->country,
            'company' => $this->company,
            'phone' => $this->phone,
            'fax' => $this->fax,
            'branch' => $this->branch,
            'position' => $this->position,
            'vest_available' => (bool) $this->vest_available,
            'vest_number' => $this->vest_number,

            // The mandant resolved from the request host (MandantContextMiddleware).
            // Null when no mandant matches the host (e.g. global routes without a
            // tenant context). The frontend uses this for super_admin to show the
            // teams/resources of the CURRENT mandant instead of the primary one.
            'current_mandant_id' => MandantContext::currentId(),

            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(
                fn ($role) => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'mandant_id' => $role->pivot->mandant_id ?? null,
                    'team_id' => $role->pivot->team_id ?? null,
                ],
            )->values()),

            'media' => UserMediaResource::collection($this->whenLoaded('media')),
        ];
    }
}

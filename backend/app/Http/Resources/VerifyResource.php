<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public verification payload (P4). Privacy guard: only `approved`
 * applications expose identity data (name/category/event/date/photo_url);
 * `requested`/`denied`/`blacklisted` answer with the bare status so a revoked
 * badge still verifies without leaking the holder's data. `photo_url` is a
 * relative path — the frontend prefixes its own origin.
 */
class VerifyResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly string $token)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->status !== 'approved') {
            return ['status' => $this->status];
        }

        return [
            'status' => $this->status,
            'name' => $this->user?->name,
            'category' => $this->accreditation?->category?->name,
            'event' => $this->accreditation?->event?->title,
            'date' => $this->accreditation?->event?->date?->format('Y-m-d'),
            'photo_url' => '/api/verify/'.$this->token.'/photo',
        ];
    }
}

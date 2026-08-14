<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application (Antrag) of the admin approval view (P3e), including the
 * applicant identity and a compact view of the accreditation. The nested
 * accreditation's `available` is `quota - approved_count` (the remaining quota
 * slots) — controllers load it via a scoped `withCount`.
 */
class AdminApplicationResource extends JsonResource
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
            'user' => $this->user !== null
                ? ['id' => $this->user->id, 'email' => $this->user->email, 'name' => $this->user->name]
                : null,
            'accreditation' => $this->accreditationData(),
            'status' => $this->status,
            'priority' => $this->priority,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accreditationData(): ?array
    {
        if (! $this->relationLoaded('accreditation') || $this->accreditation === null) {
            return null;
        }

        $accreditation = $this->accreditation;

        return [
            'id' => $accreditation->id,
            'category' => $accreditation->category !== null
                ? ['id' => $accreditation->category->id, 'name' => $accreditation->category->name]
                : null,
            'scope' => $accreditation->scope,
            'event' => $accreditation->event_id !== null && $accreditation->event !== null
                ? [
                    'id' => $accreditation->event->id,
                    'title' => $accreditation->event->title,
                    'date' => $accreditation->event->date?->format('Y-m-d'),
                ]
                : null,
            'team' => $accreditation->team_id !== null && $accreditation->team !== null
                ? ['id' => $accreditation->team->id, 'name' => $accreditation->team->name]
                : null,
            'quota' => $accreditation->quota,
            'available' => (int) $accreditation->quota - (int) ($accreditation->approved_count ?? 0),
        ];
    }
}

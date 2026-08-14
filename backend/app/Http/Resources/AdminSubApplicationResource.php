<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sub-application (Park-/Sitzkarte, D9) of the admin approval view (P3e):
 * applicant identity plus the sub-accreditation and its main accreditation.
 * The sub's `available` is `quota - approved_count` (the remaining sub-quota
 * slots) — controllers load it via a scoped `withCount`.
 */
class AdminSubApplicationResource extends JsonResource
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
            'sub_accreditation' => $this->subAccreditationData(),
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
    private function subAccreditationData(): ?array
    {
        if (! $this->relationLoaded('subAccreditation') || $this->subAccreditation === null) {
            return null;
        }

        $sub = $this->subAccreditation;

        return [
            'id' => $sub->id,
            'type' => $sub->type,
            'quota' => $sub->quota,
            'available' => (int) $sub->quota - (int) ($sub->approved_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accreditationData(): ?array
    {
        $sub = $this->subAccreditation;

        if ($sub === null || ! $sub->relationLoaded('accreditation') || $sub->accreditation === null) {
            return null;
        }

        $accreditation = $sub->accreditation;

        return [
            'id' => $accreditation->id,
            'category' => $accreditation->category !== null
                ? ['id' => $accreditation->category->id, 'name' => $accreditation->category->name]
                : null,
            'event' => $accreditation->event_id !== null && $accreditation->event !== null
                ? [
                    'id' => $accreditation->event->id,
                    'title' => $accreditation->event->title,
                    'date' => $accreditation->event->date?->format('Y-m-d'),
                ]
                : null,
        ];
    }
}

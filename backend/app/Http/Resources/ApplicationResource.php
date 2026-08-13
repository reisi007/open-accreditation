<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application (Antrag) of the current user, including a compact view of the
 * accreditation it belongs to. The nested accreditation's `available` needs the
 * `applications` count — controllers load it via a scoped `withCount`.
 */
class ApplicationResource extends JsonResource
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
            'deadline_end' => $accreditation->deadline_end?->format('Y-m-d'),
            'quota' => $accreditation->quota,
            'available' => (int) $accreditation->quota - (int) ($accreditation->applications_count ?? 0),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sub-application (Antrag auf Park-/Sitzkarte) of the current user,
 * including the sub-accreditation and the main accreditation (category/event)
 * it is derived from. Controllers eager-load `subAccreditation`,
 * `subAccreditation.accreditation.category` and
 * `subAccreditation.accreditation.event`.
 */
class SubApplicationResource extends JsonResource
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
            'sub_accreditation' => $this->subAccreditationData(),
            'accreditation' => $this->accreditationData(),
            'status' => $this->status,
            'priority' => $this->priority,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return array{id: int, type: string, quota: int, deadline_end: string|null}|null
     */
    private function subAccreditationData(): ?array
    {
        if (! $this->relationLoaded('subAccreditation') || $this->subAccreditation === null) {
            return null;
        }

        return [
            'id' => $this->subAccreditation->id,
            'type' => $this->subAccreditation->type,
            'quota' => $this->subAccreditation->quota,
            'deadline_end' => $this->subAccreditation->deadline_end?->format('Y-m-d'),
        ];
    }

    /**
     * @return array{id: int, category: array{id: int, name: string}|null, event: array{id: int, title: string, date: string|null}|null}|null
     */
    private function accreditationData(): ?array
    {
        if (! $this->relationLoaded('subAccreditation') || $this->subAccreditation === null) {
            return null;
        }

        $accreditation = $this->subAccreditation->accreditation;

        if ($accreditation === null) {
            return null;
        }

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

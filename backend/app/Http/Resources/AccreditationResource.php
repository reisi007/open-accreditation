<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accreditation (Akkreditierung) shared by the public and the admin API.
 *
 * `applications_count` is the total count of all applications (requested +
 * approved + denied + blacklisted) — it must be loaded via `withCount`
 * (`applications`), otherwise it falls back to 0. `available` is
 * `quota - applications_count` and may be negative (overbooking is legal until
 * the P3c allocation engine decides).
 */
class AccreditationResource extends JsonResource
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
            'category_id' => $this->category_id,
            'category' => $this->categoryData(),
            'scope' => $this->scope,
            'event_id' => $this->event_id,
            'event' => $this->eventData(),
            'team_id' => $this->team_id,
            'team' => $this->teamData(),
            'quota' => $this->quota,
            'applications_count' => $this->applications_count ?? 0,
            'available' => (int) $this->quota - (int) ($this->applications_count ?? 0),
            'deadline_start' => $this->deadline_start?->format('Y-m-d'),
            'deadline_end' => $this->deadline_end?->format('Y-m-d'),
            'auto_approve' => $this->auto_approve,
            'active' => $this->active,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function categoryData(): ?array
    {
        if (! $this->relationLoaded('category') || $this->category === null) {
            return null;
        }

        return [
            'id' => $this->category->id,
            'name' => $this->category->name,
        ];
    }

    /**
     * @return array{id: int, title: string, date: string|null}|null
     */
    private function eventData(): ?array
    {
        if ($this->event_id === null || ! $this->relationLoaded('event') || $this->event === null) {
            return null;
        }

        return [
            'id' => $this->event->id,
            'title' => $this->event->title,
            'date' => $this->event->date?->format('Y-m-d'),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function teamData(): ?array
    {
        if ($this->team_id === null || ! $this->relationLoaded('team') || $this->team === null) {
            return null;
        }

        return [
            'id' => $this->team->id,
            'name' => $this->team->name,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sub-accreditation (Park-/Sitzkarte) shared by the public and the admin API.
 *
 * `applications_count` is the total count of all sub-applications (requested
 * + approved + denied) — it must be loaded via `withCount('subApplications')`
 * (attribute `sub_applications_count`), otherwise it falls back to 0.
 * `available` is `quota - applications_count` and may be negative (overbooking
 * is legal until the P3d allocation engine decides).
 */
class SubAccreditationResource extends JsonResource
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
            'accreditation_id' => $this->accreditation_id,
            'type' => $this->type,
            'quota' => $this->quota,
            'applications_count' => $this->sub_applications_count ?? 0,
            'available' => (int) $this->quota - (int) ($this->sub_applications_count ?? 0),
            'deadline_start' => $this->deadline_start?->format('Y-m-d'),
            'deadline_end' => $this->deadline_end?->format('Y-m-d'),
            'auto_approve' => $this->auto_approve,
            'active' => $this->active,
        ];
    }
}

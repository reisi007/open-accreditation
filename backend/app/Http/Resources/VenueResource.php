<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Venue (Spielstätte) for the admin API.
 *
 * `teams_count` / `events_count` are the reference counts the DELETE guard
 * works from: they let the admin see what deleting this venue WOULD destroy
 * before offering the action, and they are the numbers named in the 409
 * message when a referenced venue is refused.
 *
 * The counts are read through `withCount()`. When the relation is loaded
 * (index), the preloaded size is used; otherwise a `count()` is issued lazily
 * so a single-resource response never carries a `null` count.
 */
class VenueResource extends JsonResource
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
            'is_active' => $this->is_active,
            'teams_count' => $this->referenceCount('teams'),
            'events_count' => $this->referenceCount('events'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The number of referencing rows, preferring the `withCount()` aggregate
     * and falling back to the loaded relation size.
     */
    private function referenceCount(string $relation): int
    {
        $aggregate = $relation.'_count';

        if (array_key_exists($aggregate, $this->resource->getAttributes())) {
            return (int) $this->resource->getAttribute($aggregate);
        }

        if ($this->relationLoaded($relation)) {
            return $this->resource->getRelation($relation)->count();
        }

        return $this->resource->{$relation}()->count();
    }
}

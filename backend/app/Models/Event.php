<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An event (Event/Spiel) of a mandant, optionally assigned to a team.
 */
#[Fillable(['mandant_id', 'team_id', 'title', 'date', 'venue', 'competition', 'deadline_start', 'deadline_end', 'active'])]
class Event extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'deadline_start' => 'date',
            'deadline_end' => 'date',
            'active' => 'boolean',
        ];
    }

    /**
     * Scope to the events of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }

    /**
     * Scope to the team-level events of one team.
     */
    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where($query->getQuery()->from.'.team_id', $teamId);
    }

    /**
     * Scope to active (or inactive) events.
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->getQuery()->from.'.active', $active);
    }
}

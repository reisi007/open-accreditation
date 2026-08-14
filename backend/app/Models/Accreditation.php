<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An accreditation (Akkreditierung): a quota + deadline window for one
 * category within a scope (`event` | `league` | `season`). `team_id = null` →
 * mandant-level, set → team-level. Quota is the target count only — the
 * applications table is allowed to exceed it (overbooking), the P3c allocation
 * engine decides who gets the quota slots.
 */
#[Fillable([
    'mandant_id',
    'team_id',
    'category_id',
    'event_id',
    'scope',
    'quota',
    'deadline_start',
    'deadline_end',
    'auto_approve',
    'active',
])]
class Accreditation extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * The sub-accreditations (Park-/Sitzkarten, P3d/D9) attached to this
     * accreditation.
     */
    public function subAccreditations(): HasMany
    {
        return $this->hasMany(SubAccreditation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota' => 'integer',
            'deadline_start' => 'date',
            'deadline_end' => 'date',
            'auto_approve' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Scope to the accreditations of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }

    /**
     * Scope to active (or inactive) accreditations.
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->getQuery()->from.'.active', $active);
    }

    /**
     * Scope to the team-level accreditations of one team.
     */
    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where($query->getQuery()->from.'.team_id', $teamId);
    }
}

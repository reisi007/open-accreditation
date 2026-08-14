<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sub-accreditation (Park-/Sitzkarte, D9): a `park` | `seat` quota +
 * deadline window attached to one main accreditation. `quota` is the target
 * count only — sub_applications may exceed it (overbooking), the P3d
 * allocation engine decides who receives a slot.
 */
#[Fillable([
    'accreditation_id',
    'type',
    'quota',
    'deadline_start',
    'deadline_end',
    'auto_approve',
    'active',
])]
class SubAccreditation extends Model
{
    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(Accreditation::class);
    }

    public function subApplications(): HasMany
    {
        return $this->hasMany(SubApplication::class);
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
     * Scope to the sub-accreditations whose main accreditation belongs to one
     * mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->whereHas(
            'accreditation',
            fn (Builder $q) => $q->forMandant($mandantId),
        );
    }

    /**
     * Scope to the sub-accreditations of one team.
     */
    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->whereHas(
            'accreditation',
            fn (Builder $q) => $q->forTeam($teamId),
        );
    }

    /**
     * Scope to active (or inactive) sub-accreditations.
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->getQuery()->from.'.active', $active);
    }
}

<?php

namespace App\Models;

use App\Support\MandantContext;
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
     * Route-model-binding safety net: sub-accreditations carry no `mandant_id`
     * of their own, so a bound instance is only resolved when its parent
     * accreditation belongs to the current mandant (host-derived). Without a
     * resolved mandant (seeders, console commands, tests) the binding stays
     * unscoped. Mirrors MandantDomain's `forCurrentMandant()` intent at the
     * route boundary.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (MandantContext::hasCurrent()) {
            $query->whereHas('accreditation', function (Builder $q) {
                $q->where($q->getQuery()->from.'.mandant_id', MandantContext::currentId());
            });
        }

        return $query;
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

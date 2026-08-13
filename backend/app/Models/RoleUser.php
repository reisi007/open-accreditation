<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Scoped pivot between users and roles. `mandant_id` is null for the global
 * `super_admin` role; `team_id` is null until P2 introduces teams. The table
 * name `role_user` is derived automatically from the class name (AsPivot).
 */
class RoleUser extends Pivot
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = true;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Scope rows to one mandant (or to the global super_admin rows when null).
     */
    public function scopeForMandant(Builder $query, ?int $mandantId): Builder
    {
        return $query->where($query->qualifyColumn('mandant_id'), $mandantId);
    }

    /**
     * Scope rows to one team (null matches team-agnostic roles).
     */
    public function scopeForTeam(Builder $query, ?int $teamId): Builder
    {
        return $query->where($query->qualifyColumn('team_id'), $teamId);
    }
}

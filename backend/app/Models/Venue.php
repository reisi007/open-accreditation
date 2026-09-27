<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A venue (Spielstätte/Austragungsort) as mandant-level master data (W12).
 *
 * ONE table, referenced by both `teams.venue_id` (a Verein's default location)
 * and `events.venue_id` (an event that deviates from it). The former free-text
 * columns `teams.home_venue` / `events.venue` are dropped — the master data
 * replaces them, it does not supplement them.
 *
 * `is_active` is the lifecycle flag, not a soft-delete: a venue that is still
 * referenced by a team or an event is DEACTIVATED (`is_active = false`), never
 * deleted, so historical team/event rows keep resolving to the same name. Only
 * an unreferenced venue can be deleted (the escape hatch for a mistyped empty
 * entry); the DB `restrict` on both referencing FKs enforces the same rule
 * below the API.
 *
 * Name uniqueness is scoped per mandant (`unique(mandant_id, name)`) and
 * deliberately NOT filtered on `is_active`: a deactivated name stays taken and
 * is reactivated rather than duplicated. Two Verbände may of course use the
 * same venue name.
 */
#[Fillable(['mandant_id', 'name', 'is_active'])]
class Venue extends Model
{
    use HasFactory;

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    /**
     * The teams (Vereine) using this venue as their default location.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * The events assigned to this venue, overriding their team's default.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Route-model-binding safety net: a bound instance is only resolved when it
     * belongs to the current mandant (host-derived). Without a resolved mandant
     * (seeders, console commands, tests) the binding stays unscoped. Mirrors
     * Category's `forCurrentMandant()` intent at the route boundary, so a
     * single missed `forMandant()` in a controller can no longer leak another
     * tenant's row through an unscoped binding.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (MandantContext::hasCurrent()) {
            $query->where($query->getQuery()->from.'.mandant_id', MandantContext::currentId());
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope to the venues of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }

    /**
     * Scope to active (or inactive) venues.
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->getQuery()->from.'.is_active', $active);
    }
}

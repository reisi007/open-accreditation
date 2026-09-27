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
 * A team (Verein) of a mandant. Its location is the nullable `venue_id` FK to
 * the mandant-wide venue master data (W12) — the former free-text `home_venue`
 * column is gone, so one venue row is shared by every team and event using it.
 */
#[Fillable(['mandant_id', 'slug', 'name', 'venue_id', 'logo_path'])]
class Team extends Model
{
    use HasFactory;

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    /**
     * The team's default venue (Heimstätte, W12). Nullable — a team without a
     * venue simply has none. The relation is mandant-internal: the venue
     * belongs to the same mandant as the team.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Route-model-binding safety net: a bound instance is only resolved when it
     * belongs to the current mandant (host-derived). Without a resolved mandant
     * (seeders, console commands, tests) the binding stays unscoped. Mirrors
     * MandantDomain's `forCurrentMandant()` intent at the route boundary, so a
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
     * The team's own (team-level) categories, overriding mandant-level slugs
     * (P2b).
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * The team's own events (P2b).
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Scope to the teams of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }
}

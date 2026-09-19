<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An event (Event/Spiel) of a mandant, optionally assigned to a team.
 *
 * The participant list (W4) is cardinality-free: an event may have one
 * participant (Single), two (Versus, the default pairing) or N (tournament
 * groups sharing a slot). `team_id` stays the "home team" shorthand; the full
 * field lives in `event_participants`.
 *
 * The home default venue chain is intentionally NOT rewired by W4:
 * `venue_effective = events.venue ?? teams.home_venue` (see
 * `PortalEventDetailResource`) keeps resolving through the bound `team`. A
 * dedicated Venue-CRUD (`events.venue_id`) follows later; participants only
 * carry the opposing/tournament sides.
 */
#[Fillable(['mandant_id', 'team_id', 'event_type_id', 'title', 'date', 'venue', 'competition', 'deadline_start', 'deadline_end', 'active'])]
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
     * The optional event type (W2). `competition` stays the free-text fallback
     * for events without a type.
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * The event's participant slots (W4), ordered by `sort_order`. Each row
     * points at an optional team and/or carries a free-text `name` override,
     * so Single/Versus/tournament events use the same model.
     */
    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class)->orderBy('sort_order');
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

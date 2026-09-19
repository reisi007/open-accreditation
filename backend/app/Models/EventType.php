<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mandant-specific event type (Veranstaltungs-/Wettbewerbstyp, e.g.
 * `bundesliga`, `cup`). Slug uniqueness is scoped to the mandant — two
 * Verbände may use the same slug. `presets` is a portable JSON envelope (only
 * structurally validated in W2; the fachliches schema follows in W3) and
 * `logo_path` points at a file below the public media root
 * (`MediaPathService::eventTypeFile()`, served by Caddy in W6/W7).
 */
#[Fillable(['mandant_id', 'slug', 'name', 'logo_path', 'presets', 'active'])]
class EventType extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    /**
     * The events assigned to this type. `events.competition` stays the
     * free-text fallback for events without a type.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
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
            'presets' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Scope to the event types of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }

    /**
     * Scope to active (or inactive) event types.
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where($query->getQuery()->from.'.active', $active);
    }
}

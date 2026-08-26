<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A badge template (Ausweis-Vorlage) of a mandant. `layout` is a JSON array of
 * positioned entries (schema v2, features/badge-template-editor.md):
 * `field ∈ {name, category, event, date, photo, status, team, vest_number,
 * qr, image}`; coordinates `x/y/w/h` are millimetres on the A6 card.
 * Data fields carry `size` (pt) + `align`; `qr` is geometry-only (max one per
 * template); `image` requires the source union `src`
 * (`{kind: brand, ref}` | `{kind: upload, image_id}`) plus optional `fit`.
 */
#[Fillable(['mandant_id', 'name', 'layout', 'is_default'])]
class BadgeTemplate extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
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
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Scope to the badge templates of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }

    /**
     * Scope to the default template(s) of a mandant.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.is_default', true);
    }
}

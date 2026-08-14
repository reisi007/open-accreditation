<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A badge template (Ausweis-Vorlage) of a mandant. `layout` is a JSON array of
 * positioned fields `[{field, x, y, w, h, size, align}]` with
 * `field ∈ {name, category, event, date, photo, status}`; the badge renderer
 * positions each field in millimetres on the A6 card (P4).
 */
#[Fillable(['mandant_id', 'name', 'layout', 'is_default'])]
class BadgeTemplate extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
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

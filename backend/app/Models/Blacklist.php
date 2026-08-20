<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mandant-scoped blacklist entry (P3c schema). `email` and/or `domain` may be
 * set; the enforcement logic lives in `AllocationRules` (P3c/P3d engines and
 * the P3e single-approve guard). The `(mandant_id, email)` /
 * `(mandant_id, domain)` unique constraints forbid duplicate entries.
 */
#[Fillable(['mandant_id', 'email', 'domain', 'note'])]
class Blacklist extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    /**
     * Scope to the blacklist entries of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
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
}

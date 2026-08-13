<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mandant_id', 'hostname'])]
class MandantDomain extends Model
{
    use HasFactory;

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    /**
     * Scope to the current mandant (host-derived), mirroring the portal's
     * `forCurrentBrand()` pattern. Falls back to an empty result set when no
     * mandant is set, so no cross-mandant data can leak.
     */
    public function scopeForCurrentMandant(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', MandantContext::currentId());
    }
}

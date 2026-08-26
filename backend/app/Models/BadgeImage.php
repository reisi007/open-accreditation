<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mandant-owned badge image (features/badge-template-editor.md, "Elementtyp
 * `image`"): an uploaded picture addressed by `image_id` from a badge
 * template's `image` layout entry (`{kind: upload, image_id}`). The file sits
 * on the private disk; delivery is auth-gated through the admin API only —
 * the stored `path` is never exposed as a public URL.
 */
#[Fillable(['mandant_id', 'path', 'mime', 'original_name'])]
class BadgeImage extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    /**
     * Route-model-binding safety net: a bound instance is only resolved when it
     * belongs to the current mandant (host-derived). Without a resolved mandant
     * (seeders, console commands, tests) the binding stays unscoped. Mirrors
     * BadgeTemplate/Team so a single missed `forMandant()` in a controller can
     * no longer leak another tenant's row (or file) through an unscoped binding.
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
     * Scope to the badge images of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }
}

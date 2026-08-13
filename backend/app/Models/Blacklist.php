<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mandant-scoped blacklist entry (P3c schema). `email` and/or `domain` may be
 * set; the enforcement logic lands in P3c (this model only carries the data).
 */
#[Fillable(['mandant_id', 'email', 'domain', 'note'])]
class Blacklist extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }
}

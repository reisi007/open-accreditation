<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An application (Antrag) of one user for one accreditation. The unique
 * `(accreditation_id, user_id)` constraint forbids duplicate applications at
 * the database level; the apply endpoint mirrors it with a 422. Status:
 * `requested|approved|denied|blacklisted` (P3c allocation engine advances the
 * status).
 */
#[Fillable([
    'accreditation_id',
    'user_id',
    'status',
    'priority',
    'reason',
])]
class Application extends Model
{
    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(Accreditation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'boolean',
        ];
    }

    /**
     * Scope to the applications of one user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where($query->getQuery()->from.'.user_id', $userId);
    }

    /**
     * Scope to the applications whose accreditation belongs to one mandant
     * (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->whereHas(
            'accreditation',
            fn (Builder $q) => $q->forMandant($mandantId),
        );
    }
}

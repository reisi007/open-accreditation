<?php

namespace App\Models;

use App\Support\MandantContext;
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
    'qr_token',
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
     * Route-model-binding safety net: applications carry no `mandant_id` of
     * their own, so a bound instance is only resolved when its parent
     * accreditation belongs to the current mandant (host-derived). Without a
     * resolved mandant (seeders, console commands, tests) the binding stays
     * unscoped. Mirrors MandantDomain's `forCurrentMandant()` intent at the
     * route boundary.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (MandantContext::hasCurrent()) {
            $query->whereHas('accreditation', function (Builder $q) {
                $q->where($q->getQuery()->from.'.mandant_id', MandantContext::currentId());
            });
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

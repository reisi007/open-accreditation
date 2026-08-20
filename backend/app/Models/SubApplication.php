<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An application (Antrag) of one user for one sub-accreditation (D9). The
 * unique `(sub_accreditation_id, application_id)` constraint forbids
 * duplicate sub-applications at the database level; the apply endpoint
 * mirrors it with a 422. Status: `requested|approved|denied` (P3d allocation
 * engine advances the status). `user_id` denormalizes `application.user_id`
 * (always equal — the apply endpoint sets both from the approved main
 * application) so the blacklist check needs no join.
 */
#[Fillable([
    'sub_accreditation_id',
    'application_id',
    'user_id',
    'status',
    'priority',
    'reason',
])]
class SubApplication extends Model
{
    public function subAccreditation(): BelongsTo
    {
        return $this->belongsTo(SubAccreditation::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Route-model-binding safety net: sub-applications carry no `mandant_id` of
     * their own, so a bound instance is only resolved when its parent
     * sub-accreditation's accreditation belongs to the current mandant
     * (host-derived). Without a resolved mandant (seeders, console commands,
     * tests) the binding stays unscoped. Mirrors MandantDomain's
     * `forCurrentMandant()` intent at the route boundary.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (MandantContext::hasCurrent()) {
            $query->whereHas('subAccreditation.accreditation', function (Builder $q) {
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
     * Scope to the sub-applications of one user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where($query->getQuery()->from.'.user_id', $userId);
    }

    /**
     * Scope to the sub-applications whose sub-accreditation's main
     * accreditation belongs to one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->whereHas(
            'subAccreditation.accreditation',
            fn (Builder $q) => $q->forMandant($mandantId),
        );
    }
}

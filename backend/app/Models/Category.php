<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A category (Kategorie) of a mandant. `team_id = null` → mandant-level,
 * set → team-level (a team may override a mandant-level slug, see
 * `effectiveForTeam()`). Slug uniqueness is enforced level-scoped at the
 * database layer (see the migration) and mirrored by the controller rules.
 */
#[Fillable(['mandant_id', 'team_id', 'name', 'slug', 'description'])]
class Category extends Model
{
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Scope to the categories of one mandant (Verband).
     */
    public function scopeForMandant(Builder $query, int $mandantId): Builder
    {
        return $query->where($query->getQuery()->from.'.mandant_id', $mandantId);
    }

    /**
     * Scope to the team-level categories of one team.
     */
    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where($query->getQuery()->from.'.team_id', $teamId);
    }

    /**
     * The effective category list for a team: the team's own team-level
     * categories plus the mandant-level categories it does not override. For
     * an equal slug the team-level category wins (override precedence).
     *
     * @return Collection<int, Category>
     */
    public static function effectiveForTeam(int $teamId): Collection
    {
        $team = Team::query()->findOrFail($teamId);

        $teamLevel = self::query()
            ->forMandant((int) $team->mandant_id)
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $mandantLevel = self::query()
            ->forMandant((int) $team->mandant_id)
            ->whereNull('team_id')
            ->whereNotIn('slug', $teamLevel->pluck('slug'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $teamLevel->merge($mandantLevel);
    }
}

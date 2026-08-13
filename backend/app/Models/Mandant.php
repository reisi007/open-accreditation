<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'name',
    'logo_path',
    'header_path',
    'impressum_text',
    'privacy_text',
    'smtp_config',
    'teams_enabled',
    'is_primary',
    'is_active',
])]
class Mandant extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smtp_config' => 'array',
            'teams_enabled' => 'boolean',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * All hostnames routed to this mandant.
     */
    public function domains(): HasMany
    {
        return $this->hasMany(MandantDomain::class);
    }

    /**
     * The mandant's teams (Vereine), optional per mandant.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * The mandant's categories (Kategorien), mandant- and team-level (P2b).
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * The mandant's events (Events/Spiele), mandant- and team-level (P2b).
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Only mandants that may serve traffic.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('mandants.is_active', true);
    }

    /**
     * Whether this mandant is the primary fallback mandant.
     */
    public function isPrimary(): bool
    {
        return (bool) $this->is_primary;
    }
}

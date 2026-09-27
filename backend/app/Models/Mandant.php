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
     * `smtp_config` is `encrypted:json` (WP-6-d / R-D9): the column carries a
     * third party's mail credentials, so it is stored as an opaque ciphertext
     * like the bcrypt hashes next to it. `MandantResource` only masked the
     * password at the API boundary — any DB read path (backup, dump, replica)
     * used to yield usable credentials.
     *
     * Consequences an operator has to know:
     *  - The column type is `text` (see
     *    `2026_09_26_000502_widen_smtp_config_for_encryption`), because
     *    Laravel's ciphertext is base64 and a `json` column rejects it on
     *    Postgres while SQLite (whose `json` is plain `text`) would not have
     *    complained.
     *  - Rows written before this cast are plain JSON and can no longer be
     *    read: they raise `Illuminate\Contracts\Encryption\DecryptException`.
     *    There is no production data yet (Go-Live is parked), so a re-save
     *    per mandant (`PUT /api/admin/mandants/{id}` with the same
     *    `smtp_config`, or `smtp_config: null` to drop it) is the whole
     *    remediation. See `features/02-domain-model.md`.
     *  - Rotating `APP_KEY` invalidates the stored configs unless the old key
     *    is listed in `APP_PREVIOUS_KEYS` (Laravel's encrypter then decrypts
     *    with the fallback keys) — otherwise a re-save is required again.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smtp_config' => 'encrypted:json',
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
     * The mandant's venues (Spielstätten), mandant-wide master data (W12).
     * Referenced by both teams and events.
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /**
     * The mandant's event types (W2), mandant-wide.
     */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /**
     * The mandant's accreditations (Akkreditierungen), mandant- and team-level
     * (P3b).
     */
    public function accreditations(): HasMany
    {
        return $this->hasMany(Accreditation::class);
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

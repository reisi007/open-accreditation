<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable([
    'name',
    'email',
    'email_verified_at',
    'password',
    'title',
    'gender',
    'birth_date',
    'street',
    'zip',
    'city',
    'country',
    'company',
    'phone',
    'fax',
    'branch',
    'position',
    'vest_available',
    'vest_number',
    'activation_token',
    'activation_token_expires_at',
])]
#[Hidden(['password', 'remember_token', 'activation_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The identifier stored in the JWT `sub` claim.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Custom JWT claims (none for now).
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date:Y-m-d',
            'vest_available' => 'boolean',
            'activation_token_expires_at' => 'datetime',
        ];
    }

    /**
     * All roles assigned to this user, including the pivot scope.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUser::class)
            ->withPivot(['mandant_id', 'team_id'])
            ->withTimestamps();
    }

    /**
     * Raw pivot rows — useful for scoped role queries.
     */
    public function roleUserAssignments(): HasMany
    {
        return $this->hasMany(RoleUser::class);
    }

    /**
     * Uploaded private media (portrait, press id, attachments).
     */
    public function media(): HasMany
    {
        return $this->hasMany(UserMedia::class);
    }

    /**
     * Whether the user holds a specific role for the given scope. Null scope
     * values match the global `super_admin` assignment (mandant_id = team_id =
     * null).
     */
    public function hasRole(string $slug, ?int $mandantId = null, ?int $teamId = null): bool
    {
        return $this->roleUserAssignments()
            ->forMandant($mandantId)
            ->forTeam($teamId)
            ->whereHas('role', fn (Builder $query) => $query->where('roles.slug', $slug))
            ->exists();
    }

    /**
     * Global super admin (not scoped to any mandant).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SUPER_ADMIN->value);
    }

    /**
     * Administrator of a specific mandant (Verband).
     */
    public function isMandantAdmin(int $mandantId): bool
    {
        return $this->hasRole(UserRole::MANDANT_ADMIN->value, $mandantId);
    }

    /**
     * Team administrator. Team scope lands in P2; until then the pivot row
     * simply matches the team id (the team's mandant is implicit).
     */
    public function isTeamAdmin(int $teamId): bool
    {
        return $this->roleUserAssignments()
            ->forTeam($teamId)
            ->whereHas('role', fn (Builder $query) => $query->where('roles.slug', UserRole::TEAM_ADMIN->value))
            ->exists();
    }

    /**
     * Door/check-in verifier of a specific mandant.
     */
    public function isVerifier(int $mandantId): bool
    {
        return $this->hasRole(UserRole::VERIFIER->value, $mandantId);
    }

    /**
     * The (first assigned) role slug within a mandant, or null when the user
     * has no role there. `super_admin` is global and therefore never returned
     * for a mandant scope.
     */
    public function roleForMandant(int $mandantId): ?string
    {
        return $this->roleUserAssignments()
            ->forMandant($mandantId)
            ->whereHas('role')
            ->with('role')
            ->orderBy('role_user.id')
            ->get()
            ->pluck('role.slug')
            ->first();
    }

    /**
     * Whether the user uploaded any media (optionally of one type).
     */
    public function hasMedia(?string $type = null): bool
    {
        $query = $this->media()->getQuery();

        if ($type !== null) {
            $query->where('user_media.type', $type);
        }

        return $query->exists();
    }
}

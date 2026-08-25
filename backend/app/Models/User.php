<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\MandantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable([
    'name',
    'mandant_id',
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
     * The owning mandant ("home" mandant) of this account — the anchor for
     * the per-mandant email uniqueness (BE-R1) and the host-scoped login
     * lookup. Authorization itself still flows exclusively through the
     * mandant-scoped `role_user` assignments (union semantics), not through
     * this column.
     */
    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
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
     * for a mandant scope. Kept for compatibility — the union-aware
     * authorization layer uses `roleAssignmentsForMandant()` instead.
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
     * All role assignments within one mandant, eager-loaded with `role` and
     * `team`. Union semantics (P1d-F2): a user may hold several roles per
     * mandant, and every assignment counts. `null` defaults to the current
     * mandant from `MandantContext`.
     *
     * @return Collection<int, RoleUser>
     */
    public function roleAssignmentsForMandant(?int $mandantId = null): Collection
    {
        $mandantId ??= MandantContext::currentId();

        if ($mandantId === null) {
            return collect();
        }

        return $this->roleUserAssignments()
            ->forMandant($mandantId)
            ->with(['role', 'team'])
            ->orderBy('role_user.id')
            ->get();
    }

    /**
     * The first role assignment (role + pivot scope) within a mandant, or null.
     * Mirrors `roleForMandant()` but also exposes the pivot's `team_id`.
     * Kept for compatibility — the union-aware authorization layer uses
     * `roleAssignmentsForMandant()` instead.
     */
    public function roleAssignmentForMandant(int $mandantId): ?RoleUser
    {
        return $this->roleUserAssignments()
            ->forMandant($mandantId)
            ->with('role')
            ->orderBy('role_user.id')
            ->first();
    }

    /**
     * Whether the user holds a permission within a mandant (defaults to the
     * current mandant from `MandantContext`). super_admin bypasses the matrix
     * entirely (global, also without a mandant).
     *
     * Union semantics (P1d-F2): ALL role assignments of the user within the
     * mandant are evaluated — the permission is granted as soon as ANY
     * assignment holds it. For team_admin the permission is additionally
     * scoped to the team(s) of his role assignment(s): an explicit `$teamId`
     * must match one of them, without an argument the own team(s) are used;
     * a team_admin without any team assignment → deny.
     */
    public function hasPermission(string $permission, ?int $mandantId = null, ?int $teamId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $mandantId ??= MandantContext::currentId();

        if ($mandantId === null) {
            return false;
        }

        $assignments = $this->roleAssignmentsForMandant($mandantId);

        foreach ($assignments as $assignment) {
            $roleSlug = $assignment->role->slug;

            if (! in_array($permission, (array) config("permissions.{$roleSlug}"), true)) {
                continue;
            }

            if ($roleSlug !== UserRole::TEAM_ADMIN->value) {
                return true;
            }

            $roleTeamId = $assignment->team_id === null ? null : (int) $assignment->team_id;

            if ($roleTeamId === null) {
                continue;
            }

            if ($teamId === null || (int) $teamId === $roleTeamId) {
                return true;
            }
        }

        return false;
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

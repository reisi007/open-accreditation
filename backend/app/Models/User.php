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
use Illuminate\Database\Eloquent\Relations\Relation;
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
     * Main accreditation applications of this account.
     *
     * Exists as a relation so the deletion summary and the confirmation dialog
     * count them through the ordinary query builder (`withCount`, no raw SQL,
     * portable on both engines per §2). `applications.user_id` carries
     * `cascadeOnDelete()`, so they go with the account — and with them every
     * printed badge's `qr_token`, which is the intended DSGVO consequence: the
     * token would otherwise stay a way to re-identify the deleted person.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Park-/Sitzkarte applications. `sub_applications.user_id` cascades as well
     * (independently of `sub_applications.application_id`), so this relation is
     * the authoritative count for the confirmation dialog.
     */
    public function subApplications(): HasMany
    {
        return $this->hasMany(SubApplication::class);
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
     * Whether the user may act inside the given mandant: either they hold at
     * least one role assignment scoped to it, or they are the GLOBAL
     * `super_admin` (whose `role_user` rows carry `mandant_id = null` and are
     * therefore valid everywhere).
     *
     * This is the single expression of the tenant-membership invariant. The
     * JWT carries no mandant claim (`getJWTCustomClaims()` is empty), so a
     * token minted on mandant A is technically valid on mandant B's domain;
     * this predicate is what keeps such an account inside its tenant. It backs
     * the per-request check in `EnsureMandantMembership` and is deliberately
     * the same rule `AuthController::mayLogInOnCurrentMandant()` evaluates at
     * login time — the login check is the early filter, this one the
     * continuous one. (Change both together if the rule ever moves.)
     *
     * The same predicate is the scope of `resolveRouteBindingQuery()` — the
     * identity check (may this account act here at all?) and the resource check
     * (is THIS target one of my users?) are deliberately the ONE rule, factored
     * into `constrainToMandantMembership()` so they cannot drift apart.
     *
     * ONE query for both branches: a single `EXISTS` whose predicate is the
     * disjunction "role in this mandant OR global super_admin". The
     * super-admin case is a row comparison inside the DB, not a second
     * round-trip, and the index scan is over the user's handful of role rows.
     * Both branches are plain comparisons + one `EXISTS` subquery — identical
     * SQL on Postgres and SQLite (§2 portability).
     *
     * `null` defaults to the current mandant; without a mandant there is
     * nothing to be a member of, so the answer is false.
     */
    public function isMemberOfMandant(?int $mandantId = null): bool
    {
        $mandantId ??= MandantContext::currentId();

        if ($mandantId === null) {
            return false;
        }

        // The first branch is the existing `forMandant()` scope — the same
        // rows the rest of the model reads — grouped with the global
        // `super_admin` rows so one `EXISTS` answers both. The predicate itself
        // lives in `constrainToMandantMembership()`, which the route binding
        // uses too (see there).
        return $this->constrainToMandantMembership($this->roleUserAssignments(), $mandantId)->exists();
    }

    /**
     * The membership predicate as a query constraint, on a `role_user` query.
     *
     * ONE definition, two callers: the per-request gate
     * (`isMemberOfMandant()`, the identity check) and the route binding
     * (`resolveRouteBindingQuery()`, the resource check). They must not be able
     * to drift — a row that counts as "belongs to this mandant" for one and not
     * for the other would either 404 a legitimate target or let a foreign id
     * resolve, which is exactly the class of bug this file's binding exists to
     * close.
     *
     * @param  Builder|Relation  $assignments  a `role_user` query of the user in question
     */
    private function constrainToMandantMembership(Builder|Relation $assignments, int $mandantId): Builder|Relation
    {
        return $assignments->where(function (Builder $query) use ($mandantId): void {
            $query->forMandant($mandantId)
                ->orWhere(function (Builder $global): void {
                    // `mandant_id IS NULL AND team_id IS NULL` — exactly the
                    // predicate `isSuperAdmin()` evaluates (`forMandant(null)`
                    // + `forTeam(null)`). Without the `team_id` half the two
                    // disagreed about the same row: a `super_admin` pivot
                    // that (wrongly) carried a team id counted as a global
                    // super admin HERE, while `isSuperAdmin()` — the method
                    // every permission check consults — said no.
                    $global->whereNull('role_user.mandant_id')
                        ->whereNull('role_user.team_id')
                        ->whereHas('role', fn (Builder $role): Builder => $role->where('roles.slug', UserRole::SUPER_ADMIN->value));
                });
        });
    }

    /**
     * Route-model-binding safety net: an account is only resolved when it is a
     * member of the current mandant (host-derived), i.e. exactly when
     * `isMemberOfMandant()` would say yes. `SubstituteBindings` runs BEFORE
     * `EnsureMandantMembership`, so an unscoped binding answers 404 for an
     * unknown id and lets a FOREIGN row through to the membership check,
     * which answers 403 — the two are distinguishable, and a replayed cookie
     * could mine sequential user ids of other tenants that way (M2). Every other
     * bound model is mandant-scoped in its own `resolveRouteBindingQuery()`.
     *
     * `users` has no `mandant_id`-based ownership for this purpose: the
     * "owning" (`home`) mandant column only anchors the per-mandant email
     * uniqueness and the host-scoped login lookup, while AUTHORIZATION and
     * membership flow exclusively through the mandant-scoped `role_user`
     * assignments (union semantics — one account legitimately holds roles in
     * SEVERAL mandants). A `where('users.mandant_id', $currentId)` would
     * therefore break the roles endpoint for exactly the admins who use it: a
     * user who is a member of the current mandant B but whose home mandant is
     * A would stop resolving on B. So the scope is the membership predicate
     * itself — a correlated `EXISTS` over the user's own `role_user` rows
     * ("any role in this mandant OR the global `super_admin`"), which is the
     * same expression the per-request gate evaluates, so a target that resolves
     * is a target whose roles the controller may legitimately replace.
     *
     * Plain comparisons + nested `EXISTS` — identical SQL on Postgres and
     * SQLite (§2 portability). Without a resolved mandant (seeders, console
     * commands, tests) the binding stays unscoped, mirroring
     * `Accreditation::resolveRouteBindingQuery()`.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        $mandantId = MandantContext::currentId();

        if ($mandantId === null) {
            return $query;
        }

        $query->whereHas(
            'roleUserAssignments',
            fn (Builder $assignments): Builder => $this->constrainToMandantMembership($assignments, $mandantId),
        );

        return $query;
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

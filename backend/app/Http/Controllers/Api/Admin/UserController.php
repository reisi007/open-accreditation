<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidUtf8;
use App\Services\AccountDeletionService;
use App\Support\LikeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin user management (P2c): list the users of the current mandant (scoped
 * to users holding at least one `role_user` assignment there), replace their
 * mandant role set, and hard-delete an account.
 *
 * Guarded by `can:users.manage` (super_admin + mandant_admin + team_admin).
 * Role replacement is union-friendly (P1d-F2): several roles per (user,
 * mandant) are allowed and each assignment is written separately. The global
 * `super_admin` assignment (mandant_id = null) is never touched and
 * `super_admin` is rejected in the payload.
 *
 * `destroy()` sits behind its OWN gate, `users.delete`, deliberately separate
 * from `users.manage`: that one is ROLE ASSIGNMENT, and hanging account
 * termination off it would give whoever may hand out roles the power to end an
 * account.
 *
 * ## `users.manage` is mandant-wide for a mandant_admin and TEAM-SCOPED for a
 * ## team_admin (F4, Nutzerentscheid 2026-10-06)
 *
 * `users.manage` was handed to `team_admin` so the two permissions stop having
 * identical holders — measured: with identical holders, swapping the delete
 * route's gate back to `can:users.manage` left 30/30 and 64/64 green, because
 * every existing behavioural test was satisfied by either route reading either
 * permission. `team_admin` is the smallest holder that separates them: he
 * appoints and dismisses the admins of his own club, and cannot end an account.
 * `users.delete` stays with `mandant_admin` alone (plus `super_admin`'s `'*'`),
 * so a team_admin is 403 at that route gate — pinned by
 * `AdminUsersPermissionSeparationTest`, which drives both routes with the REAL
 * matrix instead of an invented one.
 *
 * The gate cannot express the narrowing on its own: the route gate passes no
 * `team_id`, and `User::hasPermission()` treats a missing argument as "my own
 * team" for a `team_admin` — exactly the shape every other team_admin grant has
 * (`categories.manage`, `events.manage`). The scope therefore lives here,
 * re-using {@see ResolvesAdminTeamScope::teamIds()} for the team list:
 *
 * 1. `index()` — a team_admin sees only the users holding a team-scoped
 *    `role_user` row in one of his own teams. That is the only place the
 *    schema records that a user belongs to a team at all: `role_user.team_id`
 *    is reserved for `team_admin` assignments by `validatedRoleEntries()`
 *    below, and no other role may carry one. The roles in the payload are
 *    narrowed to the same slice, so he is shown exactly what he may write.
 * 2. `updateRoles()` — the payload may only carry `team_admin` entries for one
 *    of his own teams. A foreign `team_id` or a mandant-level role
 *    (`mandant_admin`, `user`, `verifier`) is **403, not 422**: it is an
 *    authority question, not a malformed payload, and `assertOwnership()`
 *    answers it the same way. The REPLACE is scoped to his teams too, so it
 *    can neither strip the target's mandant-level roles nor a sibling club's
 *    `team_admin` row — without that, "assign within my team" would be a
 *    mandant-wide rewrite wearing a narrow hat.
 * 3. A target outside his roster is **404**, the same "not in your scope"
 *    answer {@see assertMandantScopedTarget()} gives; a foreign id must not be
 *    distinguishable from an id that never existed.
 * 4. `destroy()` is NOT narrowed, because no team_admin can reach it: he holds
 *    no `users.delete`, so the route gate answers 403 before the controller
 *    runs. A branch there would guard a request that cannot arrive — and the
 *    thing that keeps him away is the matrix, so the matrix is where the
 *    assertion belongs.
 *
 * A user holding `mandant_admin` AND a `team_admin` assignment is narrowed like
 * every other dual holder on this surface (categories, events, teams): the
 * narrowing comes from `teamIds()`, which keys on the presence of a
 * `team_admin` assignment. That is the codebase-wide behaviour, deliberate and
 * fail-closed. The gate, by contrast, grants him mandant-wide, because
 * `hasPermission()` returns early for any role that is not `team_admin`.
 *
 * ## The route binding is NOT a membership check (F1, 2026-09-29)
 *
 * `User::resolveRouteBindingQuery()` reuses ONE predicate for both callers, and
 * that predicate deliberately ORs in the GLOBAL `super_admin` branch: an
 * account with a `role_user` row carrying `mandant_id = null` +
 * `team_id = null` resolves on EVERY mandant's host. That is correct for the
 * IDENTITY check ("may this account act here at all?") and WRONG as the whole
 * RESOURCE check ("is this target one of my users?"): a global super_admin
 * holds no mandant-scoped assignment, appears in NO mandant's user list
 * (`index()` filters by `forMandant()`), and can never have a scoped role set
 * written for it. Measured: before the guard below, `PUT …/roles` answered 404
 * for that target while `DELETE` answered 200 and took the row — a blind,
 * irreversible IDOR over sequential integer ids, since the actor cannot even
 * list the target.
 *
 * Both write endpoints therefore ask the same question explicitly, in
 * {@see assertMandantScopedTarget()}, with the same predicate, the same 404 and
 * the same message. The predicate is also what keeps the invariant "every
 * mandant-scoped `role_user.mandant_id` equals the account's home mandant"
 * true (features/auth/01-auth-and-roles.md, "Die Eine-Account-ein-Mandant-Invariante"):
 * `updateRoles()` can only ever REPLACE an existing assignment, never create
 * the first one in a second mandant, so a cross-mandant account is unreachable
 * through the product today — the guard on `destroy()` is defence in depth for
 * a state the API cannot produce, not a patch on a live hole.
 *
 * Note what "cross-mandant for super_admin" means HERE: the GATE is
 * mandant-independent for him (`Gate::before` grants every gate), exactly like
 * every other permission. The target scope is what keeps him inside the current
 * mandant — a super_admin reaches another mandant's users by acting on that
 * mandant's host, which is how the whole admin surface works (D21), not
 * through a second, deletion-only rule. The platform-wide `super_admin` itself
 * is consequently NOT reachable through this route from ANY host (no
 * mandant-scoped assignment to match); only its own self-service deletion
 * (`DELETE /api/user/account`) can remove that account.
 */
class UserController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly AccountDeletionService $deletions) {}

    /**
     * All users of the current mandant with their scoped role assignments.
     * Filterable by `search` (name/email LIKE), `role` (role_user.slug) and
     * `team_id` (assignment pivot team). Global super_admin users never
     * surface here (they hold no mandant-scoped assignment).
     *
     * F4: a `team_admin` sees only the users holding a team-scoped assignment
     * in one of his OWN teams, and only those assignments of theirs (see the
     * class docblock). An empty `$teamIds` means unrestricted — the same
     * convention every controller using `ResolvesAdminTeamScope` follows, and
     * `teamIds()` aborts 403 for a team_admin without a team assignment, so
     * "empty" can never mean "a team_admin with nothing".
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        // ValidUtf8: raw form-encoded bytes (`search=\xFF`) pass `string` and
        // reach the raw LIKE / JSON encoder → HTTP 500 on Postgres. Reject as
        // 422 at the validation boundary (M4, mirrors M3).
        $validated = $request->validate([
            'search' => ['nullable', 'string', new ValidUtf8],
        ]);

        // A `?team_id` filter outside his scope is 403, not an empty list — the
        // same answer `AccreditationController::index()` gives. Filtering by
        // one of his own teams keeps working.
        if ($teamIds !== [] && $request->filled('team_id')) {
            abort_unless(
                in_array((int) $request->input('team_id'), $teamIds, true),
                403,
                'You may only manage items of your own team.',
            );
        }

        $query = User::query()
            ->whereHas(
                'roleUserAssignments',
                fn (Builder $q) => $this->scopeVisibleAssignments($q, $mandantId, $teamIds)
            )
            // The SAME predicate as the `whereHas` above — the list must never
            // advertise an assignment the save would reject with 403.
            ->with([
                'roleUserAssignments' => fn ($q) => $this
                    ->scopeVisibleAssignments($q, $mandantId, $teamIds)
                    ->with(['role', 'team']),
            ])
            // The confirmation dialog for a deletion must be able to name the
            // number of applications BEFORE the delete — after it, the rows are
            // gone and a count read then is always zero. `withCount` adds two
            // correlated subqueries to the SAME select (no extra round-trip, no
            // N+1) and is plain `select … (select count(*) …)` on both engines
            // (§2 portability).
            ->withCount(['applications', 'subApplications']);

        // M5: a whitespace-only `search` ('   ') is treated exactly like an
        // absent one — otherwise LIKE '%   %' would silently filter everything
        // out instead of returning the unfiltered list.
        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $term = LikeSearch::escape($search);
            $query->where(
                fn (Builder $q) => $q
                    // CC-R1: `LOWER()` on both sides pins case-insensitive search
                    // and keeps Postgres (LIKE is case-sensitive) in sync with
                    // SQLite (LIKE is case-insensitive by default) — portable.
                    ->whereRaw("LOWER(users.name) like LOWER(?) escape '\\'", ["%{$term}%"])
                    ->orWhereRaw("LOWER(users.email) like LOWER(?) escape '\\'", ["%{$term}%"]),
            );
        }

        if ($request->filled('role')) {
            $roleSlug = (string) $request->input('role');
            $query->whereHas(
                'roleUserAssignments',
                fn (Builder $q) => $this->scopeVisibleAssignments($q, $mandantId, $teamIds)->whereHas(
                    'role',
                    fn (Builder $r) => $r->where('roles.slug', $roleSlug),
                ),
            );
        }

        if ($request->filled('team_id')) {
            $teamId = (int) $request->input('team_id');
            $query->whereHas(
                'roleUserAssignments',
                fn (Builder $q) => $this->scopeVisibleAssignments($q, $mandantId, $teamIds)->forTeam($teamId)
            );
        }

        return AdminUserResource::collection(
            $query->orderBy('users.name')->orderBy('users.id')->get(),
        );
    }

    /**
     * Replace the user's role set within the current mandant: the previous
     * mandant-scoped `role_user` rows are deleted, the payload rows are
     * created. `super_admin` assignments (mandant_id = null) stay untouched.
     *
     * For a `team_admin` the replacement is narrowed to HIS OWN teams (F4, see
     * the class docblock): he may write `team_admin` assignments for those
     * teams and nothing else, and the delete touches only the rows in that
     * scope — so he can neither strip the target's mandant-level roles nor a
     * sibling club's `team_admin` row.
     *
     * Response: 200 `{data: roles}` with the fresh assignment payload, in the
     * same scope the actor may see and write.
     */
    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $teamIds = $this->teamIds($request);

        $this->assertAssignableTarget($user, $mandantId, $teamIds);

        $allowedRoles = [
            UserRole::MANDANT_ADMIN->value,
            UserRole::TEAM_ADMIN->value,
            UserRole::USER->value,
            UserRole::VERIFIER->value,
        ];

        $payload = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*.role' => ['required', 'string', Rule::in($allowedRoles)],
            'roles.*.team_id' => ['nullable', 'integer'],
        ]);

        $entries = $this->validatedRoleEntries($payload['roles'], $mandantId);

        $this->assertWithinRoleAuthority($entries, $teamIds);

        $this->scopeWritableAssignments($user->roleUserAssignments(), $mandantId, $teamIds)->delete();

        foreach ($entries as $entry) {
            RoleUser::create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('slug', $entry['role'])->valueOrFail('id'),
                'mandant_id' => $mandantId,
                'team_id' => $entry['team_id'],
            ]);
        }

        $roles = $this->scopeVisibleAssignments($user->roleUserAssignments(), $mandantId, $teamIds)
            ->with(['role', 'team'])
            ->orderBy('role_user.id')
            ->get();

        return response()->json(['data' => AdminUserResource::rolesPayload($roles)]);
    }

    /**
     * DELETE /api/admin/users/{user} — hard-delete an account (DSGVO: no
     * anonymisation, the row and everything referencing it go).
     *
     * Behind its own gate `users.delete` (see the class docblock) and behind
     * the SAME explicit target scope `updateRoles()` uses — the route binding
     * alone is not a membership check, because it also resolves the global
     * `super_admin` (F1, class docblock). A target without a mandant-scoped
     * assignment here is therefore a 404 here too: same predicate, same status,
     * same message as the roles endpoint.
     *
     * The scope is deliberately MANDANT-WIDE, not team-scoped: the only roles
     * holding `users.delete` are `mandant_admin` and `super_admin`, so there is
     * no narrowed caller to narrow for. `team_admin` reached the same
     * mandant-wide shape through `users.manage` and is refused by the GATE —
     * the matrix is what keeps him out, and `AdminUsersPermissionSeparationTest`
     * asserts that against the shipped matrix.
     *
     * The counts in the response are measured INSIDE the deletion transaction,
     * which is what makes them trustworthy: they are the number of rows that
     * actually went, not a second query that could disagree with them.
     *
     * 200 `{message, data: {applications_deleted, sub_applications_deleted,
     * media_files_deleted, role_assignments_deleted, sessions_deleted,
     * media_files_left_over: string[]}}`.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertMandantScopedTarget($user, $this->currentMandantId());

        $summary = $this->deletions->delete($user, 'admin', $request->user());

        return response()->json([
            'message' => 'Konto gelöscht.',
            'data' => [
                'applications_deleted' => $summary['counts']['applications'],
                'sub_applications_deleted' => $summary['counts']['sub_applications'],
                'media_files_deleted' => $summary['counts']['media'] - count($summary['residue']),
                'role_assignments_deleted' => $summary['counts']['role_assignments'],
                'sessions_deleted' => $summary['counts']['sessions'],
                'media_files_left_over' => $summary['residue'],
            ],
        ]);
    }

    /**
     * The target must hold at least one mandant-scoped `role_user` assignment
     * in the CURRENT mandant; otherwise 404.
     *
     * ONE predicate, TWO callers — `updateRoles()` and `destroy()` — because
     * the resource check and the identity check are NOT the same question and
     * the route binding only answers the second one (it ORs in the global
     * `super_admin` branch, see the class docblock). Two callers of one
     * predicate cannot drift apart; two inline copies of it would be the very
     * thing that let `destroy()` skip the check.
     *
     * 404, not 403: "you may not act on this target" and "this target does not
     * exist in your mandant" are the same answer for the caller — a foreign id
     * must not be distinguishable from an id that never existed.
     */
    private function assertMandantScopedTarget(User $user, int $mandantId): void
    {
        abort_unless(
            $user->roleUserAssignments()->forMandant($mandantId)->exists(),
            404,
            'User is not a member of this mandant.',
        );
    }

    /**
     * The `role_user` rows of the CURRENT mandant that the actor may SEE, and —
     * deliberately the same set — may WRITE (`scopeWritableAssignments()`).
     *
     * ONE predicate, FOUR callers: the `whereHas` that builds the list, the
     * eager-load that fills its `roles`, both list filters, and the payload of
     * `updateRoles()`. Four inline copies of "mandant, plus my teams if I am
     * narrowed" is exactly the shape a drift takes: a list that advertises an
     * assignment the save answers 403 to is a UI that lies, and a payload that
     * answers more than the list showed is a leak. The predicate is applied to
     * the relation builder, so it works identically inside `whereHas`, inside
     * `with` and on a direct query.
     *
     * An empty `$teamIds` means "unrestricted" — `teamIds()` returns `[]` for
     * every role that is not `team_admin`, and aborts 403 for a `team_admin`
     * without a team assignment, so the narrowed branch cannot be reached with
     * nothing to narrow to.
     *
     * @param  list<int>  $teamIds
     */
    private function scopeVisibleAssignments(Builder|Relation $query, int $mandantId, array $teamIds): Builder
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $builder->forMandant($mandantId);

        if ($teamIds !== []) {
            $builder->whereIn($builder->qualifyColumn('team_id'), $teamIds);
        }

        return $builder;
    }

    /**
     * The `role_user` rows `updateRoles()` is allowed to DELETE.
     *
     * Identical to {@see scopeVisibleAssignments()} on purpose, and that is the
     * whole point of the F4 narrowing: a `team_admin` replaces only the
     * assignments inside his own teams. Without it the endpoint would be a
     * mandant-wide rewrite — a narrow hat over a broad delete — and he could
     * strip a `mandant_admin`'s role, which is a far bigger prize than the one
     * he was granted.
     *
     * @param  list<int>  $teamIds
     */
    private function scopeWritableAssignments(Builder|Relation $query, int $mandantId, array $teamIds): Builder
    {
        return $this->scopeVisibleAssignments($query, $mandantId, $teamIds);
    }

    /**
     * The target must be assignable by THIS actor: a mandant-scoped member for
     * an unrestricted one (super_admin / mandant_admin), and additionally a
     * holder of a team-scoped assignment in one of the actor's own teams for a
     * narrowed `team_admin`.
     *
     * 404 in both cases, for the reason {@see assertMandantScopedTarget()} gives:
     * "you may not act on this target" and "this target does not exist in your
     * scope" must be the same answer, or a foreign id becomes enumerable.
     * A narrower target is still a mandant member first, so the existing
     * predicate is composed rather than replaced.
     *
     * @param  list<int>  $teamIds
     */
    private function assertAssignableTarget(User $user, int $mandantId, array $teamIds): void
    {
        $this->assertMandantScopedTarget($user, $mandantId);

        if ($teamIds === []) {
            return;
        }

        abort_unless(
            $this->scopeVisibleAssignments($user->roleUserAssignments(), $mandantId, $teamIds)->exists(),
            404,
            'User is not a member of your team.',
        );
    }

    /**
     * A narrowed actor may write `team_admin` assignments for his own teams and
     * NOTHING else — a foreign `team_id` or a mandant-level role
     * (`mandant_admin`, `user`, `verifier`) is refused.
     *
     * **403, not 422.** The payload is well-formed here; the question is
     * whether this actor may write this row, which is the same kind of question
     * `assertOwnership()` answers with 403 everywhere else on this surface. A
     * `mandant_admin` payload from a `team_admin` is a privilege request, and
     * saying "invalid" would describe a rule he could satisfy by sending
     * something else.
     *
     * @param  list<array{role: string, team_id: ?int}>  $entries
     * @param  list<int>  $teamIds
     */
    private function assertWithinRoleAuthority(array $entries, array $teamIds): void
    {
        if ($teamIds === []) {
            return;
        }

        foreach ($entries as $entry) {
            abort_unless(
                $entry['role'] === UserRole::TEAM_ADMIN->value
                    && $entry['team_id'] !== null
                    && in_array((int) $entry['team_id'], $teamIds, true),
                403,
                'You may only assign team_admin roles within your own team.',
            );
        }
    }

    /**
     * Per-entry constraints beyond the base rules:
     *
     * - `team_admin` requires a `team_id` that belongs to the current mandant.
     * - any other role rejects a `team_id` (422).
     * - duplicate (role, team_id) assignments are rejected (422).
     *
     * @param  list<array{role: string, team_id?: mixed}>  $rawEntries
     * @return list<array{role: string, team_id: ?int}>
     */
    private function validatedRoleEntries(array $rawEntries, int $mandantId): array
    {
        $seen = [];
        $entries = [];

        foreach ($rawEntries as $entry) {
            $role = $entry['role'];
            $teamId = $entry['team_id'] ?? null;

            if ($role === UserRole::TEAM_ADMIN->value) {
                if ($teamId === null) {
                    throw ValidationException::withMessages([
                        'roles' => 'A team_admin assignment requires a team_id of the current mandant.',
                    ]);
                }

                $teamId = (int) $teamId;

                if (! Team::query()->forMandant($mandantId)->whereKey($teamId)->exists()) {
                    throw ValidationException::withMessages([
                        'roles' => "Team {$teamId} does not belong to the current mandant.",
                    ]);
                }
            } elseif ($teamId !== null) {
                throw ValidationException::withMessages([
                    'roles' => 'team_id is only allowed for a team_admin assignment.',
                ]);
            }

            $scopeKey = $role.':'.($teamId ?? '');

            if (in_array($scopeKey, $seen, true)) {
                throw ValidationException::withMessages([
                    'roles' => 'Duplicate role assignment for the same scope.',
                ]);
            }

            $seen[] = $scopeKey;
            $entries[] = ['role' => $role, 'team_id' => $teamId];
        }

        return $entries;
    }
}

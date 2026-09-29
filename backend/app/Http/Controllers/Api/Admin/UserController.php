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
 * Guarded by `can:users.manage` (super_admin + mandant_admin). Role
 * replacement is union-friendly (P1d-F2): several roles per (user, mandant)
 * are allowed and each assignment is written separately. The global
 * `super_admin` assignment (mandant_id = null) is never touched and
 * `super_admin` is rejected in the payload.
 *
 * `destroy()` sits behind its OWN gate, `users.delete`, deliberately separate
 * from `users.manage`: that one is ROLE ASSIGNMENT, and hanging account
 * termination off it would give whoever may hand out roles the power to end an
 * account.
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
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();

        // ValidUtf8: raw form-encoded bytes (`search=\xFF`) pass `string` and
        // reach the raw LIKE / JSON encoder → HTTP 500 on Postgres. Reject as
        // 422 at the validation boundary (M4, mirrors M3).
        $validated = $request->validate([
            'search' => ['nullable', 'string', new ValidUtf8],
        ]);

        $query = User::query()
            ->whereHas('roleUserAssignments', fn (Builder $q) => $q->forMandant($mandantId))
            ->with(['roleUserAssignments' => fn ($q) => $q->forMandant($mandantId)->with(['role', 'team'])])
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
                fn (Builder $q) => $q->forMandant($mandantId)->whereHas(
                    'role',
                    fn (Builder $r) => $r->where('roles.slug', $roleSlug),
                ),
            );
        }

        if ($request->filled('team_id')) {
            $teamId = (int) $request->input('team_id');
            $query->whereHas('roleUserAssignments', fn (Builder $q) => $q->forMandant($mandantId)->forTeam($teamId));
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
     * Response: 200 `{data: roles}` with the fresh assignment payload.
     */
    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $mandantId = $this->currentMandantId();

        $this->assertMandantScopedTarget($user, $mandantId);

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

        $user->roleUserAssignments()->forMandant($mandantId)->delete();

        foreach ($entries as $entry) {
            RoleUser::create([
                'user_id' => $user->id,
                'role_id' => Role::query()->where('slug', $entry['role'])->valueOrFail('id'),
                'mandant_id' => $mandantId,
                'team_id' => $entry['team_id'],
            ]);
        }

        $roles = $user->roleUserAssignments()
            ->forMandant($mandantId)
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

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin user management (P2c): list the users of the current mandant (scoped
 * to users holding at least one `role_user` assignment there) and replace
 * their mandant role set.
 *
 * Guarded by `can:users.manage` (super_admin + mandant_admin). Role
 * replacement is union-friendly (P1d-F2): several roles per (user, mandant)
 * are allowed and each assignment is written separately. The global
 * `super_admin` assignment (mandant_id = null) is never touched and
 * `super_admin` is rejected in the payload.
 */
class UserController extends Controller
{
    use ResolvesAdminTeamScope;

    /**
     * All users of the current mandant with their scoped role assignments.
     * Filterable by `search` (name/email LIKE), `role` (role_user.slug) and
     * `team_id` (assignment pivot team). Global super_admin users never
     * surface here (they hold no mandant-scoped assignment).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();

        $query = User::query()
            ->whereHas('roleUserAssignments', fn (Builder $q) => $q->forMandant($mandantId))
            ->with(['roleUserAssignments' => fn ($q) => $q->forMandant($mandantId)->with(['role', 'team'])]);

        if ($request->filled('search')) {
            $term = $this->escapeLike((string) $request->input('search'));
            $query->where(
                fn (Builder $q) => $q
                    ->whereRaw("users.name like ? escape '\\'", ["%{$term}%"])
                    ->orWhereRaw("users.email like ? escape '\\'", ["%{$term}%"]),
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

    /**
     * Escape LIKE wildcards so a search for literal `%`/`_` does not act as a
     * pattern. Applied with an explicit `ESCAPE '\'` clause (portable across
     * Postgres and SQLite — mirrors AdminApplicationController / BlacklistController).
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

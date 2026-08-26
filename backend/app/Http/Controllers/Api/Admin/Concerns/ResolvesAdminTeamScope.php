<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\RoleUser;
use App\Models\Team;
use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared within-mandant scoping for the P2b admin controllers (categories,
 * events, teams). The route groups are already guarded by the `can:…`
 * permission gates; this trait narrows the team_admin scope to his
 * role-assigned team(s) and keeps every resource operation inside the current
 * mandant:
 *
 * - super_admin / mandant_admin → unrestricted within the current mandant
 *   (empty team scope; `team_id` comes from the payload).
 * - team_admin → locked to the team(s) of his role assignment(s). Union
 *   semantics (P1d-F2): several team_admin assignments may yield several
 *   teams. A foreign `team_id` never takes effect (categories ignore it,
 *   events answer 403).
 *
 * Cross-mandant ids are answered with 404 (scoped lookup), ownership
 * violations with 403.
 */
trait ResolvesAdminTeamScope
{
    /**
     * The current mandant's id, or 404 when no mandant context exists.
     */
    protected function currentMandantId(): int
    {
        $mandantId = MandantContext::currentId();
        abort_if($mandantId === null, 404, 'No mandant context for this request.');

        return $mandantId;
    }

    /**
     * The team ids the current user is restricted to, or an empty array when
     * unrestricted (super_admin / mandant_admin / any non-team_admin role).
     * For a team_admin without a single team assignment the request is
     * rejected with 403 (he has no team to act on).
     *
     * @return list<int>
     */
    protected function teamIds(Request $request): array
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $mandantId = $this->currentMandantId();
        $assignments = $user->roleAssignmentsForMandant($mandantId);

        $isTeamAdmin = $assignments->contains(
            static fn (RoleUser $assignment): bool => $assignment->role->slug === UserRole::TEAM_ADMIN->value,
        );

        if (! $isTeamAdmin) {
            return [];
        }

        $teamIds = $assignments
            ->filter(
                static fn (RoleUser $assignment): bool => $assignment->role->slug === UserRole::TEAM_ADMIN->value
                    && $assignment->team_id !== null,
            )
            ->map(static fn (RoleUser $assignment): int => (int) $assignment->team_id)
            ->unique()
            ->values()
            ->all();

        abort_if($teamIds === [], 403, 'A team_admin needs a team assignment.');

        return $teamIds;
    }

    /**
     * The team id a written row gets. For a team_admin the row lands on one of
     * his own teams: an explicit payload `team_id` inside his scope is honored,
     * anything else falls back to his first allowed team (the single-team case
     * keeps the historical "force own team" behavior). Otherwise the validated
     * payload value wins, keeping the existing team on partial updates when the
     * key is absent.
     */
    protected function resolveTeamId(array $validated, int $mandantId, array $teamIds, ?Model $model = null): ?int
    {
        if ($teamIds !== []) {
            $payloadTeamId = array_key_exists('team_id', $validated) ? $validated['team_id'] : null;

            if ($payloadTeamId !== null && in_array((int) $payloadTeamId, $teamIds, true)) {
                return (int) $payloadTeamId;
            }

            return $teamIds[0];
        }

        if (! array_key_exists('team_id', $validated)) {
            return $model?->team_id;
        }

        $teamId = $validated['team_id'];

        if ($teamId !== null) {
            $this->assertTeamOfMandant((int) $teamId, $mandantId);
        }

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * The team-level slug-uniqueness scope for a written row: mirrors
     * `resolveTeamId()` without touching the database.
     */
    protected function targetTeamId(Request $request, array $teamIds, mixed $existingTeamId): mixed
    {
        if ($teamIds !== []) {
            $payloadTeamId = $request->input('team_id');

            if ($payloadTeamId !== null && in_array((int) $payloadTeamId, $teamIds, true)) {
                return (int) $payloadTeamId;
            }

            return $teamIds[0];
        }

        return $request->has('team_id') ? $request->input('team_id') : $existingTeamId;
    }

    /**
     * A requested team must belong to the current mandant, else 404.
     */
    protected function assertTeamOfMandant(int $teamId, int $mandantId): void
    {
        abort_unless(
            Team::query()->forMandant($mandantId)->whereKey($teamId)->exists(),
            404,
            'Team does not belong to this mandant.',
        );
    }

    /**
     * Route-model-bound resources of another mandant are not reachable (404).
     */
    protected function assertMandantScope(Model $model, int $mandantId): Model
    {
        abort_unless((int) $model->getAttribute('mandant_id') === $mandantId, 404);

        return $model;
    }

    /**
     * team_admin may only modify team-level rows of his own team(s); mandant-
     * level rows are read-only for him. super_admin / mandant_admin are
     * unrestricted (empty scope).
     */
    protected function assertOwnership(Model $model, array $teamIds): void
    {
        if ($teamIds === []) {
            return;
        }

        abort_unless(
            $model->getAttribute('team_id') !== null
                && in_array((int) $model->getAttribute('team_id'), $teamIds, true),
            403,
            'You may only manage items of your own team.',
        );
    }

    /**
     * A `?accreditation_id` filter must reference an accreditation of the
     * current mandant (422 otherwise); a team_admin may only filter within
     * his own teams (403 otherwise). Shared by every admin controller that
     * exposes an accreditation-scoped list endpoint.
     */
    protected function assertAccreditationFilter(int $accreditationId, int $mandantId, array $teamIds): void
    {
        $query = Accreditation::query()->forMandant($mandantId)->whereKey($accreditationId);

        if ($teamIds !== []) {
            $query->whereIn('team_id', $teamIds);
            abort_unless($query->exists(), 403);

            return;
        }

        abort_unless($query->exists(), 422);
    }
}

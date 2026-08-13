<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Enums\UserRole;
use App\Models\Team;
use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared within-mandant scoping for the P2b admin controllers (categories,
 * events). The route groups are already guarded by the `can:…` permission
 * gates; this trait narrows the team_admin scope to his role-assigned team and
 * keeps every resource operation inside the current mandant:
 *
 * - super_admin / mandant_admin → unrestricted within the current mandant
 *   (null team scope; team_id comes from the payload).
 * - team_admin → locked to the team of his role assignment; a foreign
 *   `team_id` never takes effect (categories ignore it, events answer 403).
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
     * The team the current user is restricted to, or null when unrestricted
     * (super_admin / mandant_admin).
     */
    protected function teamScope(Request $request): ?int
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $mandantId = MandantContext::currentId();

        if ($user->isSuperAdmin()) {
            return null;
        }

        if ($mandantId !== null && $user->isMandantAdmin($mandantId)) {
            return null;
        }

        $assignment = $user->roleAssignmentForMandant($mandantId);

        if ($assignment?->role->slug === UserRole::TEAM_ADMIN->value) {
            $teamId = $assignment->team_id === null ? null : (int) $assignment->team_id;
            abort_if($teamId === null, 403, 'A team_admin needs a team assignment.');

            return $teamId;
        }

        return null;
    }

    /**
     * The team id a written row gets: the team_admin's own team (payload value
     * ignored/forced), otherwise the validated payload value — keeping the
     * existing team on partial updates when the key is absent.
     */
    protected function resolveTeamId(array $validated, int $mandantId, ?int $teamScope, ?Model $model = null): ?int
    {
        if ($teamScope !== null) {
            return $teamScope;
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
     * team_admin may only modify team-level rows of his own team; mandant-level
     * rows are read-only for him. super_admin / mandant_admin are unrestricted
     * (null scope).
     */
    protected function assertOwnership(Model $model, ?int $teamScope): void
    {
        if ($teamScope === null) {
            return;
        }

        abort_unless(
            $model->getAttribute('team_id') !== null
                && (int) $model->getAttribute('team_id') === $teamScope,
            403,
            'You may only manage items of your own team.',
        );
    }
}

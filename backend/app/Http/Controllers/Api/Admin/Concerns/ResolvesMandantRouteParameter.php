<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Models\Mandant;
use App\Support\MandantContext;
use Illuminate\Http\Request;

/**
 * The `{mandant}` route parameter of the tenant-CRUD admin surface.
 *
 * `Mandant` is the ONE model that must NOT get a mandant-scoped
 * `resolveRouteBindingQuery()`: a `where('mandant_id', currentId())`-style
 * scope on it is meaningless (it has no `mandant_id`) and its naive analogue —
 * "the addressed mandant must be the current one" — would take away the
 * super_admin's entire purpose. He manages every mandant from whatever host he
 * happens to be on: `PUT /api/admin/mandants/{B}` while A is the request host
 * is a tested, intended flow (`AdminMandantTest` addresses `mandantB` with
 * `mandantA` as the context). So the check belongs HERE, in the controller, and
 * it must branch on the caller:
 *
 * - super_admin → the addressed mandant is whatever he addresses, from any
 *   host. No check at all. This is the branch that must never be tightened.
 * - everyone else → the addressed mandant must be the current one (host-derived,
 *   `MandantContext`), else 404.
 *
 * The second branch is what `TeamController::index` already enforced inline;
 * this trait is that single implementation, promoted so the three sibling
 * controllers that take the same parameter share it instead of each re-inventing
 * (or, worse, omitting) it.
 *
 * ## Why the controller guard and not the binding
 *
 * A route-level check would be a no-op for super_admin and a 404 for everyone
 * else — i.e. it would break him, so it cannot live in the binding. And it is
 * NOT redundant belt-and-braces on top of the `can:mandants.manage` route gate:
 * `TeamController::store`/`update`/`destroy` re-check `super_admin` in the
 * controller even though `teams.manage` is documented as super_admin-only, and
 * that re-check is load-bearing today — `config/permissions.php` grants
 * `teams.manage` to `TEAM_ADMIN`, so the route gate genuinely admits a
 * non-super-admin and only the controller stops him. A future grant of
 * `mandants.manage` (or `teams.view`, which mandant_admin/team_admin hold
 * today) must not silently open cross-tenant tenant CRUD.
 *
 * ## Why 404 and not 403
 *
 * 404 is the established code for the mandant axis in this codebase
 * (`assertMandantScope()`, `assertTeamOfMandant()`, every
 * `resolveRouteBindingQuery()` scope on the tenant models), and 403 is reserved
 * for the team/role axes (`assertOwnership()`, `authorizeSuperAdmin()`,
 * `assertMayWrite()`). Keeping the two axes on distinct codes is what lets a
 * caller — and the `UserMedia` binding note — reason about them at all.
 *
 * It is also the honest code for this specific failure: a non-super-admin has no
 * mandate over the addressed mandant, so "forbidden" would assert a
 * relationship that does not exist, while "not found" states the truth — the
 * mandant is not reachable from this host. And there is nothing to hide: every
 * mandant has a public portal, its hostname sits in the public `trustHosts`
 * allow-list, and the table is a handful of rows. 404 therefore leaks strictly
 * less than 403 while keeping the API consistent.
 */
trait ResolvesMandantRouteParameter
{
    /**
     * The addressed mandant is the current one, unless the caller is a global
     * super_admin — who may address any mandant from any host.
     */
    protected function assertMandantRouteParameter(Request $request, Mandant $mandant): Mandant
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return $mandant;
        }

        $currentId = MandantContext::currentId();

        abort_unless(
            $currentId !== null && (int) $mandant->id === $currentId,
            404,
            'Mandant does not belong to the current request context.',
        );

        return $mandant;
    }
}

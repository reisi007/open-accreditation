<?php

namespace App\Providers;

use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model policy mappings. Populated in P2/P3 once the resource models
     * (Mandant, Team, Kategorie, Event, Akkreditierung) exist.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [];

    /**
     * Register any application authentication / authorization services.
     */
    public function boot(): void
    {
        // Global super admin bypass: short-circuits every ability check with
        // `true`, independent of any mandant context. Must tolerate a null
        // user (guests fall through to the gate definitions below).
        Gate::before(fn (?User $user, string $ability): ?bool => $user?->isSuperAdmin() ? true : null);

        // One gate per permission from the role→permission matrix
        // (`config/permissions.php`). Each gate resolves the role of the user
        // in the *current* mandant (MandantContext) — no role there means deny
        // (cross-mandant isolation). team_admin additionally validates the
        // optional team_id argument against his role assignment.
        foreach ($this->permissionList() as $permission) {
            Gate::define($permission, function (?User $user, ?int $teamId = null) use ($permission): bool {
                if ($user === null) {
                    return false;
                }

                return $user->hasPermission($permission, MandantContext::currentId(), $teamId);
            });
        }
    }

    /**
     * The flat permission list derived from the matrix. `'*'` marks the global
     * super_admin bypass and is not registered as a gate.
     *
     * @return list<string>
     */
    private function permissionList(): array
    {
        return array_values(array_unique(array_merge(
            ...array_values(array_map(
                static fn (array $permissions): array => array_values(array_filter(
                    $permissions,
                    static fn (string $permission): bool => $permission !== '*',
                )),
                config('permissions'),
            )),
        )));
    }
}

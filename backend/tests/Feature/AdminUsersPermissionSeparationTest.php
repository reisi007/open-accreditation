<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F4 (2026-10-06): `users.delete` is DECLARED separate from `users.manage` — and
 * until the decision below, nothing made that declaration mean anything.
 *
 * The rationale in `config/permissions.php` and in `routes/api.php` is "wer
 * Rollen verteilen darf, darf damit nicht Konten beenden". Measured: rewriting
 * the delete route's gate from `can:users.delete` to `can:users.manage` left
 * 30/30 and 64/64 green — the swap was invisible, because the two permissions
 * had IDENTICAL holders (`mandant_admin`, plus `super_admin` through `'*'`).
 * Every behavioural test that existed was satisfied by both routes reading
 * either permission.
 *
 * ## The decision this file now pins
 *
 * **Nutzerentscheid 2026-10-06:** `team_admin` may assign roles WITHIN his own
 * team, but may not terminate accounts. That is the smallest holder which makes
 * the two sets differ — he appoints and dismisses the admins of his club and
 * cannot end anyone's account. The rejected alternative was a new
 * "Mandant-Manager" role: a role nobody holds would have been the same missing
 * carrier one level down.
 *
 * ## Why these tests use the REAL matrix now
 *
 * The previous version drove both routes under `config()` overrides that
 * artificially separated the permissions. That could only ever prove "the two
 * routes read different strings"; it said nothing about the shipped system,
 * because the shipped system gave both permissions to the same role. With
 * `team_admin` holding `users.manage` and not `users.delete`, the artificial
 * split is unnecessary — every assertion below runs against the unmodified
 * `config/permissions.php`, so a swap now breaks a test that describes the
 * product rather than a test that describes a fixture.
 */
class AdminUsersPermissionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private Team $team;

    private Team $foreignTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband', 'name' => 'Verband']);
        $this->team = $this->mandant->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->foreignTeam = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B'])
            ->teams()->create(['name' => 'Fremd', 'slug' => 'fremd']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /**
     * The carrier, exercised on the shipped matrix: a `team_admin` distributes
     * roles inside his own team and is refused the account deletion.
     *
     * This is the test the old swap could not break. With `team_admin` holding
     * only `users.manage`, rewriting the delete gate to `can:users.manage`
     * answers 200 here — the account goes — and this test fails on the status
     * AND on the `assertDatabaseHas`. Two assertions, because the status alone
     * would also be satisfied by an unrelated 403: the surviving row is what
     * proves nothing was deleted.
     */
    public function test_a_team_admin_may_assign_roles_but_may_not_delete_accounts(): void
    {
        $actor = $this->teamAdminOf($this->team);
        $target = $this->teamAdminOf($this->team, 'ziel@example.com');

        $this->actingAsApi($actor)
            ->putJson('/api/admin/users/'.$target->id.'/roles', [
                'roles' => [['role' => 'team_admin', 'team_id' => $this->team->id]],
            ])
            ->assertOk();

        $this->actingAsApi($actor)
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    /**
     * The other direction — a role that may delete but not assign — has NO real
     * carrier, and that is a property of the matrix, not an oversight: whoever
     * may end an account is a Verband-level authority, and both such roles
     * (`mandant_admin`, `super_admin`) also assign roles. So it is asserted as
     * what it is: a set difference, read off the shipped matrix, with
     * `team_admin` as its whole content.
     *
     * Without this, the test above would pass for the wrong reason — a matrix
     * in which the two sets merely HAPPEN to coincide for `team_admin` would
     * satisfy it too.
     */
    public function test_the_shipped_matrix_separates_the_two_permissions(): void
    {
        $manageHolders = $this->holdersOf('users.manage');
        $deleteHolders = $this->holdersOf('users.delete');

        $this->assertSame(
            ['team_admin'],
            array_values(array_diff($manageHolders, $deleteHolders)),
            'users.manage must have exactly one holder users.delete does not have, or the F4 carrier is gone again.',
        );

        // The reverse must stay EMPTY: no role may end an account without being
        // allowed to hand out roles.
        $this->assertSame([], array_values(array_diff($deleteHolders, $manageHolders)));
    }

    /**
     * The declaration, checked on the route table. Independent of every
     * behavioural test above: it fails on a swapped gate even if the matrix is
     * edited the same commit, and it fails on a matrix edit even if the gates
     * are untouched.
     */
    public function test_the_two_routes_are_gated_by_two_different_permissions(): void
    {
        $this->assertSame(
            ['can:users.manage'],
            $this->canMiddlewareOf('api.admin.users.roles.update'),
        );

        $this->assertSame(
            ['can:users.delete'],
            $this->canMiddlewareOf('api.admin.users.destroy'),
        );
    }

    /**
     * `mandant_admin` is unchanged by F4: both permissions, whole mandant. The
     * split is not "nobody but team_admin may assign" — without this, a
     * regression that over-corrected and took `users.manage` away from the
     * Verband admin would look like a green separation.
     */
    public function test_a_mandant_admin_still_holds_both_permissions(): void
    {
        $actor = $this->mandantAdmin();
        $target = $this->teamAdminOf($this->team, 'ziel@example.com');

        $this->actingAsApi($actor)
            ->putJson('/api/admin/users/'.$target->id.'/roles', ['roles' => [['role' => 'user']]])
            ->assertOk();

        $this->actingAsApi($actor)
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    /**
     * The `super_admin` bypass is what carries `users.delete` mandant-
     * independently, so the separation above depends on it. Pinned here at the
     * GATE level: remove `Gate::before`'s short-circuit and this fails, which is
     * the point — a "bypass removed" refactor must not quietly drop the
     * account-termination authority nobody else has.
     */
    public function test_super_admin_holds_users_delete_through_the_global_bypass(): void
    {
        $superAdmin = $this->globalSuperAdmin();

        // No mandant context at all — the assignment carries `mandant_id = null`.
        MandantContext::reset();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('users.delete'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('users.manage'));
    }

    /**
     * A `team_admin` holds neither on a FOREIGN mandant: the grant is
     * mandant-scoped like every other, so the F4 widening does not leak the
     * Verband's roster across a host boundary.
     */
    public function test_the_team_admin_grant_does_not_leak_into_another_mandant(): void
    {
        $actor = $this->teamAdminOf($this->team);

        $foreignMandant = Mandant::query()->findOrFail($this->foreignTeam->mandant_id);
        MandantContext::set($foreignMandant);

        $this->assertFalse(Gate::forUser($actor)->allows('users.manage'));

        // And the gate agrees before the controller is ever reached.
        $this->actingAsApi($actor)->getJson('/api/admin/users')->assertStatus(403);
    }

    /**
     * The roles holding one permission, read off the shipped matrix. `'*'` is
     * the global `super_admin` bypass, not a permission, so it is not a holder
     * here — the bypass is asserted separately above.
     *
     * @return list<string>
     */
    private function holdersOf(string $permission): array
    {
        $holders = [];

        foreach ((array) config('permissions') as $roleSlug => $permissions) {
            if (in_array($permission, (array) $permissions, true)) {
                $holders[] = (string) $roleSlug;
            }
        }

        sort($holders);

        return $holders;
    }

    /**
     * The `can:` middleware of one named route, in declaration order.
     *
     * @return list<string>
     */
    private function canMiddlewareOf(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "route [{$routeName}] must exist.");

        return array_values(array_filter(
            $route->gatherMiddleware(),
            static fn (string $middleware): bool => str_starts_with($middleware, 'can:'),
        ));
    }

    private function mandantAdmin(string $email = 'mandant-admin@example.com'): User
    {
        return $this->member(UserRole::MANDANT_ADMIN, $email);
    }

    private function teamAdminOf(Team $team, string $email = 'team-admin@example.com'): User
    {
        return $this->member(UserRole::TEAM_ADMIN, $email, $team->id);
    }

    private function globalSuperAdmin(): User
    {
        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }

    private function member(UserRole $role, string $email = 'actor@example.com', ?int $teamId = null): User
    {
        $user = User::factory()->create([
            'mandant_id' => $this->mandant->id,
            'email' => $email,
        ]);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => $teamId,
        ]);

        return $user;
    }
}

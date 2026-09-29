<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F4 (2026-09-29): `users.delete` is DECLARED separate from `users.manage` —
 * and nothing pinned the declaration.
 *
 * The rationale in `config/permissions.php` and in `routes/api.php` is "wer
 * Rollen verteilen darf, darf damit nicht Konten beenden". Measured: rewriting
 * the delete route's gate from `can:users.delete` to `can:users.manage` left
 * 30/30 and 64/64 green — the swap is invisible, because the two permissions
 * currently have IDENTICAL holders (`mandant_admin`, plus `super_admin` through
 * `'*'`). Every behavioural test that exists today is satisfied by both routes
 * reading either permission.
 *
 * A plain "the two holder sets differ" assertion would therefore FAIL today —
 * they do not differ — and inventing a role that separates them is a product
 * decision this test must not smuggle in (see the open question in
 * features/auth/01-auth-and-roles.md). What IS pinnable without inventing
 * anything is the BEHAVIOUR the declaration exists for, under a permission
 * matrix that actually separates the two: both routes are exercised with a role
 * holding exactly one of the permissions, and the separation must hold in both
 * directions. A swapped gate turns that into a 403-vs-200 failure.
 *
 * The third test pins the DECLARATION itself, because the behavioural pair
 * above says nothing at all while the shipped matrix gives both permissions to
 * the same role — it is the only assertion that fails today if someone swaps
 * the gate back.
 */
class AdminUsersPermissionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband', 'name' => 'Verband']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_a_role_that_may_assign_roles_may_not_delete_accounts(): void
    {
        // A matrix in which the two permissions actually differ: this role
        // distributes roles, nothing else.
        config(['permissions.'.UserRole::VERIFIER->value => ['verification.verify', 'users.manage']]);

        $actor = $this->member(UserRole::VERIFIER);
        $target = $this->member(UserRole::USER, 'ziel@example.com');

        $this->actingAsApi($actor)
            ->putJson('/api/admin/users/'.$target->id.'/roles', ['roles' => [['role' => 'user']]])
            ->assertOk();

        $this->actingAsApi($actor)
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_a_role_that_may_delete_accounts_may_not_assign_roles(): void
    {
        config(['permissions.'.UserRole::VERIFIER->value => ['verification.verify', 'users.delete']]);

        $actor = $this->member(UserRole::VERIFIER);
        $target = $this->member(UserRole::USER, 'ziel@example.com');

        $this->actingAsApi($actor)
            ->putJson('/api/admin/users/'.$target->id.'/roles', ['roles' => [['role' => 'user']]])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id]);

        $this->actingAsApi($actor)
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    /**
     * The declaration, checked on the route table. This is the assertion that
     * fails TODAY if the gate is swapped, because the shipped matrix hands both
     * permissions to `mandant_admin` and the two behavioural tests above only
     * look at a matrix that separates them.
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

    private function member(UserRole $role, string $email = 'actor@example.com'): User
    {
        $user = User::factory()->create([
            'mandant_id' => $this->mandant->id,
            'email' => $email,
        ]);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }
}

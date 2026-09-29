<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * F5 (2026-09-29): the ONE ACCOUNT — ONE MANDANT invariant.
 *
 * `users.mandant_id` is the documented "owning (home) mandant" and the
 * uniqueness anchor for `(mandant_id, email)`
 * (`0001_01_01_000000_create_users_table.php`); `NULL` marks a GLOBAL account,
 * which in production is exactly one: the platform-wide `super_admin`.
 *
 * The invariant this file pins: **every mandant-scoped `role_user.mandant_id`
 * equals the account's home mandant.** The only legal `NULL` pivot is the
 * global `super_admin` one (`mandant_id = team_id = null`), and apart from the
 * bootstrap admin a global pivot can also sit on an account that additionally
 * belongs to one mandant — the hybrid super_admin shape the roles endpoint
 * preserves and the delete endpoint accepts.
 *
 * There are three `role_user` write sites in the whole backend, and this file
 * covers all three, because the invariant is only worth as much as the number of
 * places that could break it:
 *
 *  1. `AuthController::register()` — `users.mandant_id = current` and
 *     `role_user.mandant_id = $mandant->id`: the same mandant.
 *  2. `UserController::updateRoles()` — `role_user.mandant_id = $mandantId`, and
 *     it is only reached after the membership check, so it can REPLACE an
 *     assignment of the account's own mandant but never CREATE the first one in
 *     a second mandant. The chicken-and-egg is pinned below.
 *  3. `DatabaseSeeder` — the bootstrap admin: `users.mandant_id = null` AND
 *     `role_user.mandant_id = null`.
 *
 * A dual-mandant account is therefore **not reachable through the product**;
 * the verifier's fixture for it was built by direct Eloquent writes. The
 * membership guard in `UserController` (F1) is defence in depth for that state,
 * not a patch on a live attack surface — this file is what keeps that sentence
 * true, so a fourth write site cannot be added without turning a test red.
 */
class RoleAssignmentMandantInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Write site 1: registration
     | ------------------------------------------------------------------- */

    public function test_registration_binds_the_role_assignment_to_the_accounts_own_mandant(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertCreated();

        $user = User::query()->where('email', 'max@example.com')->firstOrFail();

        $this->assertSame($this->mandantA->id, (int) $user->mandant_id);
        $this->assertHomeMandantInvariant($user);
    }

    /* ---------------------------------------------------------------------
     | Write site 2: the admin role replacement
     | ------------------------------------------------------------------- */

    public function test_update_roles_writes_only_into_the_accounts_own_mandant(): void
    {
        $target = $this->member($this->mandantA, 'ziel@example.com');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->putJson('/api/admin/users/'.$target->id.'/roles', ['roles' => [['role' => 'verifier']]])
            ->assertOk();

        $this->assertSame(1, RoleUser::query()->where('user_id', $target->id)->count());
        $this->assertHomeMandantInvariant($target->fresh());
    }

    /**
     * The other direction: the replacement of a mandant-scoped role set leaves
     * a GLOBAL pivot alone (a super_admin who also belongs to this mandant
     * keeps his platform-wide role), and writes nothing anywhere else.
     */
    public function test_update_roles_keeps_a_global_pivot_and_writes_only_into_the_own_mandant(): void
    {
        $hybrid = $this->member($this->mandantA, 'hybrid@example.com');
        $this->giveGlobalSuperAdmin($hybrid);

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->putJson('/api/admin/users/'.$hybrid->id.'/roles', ['roles' => [['role' => 'user']]])
            ->assertOk();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $hybrid->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->value('id'),
            'mandant_id' => null,
            'team_id' => null,
        ]);
        $this->assertSame(0, RoleUser::query()->where('user_id', $hybrid->id)->where('mandant_id', $this->mandantB->id)->count());
        $this->assertHomeMandantInvariant($hybrid->fresh());
    }

    /**
     * The strongest available statement of "a cross-mandant account is not
     * reachable through the API": mandant B's admin cannot even START a role set
     * for an account that belongs to mandant A — the membership check answers
     * 404 before a single pivot could be written. Chicken-and-egg, pinned in both
     * directions (the roles route AND the delete route, which is what F1 was
     * about).
     */
    public function test_an_admin_cannot_give_an_account_a_first_assignment_in_a_second_mandant(): void
    {
        $target = $this->member($this->mandantA, 'nur-a@example.com');
        $adminB = $this->mandantAdmin($this->mandantB);

        MandantContext::set($this->mandantB);

        $this->actingAsApi($adminB)
            ->putJson('/api/admin/users/'.$target->id.'/roles', ['roles' => [['role' => 'user']]])
            ->assertStatus(404);

        $this->actingAsApi($adminB)
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertStatus(404);

        // The account keeps exactly the one assignment it was created with.
        $this->assertSame(1, RoleUser::query()->where('user_id', $target->id)->count());
        $this->assertHomeMandantInvariant($target->fresh());
    }

    /* ---------------------------------------------------------------------
     | Write site 3: the bootstrap admin
     | ------------------------------------------------------------------- */

    public function test_the_bootstrap_admin_is_a_global_account_with_a_global_role_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertNull($admin->mandant_id, 'The bootstrap admin must be a GLOBAL account.');
        $this->assertHomeMandantInvariant($admin);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * The invariant itself, asserted on a real row set.
     *
     * A mandant-scoped pivot must name the account's home mandant. The only
     * legal `NULL` pivot is the global `super_admin` one, and it carries no
     * team — on a GLOBAL account (the bootstrap admin, `DatabaseSeeder`) and on
     * a mandant-scoped account alike: a platform admin who additionally belongs
     * to one mandant is the hybrid shape the roles endpoint deliberately
     * preserves (`AdminUserTest::test_update_roles_never_touches_global_super_admin_assignment`)
     * and the delete endpoint deliberately accepts (it holds a mandant-scoped
     * assignment there).
     *
     * What the invariant forbids is the thing F5 is about: an account carrying a
     * mandant-scoped assignment in a mandant that is not its own.
     */
    private function assertHomeMandantInvariant(User $user): void
    {
        /** @var list<RoleUser> $assignments */
        $assignments = $user->roleUserAssignments()->with('role')->get()->all();

        $this->assertNotEmpty($assignments, 'PREMISE: the fixture has at least one role assignment.');

        foreach ($assignments as $assignment) {
            if ($assignment->mandant_id === null) {
                $this->assertSame(
                    UserRole::SUPER_ADMIN->value,
                    $assignment->role->slug,
                    "user {$user->id} carries a global pivot for a role other than super_admin.",
                );
                $this->assertNull($assignment->team_id, 'A global pivot must carry no team.');

                continue;
            }

            $this->assertSame(
                (int) $user->mandant_id,
                (int) $assignment->mandant_id,
                "user {$user->id} has an assignment in mandant {$assignment->mandant_id} "
                .'but belongs to mandant '.var_export($user->mandant_id, true).'.',
            );
        }
    }

    private function giveGlobalSuperAdmin(User $user): void
    {
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);
    }

    private function member(Mandant $mandant, string $email): User
    {
        $user = User::factory()->create([
            'mandant_id' => $mandant->id,
            'email' => $email,
        ]);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::USER->value)->firstOrFail()->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }

    private function mandantAdmin(Mandant $mandant): User
    {
        $admin = User::factory()->create([
            'mandant_id' => $mandant->id,
            'email' => 'admin-'.$mandant->slug.'@example.com',
        ]);

        RoleUser::create([
            'user_id' => $admin->id,
            'role_id' => Role::query()->where('slug', UserRole::MANDANT_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        return $admin;
    }
}

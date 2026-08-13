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
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Authorization layer (P1d): the role→permission matrix
 * (`config/permissions.php`) registered as gates in AuthServiceProvider.
 *
 * Covers 5 roles × gates × mandant contexts: own mandant, foreign mandant,
 * global (super_admin without mandant), plus team-scope and guest/no-role
 * denials.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        MandantContext::reset();
    }

    public static function accessMatrixProvider(): array
    {
        $allPermissions = [
            'mandants.manage',
            'teams.manage',
            'categories.manage',
            'events.manage',
            'accreditations.view',
            'accreditations.manage',
            'accreditations.self',
            'users.manage',
            'verification.verify',
        ];

        $mandantLevel = [
            'categories.manage',
            'events.manage',
            'users.manage',
            'accreditations.view',
            'accreditations.manage',
        ];

        return [
            'super_admin global (no mandant)' => [
                UserRole::SUPER_ADMIN->value,
                null,
                $allPermissions,
                [],
                'none',
            ],
            'super_admin on a foreign mandant' => [
                UserRole::SUPER_ADMIN->value,
                null,
                $allPermissions,
                [],
                'foreign',
            ],
            'mandant_admin within own mandant' => [
                UserRole::MANDANT_ADMIN->value,
                null,
                $mandantLevel,
                ['mandants.manage', 'teams.manage', 'accreditations.self', 'verification.verify'],
                'own',
            ],
            'mandant_admin on a foreign mandant' => [
                UserRole::MANDANT_ADMIN->value,
                null,
                [],
                $allPermissions,
                'foreign',
            ],
            'team_admin within own mandant and team' => [
                UserRole::TEAM_ADMIN->value,
                7,
                ['teams.manage', 'categories.manage', 'events.manage', 'accreditations.manage', 'accreditations.view'],
                ['mandants.manage', 'users.manage', 'accreditations.self', 'verification.verify'],
                'own',
            ],
            'team_admin on a foreign mandant' => [
                UserRole::TEAM_ADMIN->value,
                7,
                [],
                $allPermissions,
                'foreign',
            ],
            'user within own mandant' => [
                UserRole::USER->value,
                null,
                ['accreditations.self'],
                ['mandants.manage', 'teams.manage', 'categories.manage', 'events.manage', 'accreditations.view', 'accreditations.manage', 'users.manage', 'verification.verify'],
                'own',
            ],
            'user on a foreign mandant' => [
                UserRole::USER->value,
                null,
                [],
                $allPermissions,
                'foreign',
            ],
            'verifier within own mandant' => [
                UserRole::VERIFIER->value,
                null,
                ['verification.verify'],
                ['mandants.manage', 'teams.manage', 'categories.manage', 'events.manage', 'accreditations.view', 'accreditations.manage', 'accreditations.self', 'users.manage'],
                'own',
            ],
            'verifier on a foreign mandant' => [
                UserRole::VERIFIER->value,
                null,
                [],
                $allPermissions,
                'foreign',
            ],
        ];
    }

    /**
     * Parametrized access matrix: role × gate × mandant context.
     */
    #[DataProvider('accessMatrixProvider')]
    public function test_role_permission_matrix(string $roleSlug, ?int $teamId, array $allowed, array $denied, string $context): void
    {
        [$mandant, $foreignMandant] = $this->createMandants();
        $user = $roleSlug === UserRole::SUPER_ADMIN->value
            ? $this->createGlobalSuperAdmin()
            : $this->createUserWithRole($roleSlug, $mandant, $teamId);

        match ($context) {
            'own' => MandantContext::set($mandant),
            'foreign' => MandantContext::set($foreignMandant),
            default => MandantContext::reset(),
        };

        foreach ($allowed as $permission) {
            $this->assertTrue(
                Gate::forUser($user)->allows($permission),
                "[{$roleSlug}/{$context}] expected ALLOW: {$permission}",
            );
        }

        foreach ($denied as $permission) {
            $this->assertFalse(
                Gate::forUser($user)->allows($permission),
                "[{$roleSlug}/{$context}] expected DENY: {$permission}",
            );
        }
    }

    public function test_super_admin_bypasses_every_gate_without_mandant_context(): void
    {
        $admin = $this->createGlobalSuperAdmin();
        MandantContext::reset();

        $this->assertTrue(Gate::forUser($admin)->allows('mandants.manage'));
        $this->assertTrue(Gate::forUser($admin)->allows('teams.manage'));
        $this->assertTrue(Gate::forUser($admin)->allows('accreditations.view'));
    }

    public function test_team_admin_team_argument_scope(): void
    {
        [$mandant] = $this->createMandants();
        MandantContext::set($mandant);

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $mandant, 7);

        // Without an argument the gate falls back to the role's own team.
        $this->assertTrue(Gate::forUser($teamAdmin)->allows('events.manage'));
        $this->assertTrue(Gate::forUser($teamAdmin)->allows('teams.manage'));
        $this->assertTrue(Gate::forUser($teamAdmin)->allows('accreditations.manage'));

        // Explicit own team id → allow, foreign team id → deny.
        $this->assertTrue(Gate::forUser($teamAdmin)->allows('events.manage', 7));
        $this->assertFalse(Gate::forUser($teamAdmin)->allows('events.manage', 8));
        $this->assertFalse(Gate::forUser($teamAdmin)->allows('teams.manage', 999));

        // D7 (P2/P3): read-only view on the Verband's accreditations of the
        // team's persons — the gate semantics are nailed down here, the person
        // scope filtering follows with the real resources in P3.
        $this->assertTrue(Gate::forUser($teamAdmin)->allows('accreditations.view'));
        $this->assertFalse(Gate::forUser($teamAdmin)->allows('accreditations.view', 8));
    }

    public function test_team_admin_without_team_assignment_is_denied(): void
    {
        [$mandant] = $this->createMandants();
        MandantContext::set($mandant);

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $mandant, null);

        $this->assertFalse(Gate::forUser($teamAdmin)->allows('events.manage'));
        $this->assertFalse(Gate::forUser($teamAdmin)->allows('teams.manage'));
        $this->assertFalse(Gate::forUser($teamAdmin)->allows('accreditations.view'));
    }

    public function test_guest_is_denied_everything(): void
    {
        [$mandant] = $this->createMandants();
        MandantContext::set($mandant);

        foreach (['mandants.manage', 'teams.manage', 'events.manage', 'accreditations.view', 'accreditations.manage', 'accreditations.self', 'verification.verify'] as $permission) {
            $this->assertFalse(Gate::allows($permission), "guest should be denied: {$permission}");
        }
    }

    public function test_user_without_role_in_current_mandant_is_denied(): void
    {
        [$mandant, $foreignMandant] = $this->createMandants();
        MandantContext::set($mandant);

        // The user only holds a role in the *foreign* mandant.
        $user = $this->createUserWithRole(UserRole::USER->value, $foreignMandant);

        $this->assertFalse(Gate::forUser($user)->allows('accreditations.self'));
        $this->assertFalse(Gate::forUser($user)->allows('accreditations.view'));
    }

    public function test_has_permission_helper_is_consistent_with_gates(): void
    {
        [$mandant] = $this->createMandants();
        MandantContext::set($mandant);

        $mandantAdmin = $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $mandant);

        $this->assertTrue($mandantAdmin->hasPermission('events.manage'));
        $this->assertFalse($mandantAdmin->hasPermission('verification.verify'));
        $this->assertFalse($mandantAdmin->hasPermission('events.manage', $mandant->id + 999));

        $superAdmin = $this->createGlobalSuperAdmin();
        MandantContext::reset();

        $this->assertTrue($superAdmin->hasPermission('mandants.manage'));
    }

    /**
     * @return array{0: Mandant, 1: Mandant}
     */
    private function createMandants(): array
    {
        return [
            Mandant::factory()->create(['slug' => 'verband-a']),
            Mandant::factory()->create(['slug' => 'verband-b']),
        ];
    }

    private function createUserWithRole(string $roleSlug, Mandant $mandant, ?int $teamId = null): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandant->id,
            'team_id' => $teamId,
        ]);

        return $user;
    }

    private function createGlobalSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }
}

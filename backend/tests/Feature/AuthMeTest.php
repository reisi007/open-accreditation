<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserMedia;
use App\Support\MandantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_core_fields_roles_and_media(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAsApi($admin)
            ->getJson('/api/auth/me')
            ->assertOk();

        $response->assertJsonPath('data.id', $admin->id);
        $response->assertJsonPath('data.email', 'admin@example.com');
        $response->assertJsonPath('data.roles.0.slug', 'super_admin');
        $response->assertJsonPath('data.roles.0.mandant_id', null);
        $response->assertJsonPath('data.media', []);
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.activation_token');
    }

    public function test_me_returns_mandant_scoped_roles(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband']);

        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'verifier')->firstOrCreate(
            ['slug' => 'verifier'],
            ['name' => 'Verifier'],
        );

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        $this->actingAsApi($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0.slug', 'verifier')
            ->assertJsonPath('data.roles.0.mandant_id', $mandant->id);
    }

    public function test_me_exposes_current_mandant_id_from_host_resolution(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'verband']);
        MandantDomain::factory()->create([
            'mandant_id' => $mandant->id,
            'hostname' => 'verband.test',
        ]);

        // A real account always carries the mandant-scoped role row
        // (`AuthController::register`), and `EnsureMandantMembership` refuses
        // an authenticated account without one.
        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'user')->firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'User'],
        );

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        // MandantContext is set by MandantContextMiddleware in production;
        // here we simulate the resolved mandant for the request host.
        MandantContext::set($mandant);

        $this->actingAsApi($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $mandant->id);
    }

    public function test_me_exposes_null_current_mandant_id_without_context(): void
    {
        $user = User::factory()->create();

        MandantContext::reset();

        $this->actingAsApi($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', null);
    }

    /* ---------------------------------------------------------------------
     | M1 — the response must not carry another mandant's role assignments
     | ------------------------------------------------------------------- */

    /**
     * `/auth/me` used to eager-load `roles` unscoped, so the response listed
     * EVERY `role_user` row of the account — role slug, `mandant_id` AND
     * `team_id` — including the ones of mandants the request host does not
     * resolve to. On `a.test` a user who administrates verband B leaked that
     * fact (and B's id) to whoever held his token there.
     *
     * It is aggravated by `/auth/me` being one of the two
     * `EnsureMandantMembership` EXEMPT_ROUTES: the request it leaks on is
     * exactly the one that answers for an account WITHOUT a role in the
     * current mandant.
     *
     * FAILS WITHOUT THE FIX: two roles are serialized, the second one carries
     * `mandant_id` = the foreign mandant.
     */
    public function test_me_does_not_serialize_the_role_assignments_of_another_mandant(): void
    {
        $this->seed(RoleSeeder::class);

        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);
        MandantDomain::factory()->create(['mandant_id' => $mandantA->id, 'hostname' => 'a.test']);

        $user = User::factory()->forMandant($mandantA)->create();

        $this->assignRole($user, 'user', $mandantA->id);
        $this->assignRole($user, 'mandant_admin', $mandantB->id);

        $this->actingAsApi($user)
            ->getJson('http://a.test/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $mandantA->id)
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.slug', 'user')
            ->assertJsonPath('data.roles.0.mandant_id', $mandantA->id)
            ->assertJsonMissing(['slug' => 'mandant_admin'])
            ->assertJsonMissing(['mandant_id' => $mandantB->id]);
    }

    /**
     * The global `super_admin` row must SURVIVE the filter: its pivot carries
     * `mandant_id = null` and it is what the SPA's `isSuperAdmin` /
     * `useAdminTeams` logic keys on. Filtering on the current mandant alone
     * would hand a super_admin zero roles on every mandant domain and blind
     * exactly the accounts that need the widest view.
     *
     * The same holds for a super_admin who ALSO holds a role in a foreign
     * mandant: the foreign one goes, the global one stays.
     */
    public function test_me_keeps_the_global_super_admin_role_of_a_super_admin(): void
    {
        $this->seed(RoleSeeder::class);

        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $mandantB = Mandant::factory()->create(['slug' => 'verband-b']);
        MandantDomain::factory()->create(['mandant_id' => $mandantA->id, 'hostname' => 'a.test']);

        $admin = User::factory()->create();

        $this->assignRole($admin, 'super_admin', null);
        $this->assignRole($admin, 'user', $mandantB->id);

        $this->actingAsApi($admin)
            ->getJson('http://a.test/api/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.slug', 'super_admin')
            ->assertJsonPath('data.roles.0.mandant_id', null);
    }

    /**
     * `media` is NOT mandant-scoped, and it must not start being filtered by
     * accident: `user_media` has no `mandant_id` column at all, every row is
     * the caller's own upload, and the profile page lists them. This pins that
     * the caller's own media is still delivered on a mandant host.
     */
    public function test_me_still_delivers_the_callers_own_media_on_a_mandant_host(): void
    {
        $this->seed(RoleSeeder::class);

        $mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        MandantDomain::factory()->create(['mandant_id' => $mandantA->id, 'hostname' => 'a.test']);

        $user = User::factory()->forMandant($mandantA)->create();
        $this->assignRole($user, 'user', $mandantA->id);

        UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => 'user-media/verband-a/'.$user->id.'/portrait/portrait.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
        ]);

        $this->actingAsApi($user)
            ->getJson('http://a.test/api/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.type', 'portrait')
            // The private storage path is never part of the response.
            ->assertJsonMissing(['path' => 'user-media/verband-a/'.$user->id.'/portrait/portrait.jpg']);
    }

    private function assignRole(User $user, string $slug, ?int $mandantId): void
    {
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $slug)->firstOrFail()->id,
            'mandant_id' => $mandantId,
            'team_id' => null,
        ]);
    }
}

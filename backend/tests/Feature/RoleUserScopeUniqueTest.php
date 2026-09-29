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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WP-6-b: `role_user_scope_unique` on `(user_id, role_id, mandant_id, team_id)`
 * was inert for every role kind that exists.
 *
 * NULLs are distinct in a composite unique index on Postgres AND on SQLite,
 * and every real assignment has at least one NULL in that tuple:
 *
 *   | role          | mandant_id | team_id |
 *   |---------------|------------|---------|
 *   | super_admin   | NULL       | NULL    |
 *   | mandant_admin | set        | NULL    |
 *   | verifier      | set        | NULL    |
 *   | user          | set        | NULL    |
 *   | team_admin    | set        | set     |  ← the only protected shape
 *
 * So the constraint guarded nothing on the self-registration path
 * (`AuthController::register` writes `team_id => null`), a duplicate global
 * `super_admin` row inserted happily, and `AdminUserResource::rolesPayload`
 * then rendered the same role twice.
 *
 * The fix normalises the NULL scopes inside the index:
 * `unique (user_id, role_id, coalesce(mandant_id, 0), coalesce(team_id, 0))`.
 *
 * **Precedent, deliberately NOT copied:** `0001_01_01_000000_create_users_table.php:27-30`
 * documents the very same NULL-distinct situation for `users` — and there it
 * is intentional, because several global accounts must stay legal. The two
 * cases look alike and must be decided separately; this file is the test that
 * makes sure nobody "unifies" them later.
 */
class RoleUserScopeUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b']);
        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The index itself
     | ------------------------------------------------------------------- */

    public function test_the_coalesced_scope_index_replaces_the_inert_null_distinct_one(): void
    {
        $indexes = collect(Schema::getIndexes('role_user'))->keyBy('name');

        $this->assertTrue(
            $indexes->has('role_user_scope_unique_coalesced'),
            'expected the coalesced scope index, got: '.implode(', ', $indexes->keys()->all()),
        );
        $this->assertTrue(
            $indexes->get('role_user_scope_unique_coalesced')['unique'],
            'the coalesced scope index must be UNIQUE, otherwise it constrains nothing',
        );

        // The old index was a strict subset of the new one (same four columns,
        // NULL scopes folded together) — keeping it would only add write
        // amplification on a write-hot pivot.
        $this->assertFalse(
            $indexes->has('role_user_scope_unique'),
            'the NULL-distinct scope index is superseded and must be gone',
        );
    }

    /* ---------------------------------------------------------------------
     | NULL scopes — the shapes the old constraint could not see
     | ------------------------------------------------------------------- */

    public function test_a_duplicate_global_super_admin_row_is_rejected(): void
    {
        $user = User::factory()->create();
        $roleId = $this->roleId(UserRole::SUPER_ADMIN->value);

        RoleUser::create(['user_id' => $user->id, 'role_id' => $roleId, 'mandant_id' => null, 'team_id' => null]);

        $this->expectException(UniqueConstraintViolationException::class);

        RoleUser::create(['user_id' => $user->id, 'role_id' => $roleId, 'mandant_id' => null, 'team_id' => null]);
    }

    public function test_a_duplicate_registration_role_row_is_rejected(): void
    {
        // The exact shape `AuthController::register` writes: mandant-scoped
        // `user` role, `team_id => null`.
        $user = User::factory()->forMandant($this->mandantA)->create();
        $roleId = $this->roleId(UserRole::USER->value);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'mandant_id' => $this->mandantA->id,
            'team_id' => null,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'mandant_id' => $this->mandantA->id,
            'team_id' => null,
        ]);
    }

    public function test_the_registration_endpoint_never_leaves_a_second_role_user_row(): void
    {
        // The subject is the `role_user` row count, not the mail. Without this
        // the registration actually opens an SMTP connection to
        // `MAIL_HOST:MAIL_PORT` as pinned in `phpunit.xml` and a refused
        // connection surfaces as a 500 — i.e. the test's result depended on
        // whether a mail catcher happened to be listening. MEASURED: with
        // nothing on 127.0.0.1:1025 both this test and
        // `SameOriginGuardTest::test_register_from_a_non_browser_api_client_
        // without_origin_passes()` answer 500 instead of 201.
        Mail::fake();

        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);

        $payload = [
            'name' => 'Anna',
            'email' => 'anna@example.com',
            'password' => 'passwort123',
            'password_confirmation' => 'passwort123',
        ];

        $this->postJson('/api/auth/register', $payload)->assertCreated();
        $this->assertSame(1, RoleUser::query()->forMandant($this->mandantA->id)->count());

        // A second attempt for the same identity must be refused outright —
        // and must not append a second `user` assignment.
        $this->postJson('/api/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, RoleUser::query()->forMandant($this->mandantA->id)->count());
    }

    public function test_team_admin_with_a_team_is_still_database_protected(): void
    {
        // The one shape the old composite index already covered — it has to
        // survive the replacement.
        $user = User::factory()->forMandant($this->mandantA)->create();
        $roleId = $this->roleId(UserRole::TEAM_ADMIN->value);

        $attributes = [
            'user_id' => $user->id,
            'role_id' => $roleId,
            'mandant_id' => $this->mandantA->id,
            'team_id' => $this->teamA->id,
        ];

        RoleUser::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        RoleUser::create($attributes);
    }

    /* ---------------------------------------------------------------------
     | No over-blocking: the constraint stays scoped, not global
     | ------------------------------------------------------------------- */

    public function test_the_same_role_in_two_different_mandants_stays_legal(): void
    {
        $user = User::factory()->create();
        $roleId = $this->roleId(UserRole::USER->value);

        RoleUser::create(['user_id' => $user->id, 'role_id' => $roleId, 'mandant_id' => $this->mandantA->id, 'team_id' => null]);
        RoleUser::create(['user_id' => $user->id, 'role_id' => $roleId, 'mandant_id' => $this->mandantB->id, 'team_id' => null]);

        $this->assertSame(2, RoleUser::query()->where('user_id', $user->id)->count());
    }

    public function test_two_different_roles_in_the_same_scope_stay_legal(): void
    {
        $user = User::factory()->forMandant($this->mandantA)->create();

        RoleUser::create(['user_id' => $user->id, 'role_id' => $this->roleId(UserRole::USER->value), 'mandant_id' => $this->mandantA->id, 'team_id' => null]);
        RoleUser::create(['user_id' => $user->id, 'role_id' => $this->roleId(UserRole::VERIFIER->value), 'mandant_id' => $this->mandantA->id, 'team_id' => null]);
        RoleUser::create(['user_id' => $user->id, 'role_id' => $this->roleId(UserRole::MANDANT_ADMIN->value), 'mandant_id' => $this->mandantA->id, 'team_id' => null]);

        $this->assertSame(3, RoleUser::query()->where('user_id', $user->id)->count());
    }

    public function test_two_global_super_admins_on_different_users_stay_legal(): void
    {
        $roleId = $this->roleId(UserRole::SUPER_ADMIN->value);

        RoleUser::create(['user_id' => User::factory()->create()->id, 'role_id' => $roleId, 'mandant_id' => null, 'team_id' => null]);
        RoleUser::create(['user_id' => User::factory()->create()->id, 'role_id' => $roleId, 'mandant_id' => null, 'team_id' => null]);

        $this->assertSame(2, RoleUser::query()->forMandant(null)->count());
    }

    public function test_the_seeder_style_first_or_create_stays_idempotent(): void
    {
        $admin = User::factory()->create();
        $attributes = [
            'user_id' => $admin->id,
            'role_id' => $this->roleId(UserRole::SUPER_ADMIN->value),
            'mandant_id' => null,
            'team_id' => null,
        ];

        RoleUser::firstOrCreate($attributes);
        RoleUser::firstOrCreate($attributes);
        RoleUser::firstOrCreate($attributes);

        $this->assertSame(1, RoleUser::query()->where('user_id', $admin->id)->count());
    }

    /**
     * The contrasting, INTENTIONALLY NULL-distinct case: `users` must keep
     * allowing several global accounts that share one email. If this ever
     * fails, someone applied the `role_user` fix to the wrong table.
     */
    public function test_global_accounts_may_still_share_an_email_on_the_users_table(): void
    {
        User::factory()->create(['email' => 'chief@example.com', 'mandant_id' => null]);
        User::factory()->create(['email' => 'chief@example.com', 'mandant_id' => null]);

        $this->assertSame(2, User::query()->where('email', 'chief@example.com')->whereNull('mandant_id')->count());
    }

    private function roleId(string $slug): int
    {
        return Role::query()->where('slug', $slug)->valueOrFail('id');
    }
}

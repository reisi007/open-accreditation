<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Blacklist;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3e Blacklist CRUD — the admin-facing blacklist (Sperrliste) of the current
 * mandant.
 *
 * Guarded by `can:accreditations.manage` on the route level, but blacklists
 * are a mandant-level resource: only super_admin and mandant_admin may touch
 * them, a team_admin is rejected with 403 inside the controller. Entries are
 * mandant-scoped (foreign rows are 404), `(mandant_id, email)` /
 * `(mandant_id, domain)` unique constraints forbid duplicates, and input is
 * normalized to lowercase so the case-insensitive blacklist matching stays
 * consistent.
 */
class BlacklistTest extends TestCase
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

    public function test_blacklist_endpoints_require_authentication(): void
    {
        $entry = Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'x@example.com']);

        $this->getJson('/api/admin/blacklists')->assertStatus(401);
        $this->postJson('/api/admin/blacklists', ['email' => 'a@example.com'])->assertStatus(401);
        $this->deleteJson('/api/admin/blacklists/'.$entry->id)->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/blacklists')
                ->assertStatus(403, "expected 403 for {$role->value} on blacklists index");

            $this->actingAsApi($user)->postJson('/api/admin/blacklists', ['email' => 'a@example.com'])
                ->assertStatus(403, "expected 403 for {$role->value} on blacklists store");
        }
    }

    public function test_team_admin_is_forbidden_despite_accreditations_manage(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, null);

        $this->actingAsApi($teamAdmin)->getJson('/api/admin/blacklists')->assertStatus(403);
        $this->actingAsApi($teamAdmin)->postJson('/api/admin/blacklists', ['email' => 'a@example.com'])->assertStatus(403);
    }

    public function test_super_admin_can_store_email_domain_and_note(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', [
                'email' => 'banned@example.com',
                'domain' => 'blocked.org',
                'note' => 'Bewerteter User',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'banned@example.com')
            ->assertJsonPath('data.domain', 'blocked.org')
            ->assertJsonPath('data.note', 'Bewerteter User');

        $this->assertDatabaseHas('blacklists', [
            'mandant_id' => $this->mandantA->id,
            'email' => 'banned@example.com',
            'domain' => 'blocked.org',
        ]);
    }

    public function test_email_or_domain_alone_are_valid(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'only@example.com'])
            ->assertStatus(201)
            ->assertJsonPath('data.domain', null);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['domain' => 'only.org'])
            ->assertStatus(201)
            ->assertJsonPath('data.email', null);
    }

    public function test_input_is_normalized_to_lowercase(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => '  BANNED@EXAMPLE.com '])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'banned@example.com');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['domain' => 'BLOCKED.org'])
            ->assertStatus(201)
            ->assertJsonPath('data.domain', 'blocked.org');
    }

    public function test_at_least_one_of_email_or_domain_is_required(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['note' => 'keine Sperre'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'domain']);

        $this->assertDatabaseCount('blacklists', 0);
    }

    public function test_invalid_email_and_domain_formats_are_422(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        foreach (['http://example.com', 'example.com:8080', 'foo_bar.com', 'exa mple.com'] as $invalidDomain) {
            $this->actingAsApi($this->superAdmin())
                ->postJson('/api/admin/blacklists', ['domain' => $invalidDomain])
                ->assertStatus(422, "expected 422 for domain {$invalidDomain}")
                ->assertJsonValidationErrors('domain');
        }
    }

    public function test_duplicate_email_in_same_mandant_is_422_but_other_mandant_is_ok(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'shared@example.com'])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'shared@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'shared@example.com'])
            ->assertStatus(201);

        $this->assertSame(
            1,
            Blacklist::query()->where('mandant_id', $this->mandantA->id)->where('email', 'shared@example.com')->count(),
        );
    }

    public function test_duplicate_domain_in_same_mandant_is_422(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['domain' => 'blocked.org'])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['domain' => 'blocked.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    public function test_duplicate_email_in_different_case_is_422_not_500(): void
    {
        // A row written outside the controller (seed/direct insert) may carry
        // a mixed-case email. The duplicate pre-check must match it
        // case-insensitively — otherwise the insert would violate the DB
        // unique constraint (SQLSTATE 23505) instead of a clean 422.
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'X@EXAMPLE.com']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'x@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, Blacklist::query()->where('mandant_id', $this->mandantA->id)->count());

        // A distinct email is still accepted.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'other@example.com'])
            ->assertStatus(201);
    }

    public function test_duplicate_domain_in_different_case_is_422(): void
    {
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'BLOCKED.org']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['domain' => 'blocked.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertSame(1, Blacklist::query()->where('mandant_id', $this->mandantA->id)->count());
    }

    public function test_case_insensitive_email_and_domain_uniqueness_are_independent(): void
    {
        Blacklist::create([
            'mandant_id' => $this->mandantA->id,
            'email' => 'X@EXAMPLE.com',
            'domain' => 'BLOCKED.org',
        ]);

        // Same email in a different case → 422 on email even with a fresh domain.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'x@example.com', 'domain' => 'fresh.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Same domain in a different case → 422 on domain even with a fresh email.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'fresh@example.com', 'domain' => 'blocked.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertSame(1, Blacklist::query()->where('mandant_id', $this->mandantA->id)->count());
    }

    public function test_email_and_domain_uniqueness_are_independent_columns(): void
    {
        // The unique constraints are per column: `(mandant_id, email)` and
        // `(mandant_id, domain)`. The same email is a duplicate regardless of
        // the domain, and vice versa.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'a@example.com', 'domain' => 'example.com'])
            ->assertStatus(201);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'a@example.com', 'domain' => 'other.org'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'b@other.org', 'domain' => 'example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        // Fully distinct email AND domain → allowed.
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'b@other.org', 'domain' => 'distinct.org'])
            ->assertStatus(201);

        $this->assertSame(2, Blacklist::query()->where('mandant_id', $this->mandantA->id)->count());
    }

    public function test_database_unique_constraint_blocks_duplicate_email(): void
    {
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'dup@example.com']);

        $this->expectException(QueryException::class);

        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'dup@example.com']);
    }

    public function test_multiple_null_emails_with_distinct_domains_are_allowed(): void
    {
        // The unique (mandant_id, email) index treats NULLs as distinct —
        // portable across Postgres and SQLite.
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'a.org']);
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'b.org']);

        $this->assertSame(2, Blacklist::query()->where('mandant_id', $this->mandantA->id)->count());
    }

    public function test_index_lists_mandant_scoped_newest_first(): void
    {
        $first = Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'first@example.com']);
        $second = Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'second.org']);
        Blacklist::create(['mandant_id' => $this->mandantB->id, 'email' => 'foreign@example.com']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/blacklists')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.domain', 'second.org');
    }

    public function test_index_search_filters_by_email_domain_and_note(): void
    {
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'alice@example.com']);
        $domainRow = Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'blocked.org']);
        Blacklist::create(['mandant_id' => $this->mandantA->id, 'domain' => 'other.org', 'note' => 'Spam-Betrug']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/blacklists?search=example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'alice@example.com');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/blacklists?search=blocked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $domainRow->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/blacklists?search=Spam')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.note', 'Spam-Betrug');

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/blacklists?search=nomatch')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_super_admin_can_delete_own_mandant_entry(): void
    {
        $entry = Blacklist::create(['mandant_id' => $this->mandantA->id, 'email' => 'bye@example.com']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/blacklists/'.$entry->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('blacklists', ['id' => $entry->id]);
    }

    public function test_delete_of_foreign_mandant_entry_is_404(): void
    {
        $entry = Blacklist::create(['mandant_id' => $this->mandantB->id, 'email' => 'foreign@example.com']);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/blacklists/'.$entry->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('blacklists', ['id' => $entry->id]);
    }

    public function test_mandant_admin_can_manage_blacklists(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/blacklists', ['email' => 'ma@example.com'])
            ->assertStatus(201);

        $this->actingAsApi($this->mandantAdmin())
            ->getJson('/api/admin/blacklists')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $entry = Blacklist::query()->where('mandant_id', $this->mandantA->id)->firstOrFail();

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/blacklists/'.$entry->id)
            ->assertStatus(204);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function mandantAdmin(): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $this->mandantA->id);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => $mandantId,
            'team_id' => $teamId,
        ]);

        return $user;
    }
}

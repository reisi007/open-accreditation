<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2b Admin API — categories (Kategorien) of the current mandant.
 *
 * Two levels: mandant-level (`team_id` null) and team-level (`team_id` set).
 * super_admin / mandant_admin manage both levels; team_admin only his own
 * team's team-level categories (mandant-level is read-only). Slug uniqueness
 * is level-scoped and also enforced at the database layer (portable unique
 * indexes, see the migration).
 */
class AdminCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private Team $teamA;

    private Team $teamB;

    private Team $foreignTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        $this->teamA = $this->mandantA->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->teamB = $this->mandantA->teams()->create(['name' => 'Team B', 'slug' => 'team-b']);
        $this->foreignTeam = $this->mandantB->teams()->create(['name' => 'Fremd', 'slug' => 'fremd']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_category_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/categories')->assertStatus(401);
        $this->postJson('/api/admin/categories', [])->assertStatus(401);

        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->putJson('/api/admin/categories/'.$category->id, [])->assertStatus(401);
        $this->deleteJson('/api/admin/categories/'.$category->id)->assertStatus(401);
    }

    public function test_user_and_verifier_are_forbidden(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER] as $role) {
            $user = $this->createUserWithRole($role->value, $this->mandantA->id);

            $this->actingAsApi($user)->getJson('/api/admin/categories')
                ->assertStatus(403, "expected 403 for {$role->value} on categories index");

            $this->actingAsApi($user)->postJson('/api/admin/categories', [
                'name' => 'Hack',
                'slug' => 'hack',
            ])->assertStatus(403, "expected 403 for {$role->value} on categories store");
        }
    }

    public function test_super_admin_can_create_mandant_level_category(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Presse',
                'slug' => 'presse',
                'description' => 'Medienvertreter',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Presse')
            ->assertJsonPath('data.slug', 'presse')
            ->assertJsonPath('data.description', 'Medienvertreter')
            ->assertJsonPath('data.mandant_id', $this->mandantA->id)
            ->assertJsonPath('data.team_id', null)
            ->assertJsonPath('data.is_team_override', false)
            ->assertJsonPath('data.team', null);

        $this->assertDatabaseHas('categories', [
            'mandant_id' => $this->mandantA->id,
            'team_id' => null,
            'slug' => 'presse',
        ]);
    }

    public function test_mandant_admin_can_create_mandant_level_category(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Presse',
                'slug' => 'presse',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.is_team_override', false);
    }

    public function test_super_admin_can_create_team_level_category(): void
    {
        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Team Presse',
                'slug' => 'team-presse',
                'team_id' => $this->teamA->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->teamA->id)
            ->assertJsonPath('data.is_team_override', true)
            ->assertJsonPath('data.team.id', $this->teamA->id)
            ->assertJsonPath('data.team.name', 'Team A');
    }

    public function test_team_admin_can_create_category_for_own_team_with_foreign_team_id_forced(): void
    {
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->postJson('/api/admin/categories', [
                'name' => 'Team Presse',
                'slug' => 'team-presse',
                'team_id' => $this->teamB->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->teamA->id);

        $this->assertDatabaseHas('categories', ['slug' => 'team-presse', 'team_id' => $this->teamA->id]);
    }

    public function test_cannot_create_team_category_for_foreign_mandant_team(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Hack',
                'slug' => 'hack',
                'team_id' => $this->foreignTeam->id,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('categories', ['slug' => 'hack']);
    }

    public function test_super_admin_index_lists_mandant_and_team_level_categories(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Team A Presse', 'slug' => 'a-presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'Team B Presse', 'slug' => 'b-presse']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.is_team_override', true)
            ->assertJsonPath('data.1.team.id', $this->teamA->id)
            ->assertJsonPath('data.0.is_team_override', false)
            ->assertJsonPath('data.0.team', null);
    }

    public function test_index_with_team_id_filter_returns_effective_set_for_that_team(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'A Spezial', 'slug' => 'a-spezial']);
        $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'B Spezial', 'slug' => 'b-spezial']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/categories?team_id='.$this->teamA->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'a-spezial')
            ->assertJsonPath('data.1.slug', 'presse');
    }

    public function test_index_with_foreign_team_id_is_404(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/categories?team_id='.$this->foreignTeam->id)
            ->assertStatus(404);
    }

    public function test_team_admin_index_shows_own_team_and_mandant_level_categories_only(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Eigene', 'slug' => 'eigene']);
        $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'Fremde', 'slug' => 'fremde']);

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Eigene')
            ->assertJsonPath('data.1.name', 'Presse');
    }

    public function test_super_admin_can_update_category_partially(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Alt', 'slug' => 'alt']);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/categories/'.$category->id, [
                'name' => 'Neu',
                'description' => 'Beschreibung',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu')
            ->assertJsonPath('data.description', 'Beschreibung')
            ->assertJsonPath('data.slug', 'alt')
            ->assertJsonPath('data.is_team_override', false);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Neu']);
    }

    public function test_mandant_admin_can_update_team_category_of_own_mandant(): void
    {
        $category = $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Alt', 'slug' => 'alt']);

        $this->actingAsApi($this->mandantAdmin())
            ->putJson('/api/admin/categories/'.$category->id, ['name' => 'Neu'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu')
            ->assertJsonPath('data.team_id', $this->teamA->id);
    }

    public function test_team_admin_can_update_and_delete_own_team_category(): void
    {
        $category = $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Eigene', 'slug' => 'eigene']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/categories/'.$category->id, ['name' => 'Neu'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neu');

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/categories/'.$category->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_team_admin_cannot_update_or_delete_mandant_level_category(): void
    {
        $category = $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/categories/'.$category->id, ['name' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/categories/'.$category->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Presse']);
    }

    public function test_team_admin_cannot_touch_category_of_another_team(): void
    {
        $category = $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'Fremde', 'slug' => 'fremde']);
        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id, $this->teamA->id);

        $this->actingAsApi($teamAdmin)
            ->putJson('/api/admin/categories/'.$category->id, ['name' => 'Hack'])
            ->assertStatus(403);

        $this->actingAsApi($teamAdmin)
            ->deleteJson('/api/admin/categories/'.$category->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Fremde']);
    }

    public function test_category_of_foreign_mandant_is_not_reachable(): void
    {
        $category = $this->mandantB->categories()->create(['name' => 'Fremd', 'slug' => 'fremd']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/categories/'.$category->id, ['name' => 'Hack'])
            ->assertStatus(404);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/categories/'.$category->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Fremd']);
    }

    public function test_same_slug_is_allowed_at_mandant_and_team_level(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Team Presse',
                'slug' => 'presse',
                'team_id' => $this->teamA->id,
            ])
            ->assertStatus(201);
    }

    public function test_duplicate_mandant_level_slug_is_rejected(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Presse 2',
                'slug' => 'presse',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_duplicate_team_level_slug_in_same_team_is_rejected(): void
    {
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Presse 2',
                'slug' => 'presse',
                'team_id' => $this->teamA->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_same_slug_is_allowed_in_two_teams(): void
    {
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse A', 'slug' => 'presse']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Presse B',
                'slug' => 'presse',
                'team_id' => $this->teamB->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('categories', ['slug' => 'presse', 'team_id' => $this->teamA->id]);
        $this->assertDatabaseHas('categories', ['slug' => 'presse', 'team_id' => $this->teamB->id]);
    }

    public function test_same_slug_is_allowed_for_another_mandant(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'Presse B',
                'slug' => 'presse',
            ])->assertStatus(201);

        $this->assertDatabaseHas('categories', ['slug' => 'presse', 'mandant_id' => $this->mandantA->id]);
        $this->assertDatabaseHas('categories', ['slug' => 'presse', 'mandant_id' => $this->mandantB->id]);
    }

    public function test_db_rejects_duplicate_mandant_level_slug(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->expectException(QueryException::class);

        $this->mandantA->categories()->create(['name' => 'Presse 2', 'slug' => 'presse']);
    }

    public function test_db_rejects_duplicate_team_level_slug_in_same_team(): void
    {
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse', 'slug' => 'presse']);

        $this->expectException(QueryException::class);

        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse 2', 'slug' => 'presse']);
    }

    public function test_db_allows_mandant_level_and_team_level_override(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Override', 'slug' => 'presse']);

        $this->assertSame(2, Category::query()->where('slug', 'presse')->count());
    }

    public function test_db_allows_same_slug_in_two_teams(): void
    {
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse A', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'Presse B', 'slug' => 'presse']);

        $this->assertSame(2, Category::query()->where('slug', 'presse')->count());
    }

    public function test_mandant_slug_generated_column_null_for_team_level_rows(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Team Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamB->id, 'name' => 'B Presse', 'slug' => 'presse']);

        $this->assertDatabaseHas('categories', ['mandant_id' => $this->mandantA->id, 'team_id' => null, 'mandant_slug' => 'presse']);
        $this->assertSame(1, Category::query()->where('team_id', $this->teamA->id)->whereNull('mandant_slug')->count());
        $this->assertSame(1, Category::query()->where('team_id', $this->teamB->id)->whereNull('mandant_slug')->count());
    }

    public function test_effective_categories_apply_override_precedence(): void
    {
        $this->mandantA->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['name' => 'Akkreditierung', 'slug' => 'akkreditierung']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Presse Override', 'slug' => 'presse']);
        $this->mandantA->categories()->create(['team_id' => $this->teamA->id, 'name' => 'Funktion', 'slug' => 'funktion']);

        // For team A the team-level "presse" wins; the mandant-level "presse"
        // is dropped, mandant-level "akkreditierung" stays.
        $effective = Category::effectiveForTeam($this->teamA->id);

        $this->assertSame(
            ['akkreditierung', 'funktion', 'presse'],
            $effective->sortBy('name')->pluck('slug')->values()->all(),
        );
        $this->assertSame('Presse Override', $effective->firstWhere('slug', 'presse')->name);

        // Team B has no override → the mandant-level "presse" applies.
        $effectiveB = Category::effectiveForTeam($this->teamB->id);

        $this->assertSame(
            ['akkreditierung', 'presse'],
            $effectiveB->sortBy('name')->pluck('slug')->values()->all(),
        );
    }

    public function test_category_validation(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/categories', [
                'name' => 'X',
                'slug' => 'Ungültig',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
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

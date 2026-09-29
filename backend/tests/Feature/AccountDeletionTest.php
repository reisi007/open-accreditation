<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Team;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\AccountDeletionService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithJwtRevocation;
use Tests\TestCase;

/**
 * Konto-Löschung (DSGVO) — hard deletion, self-service and admin.
 *
 * The user decision is "wirklich löschen", so nothing here anonymises: the row
 * and everything referencing it go. The two things this file treats as
 * CONTRACTS rather than as implementation details are:
 *
 *  1. **The count has to exist before the delete.** A confirmation dialog must
 *     name the account and how many applications go with it, and a count read
 *     after the delete is always zero. So `GET /api/user/account` carries it,
 *     the admin listing carries it per row (`withCount`), and the delete
 *     response repeats the counts measured inside the transaction.
 *  2. **The revocation must not go through the JWT blacklist** (Weg B,
 *     accepted risk A6). Weg A — the missing `users` row — is what
 *     `AccountDeletionRevokesAccessImmediatelyTest` pins, and
 *     `test_the_deletion_does_not_go_through_the_jwt_blacklist` below pins it
 *     for THIS route: the account is deleted through the service, no blacklist
 *     entry is written, and the token is rejected anyway.
 *  3. **The admin route names the ACTOR** (accepted risk A5). The application
 *     log is the only place "who deleted which account" can be answered, and
 *     that needs two halves: the admin path below asserts an actor that is NOT
 *     the target, the self-service path asserts `actor_is_target`.
 *
 * The target scope of `DELETE /api/admin/users/{user}` is F1 (2026-09-29): the
 * route binding resolves a global `super_admin` on every mandant's host, so
 * the route carries the same explicit membership check as the roles endpoint.
 */
class AccountDeletionTest extends TestCase
{
    use InteractsWithJwtRevocation;
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);

        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);
        $this->mandantB->domains()->create(['hostname' => 'verband-b.test']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The count must exist BEFORE the delete
     | ------------------------------------------------------------------- */

    public function test_the_account_endpoint_answers_the_identity_and_the_application_count(): void
    {
        $user = $this->member($this->mandantA);
        $this->withApplications($user, $this->mandantA, 2);

        $this->actingAsApi($user)
            ->getJson('/api/user/account')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.mandant_id', $this->mandantA->id)
            ->assertJsonPath('data.mandant_name', 'Verband A')
            // The number the confirmation dialog names.
            ->assertJsonPath('data.applications_count', 2)
            ->assertJsonPath('data.sub_applications_count', 0)
            ->assertJsonPath('data.media_count', 0);
    }

    public function test_the_admin_listing_carries_the_application_count_per_user(): void
    {
        $listed = $this->member($this->mandantA, 'listed@example.com');
        $other = $this->member($this->mandantA, 'other@example.com');

        $this->withApplications($listed, $this->mandantA, 3);
        $this->withApplications($other, $this->mandantA, 1);

        // The listing is ordered by NAME, not by email, and the factory's names say
        // nothing about the addresses — so the counts are looked up BY email
        // instead of by index. A positional assertion here would be a claim
        // about the factory's name generator, not about the count.
        $rows = collect($this->actingAsApi($this->mandantAdmin())->getJson('/api/admin/users')->assertOk()->json('data'))
            ->keyBy('email');

        // Without the count the admin confirmation dialog would have to guess,
        // or fetch the account of every row separately.
        $this->assertArrayHasKey('applications_count', $rows['listed@example.com']);
        $this->assertArrayHasKey('sub_applications_count', $rows['listed@example.com']);
        $this->assertSame(3, $rows['listed@example.com']['applications_count']);
        $this->assertSame(1, $rows['other@example.com']['applications_count']);
        $this->assertSame(0, $rows['listed@example.com']['sub_applications_count']);
    }

    /* ---------------------------------------------------------------------
     | Self-service
     | ------------------------------------------------------------------- */

    public function test_a_user_deletes_their_own_account(): void
    {
        $user = $this->member($this->mandantA);
        $this->withApplications($user, $this->mandantA, 2);
        $this->seedSessions($user, 2);

        $this->actingAsApi($user)
            ->deleteJson('/api/user/account')
            ->assertOk()
            ->assertJsonPath('data.applications_deleted', 2)
            ->assertJsonPath('data.sessions_deleted', 2)
            ->assertJsonPath('data.media_files_left_over', []);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        // The cascade is the schema's, but it is the promise: no orphans.
        $this->assertSame(0, Application::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, RoleUser::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_the_self_service_route_requires_authentication(): void
    {
        $this->deleteJson('/api/user/account')->assertStatus(401);
        $this->getJson('/api/user/account')->assertStatus(401);
    }

    /**
     * The self-service route carries NO gate, so the role matrix cannot deny it.
     * This test is the consequence: every role that may hold an account can
     * delete it, including the plain `user` — which is the whole point of the
     * DSGVO self-service right.
     */
    public function test_self_service_needs_no_role_the_user_role_alone_is_enough(): void
    {
        foreach ([UserRole::USER, UserRole::VERIFIER, UserRole::TEAM_ADMIN, UserRole::MANDANT_ADMIN] as $role) {
            $user = $this->createUserWithRole($role, $this->mandantA);

            $this->actingAsApi($user)->deleteJson('/api/user/account')->assertOk();

            // (`assertDatabaseMissing()`'s third argument is a CONNECTION, not a message —
            // a message there is read as a database name and fails with "Database
            // connection [...] not configured".)
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
    }

    /**
     * The cascade removes the ROW, not the FILE. `user_media.user_id` cascades,
     * so the row is gone whether or not the service unlinks anything — which is
     * exactly the trap: a deleted account that silently kept its portrait on the
     * disk would leave personal data behind after a "hard deletion".
     */
    public function test_the_deletion_removes_the_private_media_files_and_their_rows(): void
    {
        $user = $this->member($this->mandantA);
        $path = $this->seedMedia($user, 'portrait');

        $mediaId = UserMedia::query()->where('path', $path)->value('id');

        $this->actingAsApi($user)
            ->deleteJson('/api/user/account')
            ->assertOk()
            ->assertJsonPath('data.media_files_deleted', 1);

        $this->assertDatabaseMissing('user_media', ['id' => $mediaId]);
        Storage::disk('private')->assertMissing($path);
    }

    /**
     * A repeated delete of an already-cleared file is a success, not a 500 — the
     * account is gone either way, and an unremovable file must not be able to
     * keep the response in the "failed" state.
     */
    public function test_a_media_row_whose_file_is_already_gone_does_not_break_the_deletion(): void
    {
        $user = $this->member($this->mandantA);
        $path = $this->seedMedia($user, 'portrait');

        Storage::disk('private')->delete($path);
        $this->assertFalse(Storage::disk('private')->exists($path), 'PREMISE: the file is really gone.');

        $this->actingAsApi($user)
            ->deleteJson('/api/user/account')
            ->assertOk()
            ->assertJsonPath('data.media_files_left_over', []);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * The cookie is cleared on the way out.
     *
     * NOT what revokes the access — the account is already gone from the
     * database, so the next request with that token answers 401 whatever the
     * cookie does (see the blacklist test). Clearing it only stops the browser
     * from sending a token that can never succeed again.
     */
    public function test_the_deletion_clears_the_jwt_cookie(): void
    {
        $user = $this->member($this->mandantA);

        $response = $this->actingAsApi($user)->deleteJson('/api/user/account');

        $response->assertOk();

        $cookie = collect($response->headers->getCookies())->first(
            fn ($cookie) => $cookie->getName() === config('jwt.cookie_key_name'),
        );

        $this->assertNotNull($cookie, 'PREMISE: the response must carry the forget-cookie for accr_jwt.');
        // `CookieJar::forget()` is `make($name, null, -2628000)` — a NULL value
        // and an expiry ~30 days in the PAST. The expiry is the part that makes
        // a browser drop it, so it is what is asserted; the null value alone
        // would also be satisfied by a cookie that merely has no value.
        $this->assertNull($cookie->getValue());
        $this->assertLessThan(
            time(),
            $cookie->getExpiresTime(),
            'PREMISE: a forget-cookie must expire in the past, otherwise the browser keeps it.',
        );
    }

    /* ---------------------------------------------------------------------
     | Admin route
     | ------------------------------------------------------------------- */

    public function test_a_mandant_admin_deletes_a_user_of_his_mandant(): void
    {
        $target = $this->member($this->mandantA, 'target@example.com');
        $this->withApplications($target, $this->mandantA, 1);

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertOk()
            ->assertJsonPath('data.applications_deleted', 1);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_the_admin_delete_route_requires_authentication(): void
    {
        $target = $this->member($this->mandantA);

        $this->deleteJson('/api/admin/users/'.$target->id)->assertStatus(401);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    /**
     * `users.delete` is mandant_admin-only. `team_admin`, `user` and `verifier`
     * hold no delete permission at all.
     *
     * The target is a resolvable member of the current mandant on purpose: the
     * mandant-scoped route binding runs FIRST, so with a resolvable target a
     * 403 can only come from the gate — which is what this is about. (A foreign
     * or unknown id would answer 404 before the gate is consulted.)
     */
    public function test_team_admin_user_and_verifier_are_forbidden_on_the_admin_delete_route(): void
    {
        foreach ([UserRole::TEAM_ADMIN, UserRole::USER, UserRole::VERIFIER] as $role) {
            $actor = $this->createUserWithRole($role, $this->mandantA);
            // Emails are unique per mandant (BE-R1), so the target's address is
            // derived from the role rather than reused across the loop.
            $target = $this->member($this->mandantA, 'target-'.$role->value.'@example.com');

            $this->actingAsApi($actor)
                ->deleteJson('/api/admin/users/'.$target->id)
                ->assertStatus(403, "expected 403 for {$role->value}");

            $this->assertDatabaseHas('users', ['id' => $target->id]);
        }
    }

    /**
     * Cross-mandant denial for the mandant_admin: the target is a member of
     * mandant B, the current context is mandant A. The mandant-scoped
     * `User::resolveRouteBindingQuery()` answers 404 — the same discriminator
     * `PUT /users/{user}/roles` uses, deliberately: 403 would confirm the id
     * exists somewhere.
     */
    public function test_a_mandant_admin_may_not_delete_a_user_of_another_mandant(): void
    {
        $target = $this->member($this->mandantB, 'fremd@example.com');

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    /**
     * A global super_admin may delete users of another mandant — but he reaches
     * them through THAT mandant's host, exactly like every other permission he
     * holds mandant-independently. The gate is not what scopes him here: the
     * target scope is, and it is the same scoping the whole admin surface has
     * (D21, host-relative with a mandant switcher).
     */
    public function test_a_super_admin_deletes_a_user_of_another_mandant_on_that_mandants_host(): void
    {
        $target = $this->member($this->mandantB, 'verband-b-user@example.com');

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertStatus(404, 'PREMISE: from mandant A\'s context a mandant-B user is not resolvable.');

        MandantContext::set($this->mandantB);

        $this->actingAsApi($this->superAdmin())
            ->deleteJson('/api/admin/users/'.$target->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_deleting_an_unknown_user_is_a_404(): void
    {
        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/users/999999')
            ->assertStatus(404);
    }

    /* ---------------------------------------------------------------------
     | F1: the route binding is NOT a membership check
     | ------------------------------------------------------------------- */

    /**
     * `User::resolveRouteBindingQuery()` reuses the IDENTITY predicate
     * `isMemberOfMandant()`, and that predicate deliberately ORs in the GLOBAL
     * `super_admin` branch: an account with a `role_user` row carrying
     * `mandant_id = null` resolves on EVERY mandant's host.
     *
     * That is right for "may this account act here at all?" and wrong as the
     * whole answer to "is this target one of my users?". The platform-wide
     * super_admin holds no mandant-scoped assignment, appears in NO mandant's
     * user list (`index()` filters by `forMandant()`) and can never have a
     * scoped role set written for it. Measured before the fix: `PUT .../roles`
     * answered 404 for this target while `DELETE` answered 200 and took the
     * row - irreversible, cascading, and blind (sequential integer ids,
     * invisible in the listing).
     *
     * The mirror of
     * `AdminUserTest::test_update_roles_rejects_global_super_admin_without_mandant_assignment`:
     * the roles endpoint already carried the check, the delete endpoint did
     * not, and the whole 1629-test suite stayed green without it.
     */
    public function test_a_mandant_admin_may_not_delete_a_global_super_admin(): void
    {
        $global = $this->createGlobalSuperAdmin();

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/users/'.$global->id)
            ->assertStatus(404);

        // Nothing went: not the account, not his global role row.
        $this->assertDatabaseHas('users', ['id' => $global->id, 'email' => 'root@example.com']);
        $this->assertDatabaseHas('role_user', ['user_id' => $global->id, 'mandant_id' => null]);
    }

    /**
     * The other direction, because a guard that is too broad is its own defect:
     * a target that DOES hold a mandant-scoped assignment here stays deletable
     * even if it additionally carries the global `super_admin` pivot. The check
     * is membership in the current mandant, not "has no super_admin row".
     *
     * (Mirrors `AdminUserTest::test_update_roles_never_touches_global_super_admin_assignment`.)
     */
    public function test_a_super_admin_who_also_belongs_to_the_current_mandant_can_still_be_deleted(): void
    {
        $hybrid = $this->member($this->mandantA, 'hybrid@example.com');

        RoleUser::create([
            'user_id' => $hybrid->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('/api/admin/users/'.$hybrid->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $hybrid->id]);
    }

    /* ---------------------------------------------------------------------
     | Cross-origin: an irreversible DELETE is the worst case there is
     | ------------------------------------------------------------------- */

    /**
     * `EnsureSameOrigin` sits on the whole `api` group and rejects a
     * state-changing request whose `Origin` is not the mandant's own origin —
     * a CSRF defence for the httpOnly-cookie auth.
     *
     * An account deletion is irreversible, so this is asserted explicitly for
     * both routes rather than left to the generic coverage of the guard: if the
     * route ever moved out of the `api` group (or the guard were exempted for
     * "self-service"), the account would be gone by a request a foreign page
     * could make the browser send.
     */
    public function test_a_cross_origin_deletion_is_rejected_on_both_routes(): void
    {
        $this->simulateAProductionHttpRequest();

        $actor = $this->member($this->mandantA, 'opfer-selbst@example.com');
        $target = $this->member($this->mandantA, 'opfer@example.com');

        // A sibling tenant's origin — same scheme, different host. This is the
        // shape a hostile page on another mandant's domain produces.
        $this->actingAsApi($actor)
            ->deleteJson('https://verband-a.test/api/user/account', [], ['Origin' => 'https://verband-b.test'])
            ->assertForbidden();

        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson(
                'https://verband-a.test/api/admin/users/'.$target->id,
                [],
                ['Origin' => 'https://verband-b.test'],
            )
            ->assertForbidden();

        // Neither deletion happened: the guard rejects BEFORE the controller.
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);

        // The premise of the two 403s above: only the CROSS-origin variant is
        // blocked. Without this, a guard that rejected every request would
        // satisfy the test.
        $this->actingAsApi($this->mandantAdmin())
            ->deleteJson('https://verband-a.test/api/admin/users/'.$target->id, [], ['Origin' => 'https://verband-a.test'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    /* ---------------------------------------------------------------------
     | The concurrent-delete branch (a STATE test, not a race test)
     | ------------------------------------------------------------------- */

    /**
     * `AccountDeletionService::delete()` answers `deleted => false` (no log,
     * zeroed counts) when the row is already gone: the winner of a real race
     * ran the same cascade, so there is nothing left for this request to do and
     * nothing this request may claim as deleted by ITS actor.
     *
     * **This is a state test, not a concurrency test.** SQLite `:memory:`
     * cannot produce a real race: a second writer simply finds no row, and
     * `lockForUpdate` on a single-process SQLite is advisory at best. What is
     * pinned here is the branch's CONTRACT for the state a race would produce
     * (row absent); hosting that state needs two writers, which this suite
     * deliberately cannot provide.
     *
     * **And it cannot be reached through HTTP at all** — measured while writing
     * this: `DELETE /api/admin/users/{id}` on a removed row answers 404 from the
     * route binding (`User::resolveRouteBindingQuery()` finds nothing), and the
     * self-service route answers 401 because `JWTGuard::user()` cannot resolve
     * the subject any more (Weg A, A6). The branch therefore covers exactly the
     * window the binding cannot see: the target was resolved, and the row
     * disappeared between that resolution and the locked re-read inside the
     * transaction. Which is why the test calls the SERVICE with a stale model
     * instance instead of pretending a route can reach it.
     *
     * Two passes over the same instance: the first deletes, the second finds
     * the row gone.
     */
    public function test_a_second_delete_of_the_same_row_is_a_no_op_and_logs_nothing(): void
    {
        $target = $this->member($this->mandantA, 'bereits-weg@example.com');
        $this->withApplications($target, $this->mandantA, 1);
        $this->seedSessions($target, 1);

        $targetId = $target->id;
        $actor = $this->mandantAdmin();

        $deletions = app(AccountDeletionService::class);

        Log::spy();

        $first = $deletions->delete($target, 'admin', $actor);

        $this->assertTrue($first['deleted'], 'PREMISE: the first pass deletes.');
        $this->assertSame($targetId, $first['user_id']);
        $this->assertSame(1, $first['counts']['sessions']);

        $second = $deletions->delete($target, 'admin', $actor);

        $this->assertFalse($second['deleted']);
        $this->assertSame($targetId, $second['user_id'], 'The target id is still reported, so a caller can log it.');
        $this->assertNull($second['email'], 'Nothing may be read from a row that is gone.');
        $this->assertNull($second['mandant_id']);
        $this->assertSame(
            ['applications' => 0, 'sub_applications' => 0, 'media' => 0, 'role_assignments' => 0, 'sessions' => 0],
            $second['counts'],
        );
        $this->assertSame([], $second['residue']);

        // Exactly ONE record: the first pass may claim the deletion, the second
        // must not add a second "this actor deleted it" line for a row that was
        // already gone.
        Log::shouldHaveReceived('notice')->once();
    }

    /* ---------------------------------------------------------------------
     | The sessions gap — the only column WITHOUT a cascade
     | ------------------------------------------------------------------- */

    /**
     * `sessions.user_id` is `nullable()->index()` with no foreign key, so the
     * row delete alone would leave the session rows behind as orphans pointing
     * at a user id that no longer exists. They are deleted explicitly; this is
     * the test that fails if that ever stops happening.
     */
    public function test_the_session_rows_of_a_deleted_account_are_gone(): void
    {
        $user = $this->member($this->mandantA);
        $other = $this->member($this->mandantA, 'other@example.com');

        $this->seedSessions($user, 3);
        $this->seedSessions($other, 1);

        $this->actingAsApi($user)->deleteJson('/api/user/account')->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        // The blanket delete must not touch anyone else's sessions.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    /* ---------------------------------------------------------------------
     | The revocation must rest on the missing row, not on the blacklist
     | ------------------------------------------------------------------- */

    /**
     * The route-level half of the Weg A / Weg B contract.
     *
     * `AccountDeletionRevokesAccessImmediatelyTest` pins the CONSEQUENCE with a
     * direct `User::query()->delete()`. This pins the ROUTE: the service runs,
     * NO blacklist entry is written, the account is nevertheless rejected on
     * the next request — and stays rejected after the whole cache is flushed,
     * which is the one thing Weg B could never survive.
     */
    public function test_the_deletion_does_not_go_through_the_jwt_blacklist(): void
    {
        $user = $this->member($this->mandantA);
        $token = $this->authenticateOverTheCookie($user);

        $this->assertSame(200, $this->callProtectedRoute(), 'PREMISE: the token must be valid before the deletion.');

        $this->callProtectedRoute($token);
        $this->deleteJson('/api/user/account')->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        // Weg A rests on THIS row — without it the 401 below could be an
        // expiry, a broken harness, or the blacklist.
        $this->assertFalse(User::query()->whereKey($user->id)->exists());

        $this->assertFalse(
            $this->blacklistHolds($token),
            'The deletion must not write a JWT blacklist entry — that revocation is undone by one cache:clear and swallows its own failure (A6).',
        );

        $this->assertSame(
            401,
            $this->callProtectedRoute($token),
            'A deleted account must be rejected on the very next request.',
        );

        // Weg A against the one thing that kills Weg B.
        $this->useProductionCacheStore();
        Cache::flush();

        $this->assertFalse($this->blacklistHolds($token), 'PREMISE: the cache is empty.');
        $this->assertGreaterThan(
            now()->getTimestamp(),
            $this->expiryOf($token),
            'PREMISE: the token is unexpired, so a rejection cannot be an expiry.',
        );

        $this->assertSame(
            401,
            $this->callProtectedRoute($token),
            'The rejection survives a full cache flush — that is Weg A, independent of Weg B.',
        );
    }

    /* ---------------------------------------------------------------------
     | The mandatory log record (accepted risk A5)
     | ------------------------------------------------------------------- */

    /**
     * There is no `audit_logs` table. The application log is the only place the
     * question "who deleted which account" can ever be answered, so the payload
     * is pinned: ACTOR (id + email) and target (id, email, mandant), plus the
     * counts of what went with the account.
     *
     * The actor here is the mandant_admin, a DIFFERENT account from the target.
     * That is the whole point of the assertion: a payload check that only ever
     * saw `actor == target` would also be satisfied by a service that quietly
     * filled the actor fields from the target - the defect this pins (F3, the
     * signature took no actor at all) would sail through.
     */
    public function test_the_deletion_is_logged_with_the_full_payload(): void
    {
        $target = $this->member($this->mandantA, 'weg@example.com');
        $this->withApplications($target, $this->mandantA, 2);
        $this->seedSessions($target, 1);

        // Captured BEFORE the delete: afterwards the row is gone and every
        // assertion about what went with it would be about nothing.
        $targetId = $target->id;
        $mandantId = $this->mandantA->id;
        $actor = $this->mandantAdmin();

        Log::spy();

        $this->actingAsApi($actor)
            ->deleteJson('/api/admin/users/'.$targetId)
            ->assertOk();

        Log::shouldHaveReceived('notice')->withArgs(
            function (string $message, array $context) use ($targetId, $mandantId, $actor): bool {
                $this->assertSame('An account was deleted.', $message);

                // The premise: an admin deletion has a THIRD party in it.
                $this->assertNotSame($actor->id, $targetId, 'PREMISE: the actor must differ from the target.');

                return $context['reason'] === 'admin'
                    && $context['actor_user_id'] === $actor->id
                    && $context['actor_user_email'] === $actor->email
                    && $context['actor_is_target'] === false
                    && $context['deleted_user_id'] === $targetId
                    && $context['deleted_user_email'] === 'weg@example.com'
                    && $context['deleted_user_mandant_id'] === $mandantId
                    && $context['applications_deleted'] === 2
                    && $context['role_assignments_deleted'] === 1
                    && $context['sessions_deleted'] === 1
                    && $context['media_rows_deleted'] === 0
                    && $context['media_files_left_over'] === [];
            },
        );
    }

    /**
     * Self-service: the actor IS the target, and the payload says so instead of
     * making a log reader compare two ids to find that out.
     */
    public function test_the_self_service_deletion_is_logged_as_such(): void
    {
        $target = $this->member($this->mandantA);

        $targetId = $target->id;
        $email = $target->email;

        Log::spy();

        $this->actingAsApi($target)->deleteJson('/api/user/account')->assertOk();

        Log::shouldHaveReceived('notice')->withArgs(
            function (string $message, array $context) use ($targetId, $email): bool {
                $this->assertSame('An account was deleted.', $message);

                return $context['reason'] === 'self_service'
                    && $context['actor_user_id'] === $targetId
                    && $context['actor_user_email'] === $email
                    && $context['actor_is_target'] === true
                    && $context['deleted_user_id'] === $targetId;
            },
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function member(Mandant $mandant, string $email = 'member@example.com'): User
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

    private function createUserWithRole(UserRole $role, Mandant $mandant): User
    {
        $user = User::factory()->create(['mandant_id' => $mandant->id]);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'mandant_id' => $role === UserRole::SUPER_ADMIN ? null : $mandant->id,
            'team_id' => $role === UserRole::TEAM_ADMIN
                ? Team::factory()->create(['mandant_id' => $mandant->id])->id
                : null,
        ]);

        return $user;
    }

    private function mandantAdmin(): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN, $this->mandantA);
    }

    /**
     * The platform-wide super admin in its production shape: a GLOBAL account
     * (`users.mandant_id = null`, matching its global `super_admin` pivot — the
     * only pairing the three `role_user` write sites can produce, see
     * `RoleAssignmentMandantInvariantTest`).
     *
     * Distinct from `createUserWithRole(SUPER_ADMIN, …)`, which builds the
     * hybrid shape (home mandant + global pivot) that `superAdmin()` uses as an
     * actor. The target of F1 needs the real thing.
     */
    private function createGlobalSuperAdmin(): User
    {
        $user = User::factory()->create([
            'mandant_id' => null,
            'email' => 'root@example.com',
        ]);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN, $this->mandantA);
    }

    /**
     * One application per ACCREDITATION: `applications` carries a unique index
     * on `(accreditation_id, user_id)`, so two applications of one accreditation
     * for one person are not a thing. Each row therefore gets its own
     * accreditation — a helper that silently inserted fewer than asked for
     * would make every count assertion below a tautology.
     *
     * @return list<Application>
     */
    private function withApplications(User $user, Mandant $mandant, int $count): array
    {
        $created = [];

        // Categories are unique per mandant, and a test may call this helper
        // twice for two different users of the SAME mandant — so the slug is
        // numbered globally, not per call. A collision here would surface as a
        // UniqueConstraintViolation rather than as a wrong count, which is the
        // better of the two failures but still not what the test is about.
        for ($i = 0; $i < $count; $i++) {
            $ordinal = $this->categoryOrdinal++;

            $category = $mandant->categories()->create([
                'name' => 'Presse '.$ordinal,
                'slug' => 'presse-'.$ordinal,
            ]);

            $accreditation = $mandant->accreditations()->create([
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 10,
            ]);

            $created[] = Application::create([
                'accreditation_id' => $accreditation->id,
                'user_id' => $user->id,
                'status' => 'requested',
            ]);
        }

        // The premise of every count assertion in this file.
        $this->assertSame($count, $user->applications()->count());

        return $created;
    }

    /**
     * Make the request look like a real HTTP request in production.
     *
     * `EnsureSameOrigin` skips itself while the app runs in the console (like
     * Laravel's own `VerifyCsrfToken`), and PHPUnit's requests look like console
     * ones — so BOTH conditions have to be lifted, exactly as `SameOriginGuardTest`
     * does it. Without this the guard is inert and the test would pass for the
     * wrong reason.
     */
    private function simulateAProductionHttpRequest(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $property = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, false);
    }

    /**
     * A process-wide counter for the generated category slugs — see
     * `withApplications()`.
     */
    private int $categoryOrdinal = 0;

    private function seedMedia(User $user, string $type, ?string $name = null): string
    {
        $path = sprintf('user-media/%s/%d/%s/%s.jpg', 'verband-a', $user->id, $type, $name ?? 'original');

        Storage::disk('private')->put($path, 'bytes');

        UserMedia::create([
            'user_id' => $user->id,
            'type' => $type,
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => 5,
            'original_name' => ($name ?? 'original').'.jpg',
        ]);

        return $path;
    }

    private function seedSessions(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sessions')->insert([
                'id' => 'session-'.$user->id.'-'.$i,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'x',
                'last_activity' => now()->getTimestamp(),
            ]);
        }
    }
}

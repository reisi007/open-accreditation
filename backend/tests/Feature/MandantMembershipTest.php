<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMandantMembership;
use App\Models\Accreditation;
use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserMedia;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #6-1: mandant membership is enforced PER REQUEST, not only at login.
 *
 * The JWT carries no mandant claim (`User::getJWTCustomClaims()` is empty) and
 * the only mandant check in the auth flow ran at login time, so a token minted
 * on mandant A was accepted on mandant B's domain. The un-gated write routes of
 * the `auth:api` group then created state inside the foreign mandant: `apply`
 * inserted an application into B (whose admins saw the foreign applicant,
 * portrait included), media uploads landed in B's storage namespace.
 *
 * `EnsureMandantMembership` closes that: a role assignment for the mandant the
 * request host resolved to — or a global `super_admin` — is required for every
 * authenticated API request.
 *
 * The hosts are REAL mandant domains (`a.test` / `b.test`) and the requests go
 * to them as absolute URLs, so the "attacker" here is exactly the documented
 * attack: one valid cookie replayed with a foreign `Host` header. Every
 * negative test proves the same token works on its OWN mandant first, so a 403
 * can never be confused with a broken token or a broken fixture.
 */
class MandantMembershipTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_A = 'a.test';

    private const HOST_B = 'b.test';

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // `MandantContext::resolve()` caches host → mandant; a stale entry
        // would silently answer with the wrong tenant.
        Cache::flush();

        $this->mandantA = Mandant::factory()->create([
            'slug' => 'verband-a',
            'name' => 'Verband A',
            'is_primary' => true,
        ]);
        $this->mandantB = Mandant::factory()->create([
            'slug' => 'verband-b',
            'name' => 'Verband B',
        ]);

        MandantDomain::factory()->for($this->mandantA)->create(['hostname' => self::HOST_A]);
        MandantDomain::factory()->for($this->mandantB)->create(['hostname' => self::HOST_B]);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The attack: a valid mandant-A token replayed on mandant B.
     -------------------------------------------------------------------- */

    public function test_token_of_mandant_a_cannot_apply_in_mandant_b(): void
    {
        $user = $this->memberOf($this->mandantA);
        $accreditation = $this->accreditationOf($this->mandantB);
        $token = $this->tokenFor($user);

        // The token is a perfectly valid mandant-A session …
        $this->withJwt($token)->getJson('http://'.self::HOST_A.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        // … and is refused on B, where the account holds no role.
        $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/accreditations/'.$accreditation->id.'/apply')
            ->assertForbidden()
            ->assertJsonPath('message', EnsureMandantMembership::DENIED_MESSAGE);

        // No application leaked into the foreign mandant's approval queue.
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseMissing('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_token_of_mandant_a_cannot_upload_media_into_mandant_b(): void
    {
        $user = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($user);

        $this->withJwt($token)
            ->post('http://'.self::HOST_B.'/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ])
            ->assertForbidden()
            ->assertJsonPath('message', EnsureMandantMembership::DENIED_MESSAGE);

        $this->assertDatabaseCount('user_media', 0);

        // The filesystem is the actual invariant here: `UserMediaController`
        // derives the path from `MandantContext::current()?->slug`, so the
        // upload must not land in the FOREIGN mandant's storage namespace —
        // and, for the same reason, not in the own one either.
        $this->assertSame(
            [],
            Storage::disk('private')->allFiles('user-media/'.$this->mandantB->slug),
            'Der Upload darf nicht im Storage-Namespace des fremden Mandanten landen.',
        );
        $this->assertSame(
            [],
            Storage::disk('private')->allFiles('user-media/'.$this->mandantA->slug),
        );
    }

    public function test_token_of_mandant_a_cannot_update_the_profile_on_mandant_b(): void
    {
        $user = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($user);

        $this->withJwt($token)
            ->putJson('http://'.self::HOST_B.'/api/user/profile', ['company' => 'Fremder Verband'])
            ->assertForbidden()
            ->assertJsonPath('message', EnsureMandantMembership::DENIED_MESSAGE);

        $this->assertNull($user->fresh()->company);
    }

    public function test_the_authenticated_read_routes_are_guarded_too(): void
    {
        $user = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($user);

        // `/api/auth/me` is deliberately NOT in this list: it is one of the two
        // #6-1-D1 exemptions (the caller's own record), see
        // `test_a_just_revoked_user_can_still_read_itself_and_log_out()`.
        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/applications')
            ->assertForbidden();

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/user/media')
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Positive controls — the guard must not over-block.
     -------------------------------------------------------------------- */

    public function test_member_of_the_current_mandant_applies_uploads_and_updates_the_profile(): void
    {
        $user = $this->memberOf($this->mandantA);
        $accreditation = $this->accreditationOf($this->mandantA);
        $token = $this->tokenFor($user);

        $this->withJwt($token)
            ->postJson('http://'.self::HOST_A.'/api/accreditations/'.$accreditation->id.'/apply')
            ->assertCreated();

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'requested',
        ]);

        $mediaId = $this->withJwt($token)
            ->post('http://'.self::HOST_A.'/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.png'),
            ])
            ->assertCreated()
            ->json('data.id');

        $path = UserMedia::findOrFail($mediaId)->path;
        $this->assertStringStartsWith(
            'user-media/verband-a/'.$user->id.'/portrait/',
            $path,
            'Der eigene Upload muss im Storage-Namespace des eigenen Mandanten landen.',
        );
        Storage::disk('private')->assertExists($path);

        $this->withJwt($token)
            ->putJson('http://'.self::HOST_A.'/api/user/profile', ['company' => 'Eigener Verband'])
            ->assertOk();

        $this->assertSame('Eigener Verband', $user->fresh()->company);
    }

    public function test_global_super_admin_may_act_in_a_foreign_mandant(): void
    {
        $admin = $this->superAdmin();
        $accreditation = $this->accreditationOf($this->mandantB);
        $token = $this->tokenFor($admin);

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);

        $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/accreditations/'.$accreditation->id.'/apply')
            ->assertCreated();

        $this->withJwt($token)
            ->post('http://'.self::HOST_B.'/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ])
            ->assertCreated();

        $this->withJwt($token)
            ->putJson('http://'.self::HOST_B.'/api/user/profile', ['company' => 'Superadmin'])
            ->assertOk();

        $this->assertDatabaseHas('applications', [
            'accreditation_id' => $accreditation->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_any_role_kind_in_the_mandant_counts_as_membership(): void
    {
        $user = $this->memberOf($this->mandantB, 'verifier');

        $this->withJwt($this->tokenFor($user))
            ->getJson('http://'.self::HOST_B.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    /* ---------------------------------------------------------------------
     | Revocation — the reason this is a per-request check and not a claim.
     -------------------------------------------------------------------- */

    public function test_a_revoked_role_takes_effect_immediately_without_re_login(): void
    {
        // The account holds a role in BOTH mandants, so the outcome can only be
        // about the revoked mandant — not about "the user has nothing left".
        $user = $this->memberOf($this->mandantA);
        $this->assign($user, $this->mandantB, 'user');

        $accreditation = $this->accreditationOf($this->mandantB);
        $token = $this->tokenFor($user);

        $this->withJwt($token)->getJson('http://'.self::HOST_B.'/api/auth/me')->assertOk();

        // The mandant revokes the role. The token is neither re-issued nor
        // invalidated — it stays cryptographically valid for JWT_TTL.
        RoleUser::query()
            ->where('user_id', $user->id)
            ->where('mandant_id', $this->mandantB->id)
            ->delete();

        // `/api/auth/me` and `POST /api/auth/logout` are the two #6-1-D1
        // exemptions and keep answering; everything else below is 403.
        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/accreditations/'.$accreditation->id.'/apply')
            ->assertForbidden();

        $this->withJwt($token)
            ->post('http://'.self::HOST_B.'/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ])
            ->assertForbidden();

        $this->withJwt($token)
            ->putJson('http://'.self::HOST_B.'/api/user/profile', ['company' => 'Fremder Verband'])
            ->assertForbidden();

        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('user_media', 0);
        $this->assertNull($user->fresh()->company);
        $this->assertSame([], Storage::disk('private')->allFiles('user-media/'.$this->mandantB->slug));

        // … and the revocation is SCOPED, not global: the very same token still
        // works on the mandant the account belongs to.
        $this->withJwt($token)->getJson('http://'.self::HOST_A.'/api/auth/me')->assertOk();
    }

    /* ---------------------------------------------------------------------
     | #6-1-D1 — the two exemptions: session teardown and "my own record"
     -------------------------------------------------------------------- */

    /**
     * A role revoked a second ago must not strand the session: the SPA calls
     * `/auth/me` on every load and `POST /auth/logout` on sign-out, and neither
     * may answer 403 (the frontend has no other way to drop the httpOnly
     * cookie — a 403 is a normal `ApiError`, only a 401 triggers its cleanup).
     *
     * `POST /api/auth/logout` answers 200 with the "successfully signed out"
     * body plus the expired cookie (`AuthController::logout()`), NOT 204.
     */
    public function test_a_just_revoked_user_can_still_read_itself_and_log_out(): void
    {
        $user = $this->memberOf($this->mandantA);
        $this->assign($user, $this->mandantB, 'user');

        $token = $this->tokenFor($user);

        $this->withJwt($token)->getJson('http://'.self::HOST_B.'/api/auth/me')->assertOk();

        RoleUser::query()
            ->where('user_id', $user->id)
            ->where('mandant_id', $this->mandantB->id)
            ->delete();

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);

        $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/auth/logout')
            ->assertOk()
            ->assertCookieExpired(config('jwt.cookie_key_name'));

        // The exemption ends the session for real — the token is blacklisted, so
        // a replay of the same cookie is a 401, not a 200.
        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/auth/me')
            ->assertUnauthorized();
    }

    /**
     * The exemption is scoped to those two routes and nothing else: the very
     * same revoked user is still refused on every mandant-scoped WRITE, and no
     * state is created or written in the foreign mandant.
     */
    public function test_the_exemption_does_not_open_the_tenant_scoped_write_routes(): void
    {
        $user = $this->memberOf($this->mandantA);
        $this->assign($user, $this->mandantB, 'user');

        $accreditation = $this->accreditationOf($this->mandantB);
        $token = $this->tokenFor($user);

        RoleUser::query()
            ->where('user_id', $user->id)
            ->where('mandant_id', $this->mandantB->id)
            ->delete();

        $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/accreditations/'.$accreditation->id.'/apply')
            ->assertForbidden()
            ->assertJsonPath('message', EnsureMandantMembership::DENIED_MESSAGE);

        $this->withJwt($token)
            ->post('http://'.self::HOST_B.'/api/user/media', [
                'type' => 'portrait',
                'file' => UploadedFile::fake()->image('portrait.jpg'),
            ])
            ->assertForbidden()
            ->assertJsonPath('message', EnsureMandantMembership::DENIED_MESSAGE);

        $this->withJwt($token)
            ->putJson('http://'.self::HOST_B.'/api/user/profile', ['company' => 'Fremder Verband'])
            ->assertForbidden()
            ->assertJsonPath('message', EnsureMandantMembership::DENIED_MESSAGE);

        // … and the mandant-scoped READS stay closed as well: `/me` is exempt
        // because it is the caller's OWN record, not because reads are exempt.
        $this->withJwt($token)->getJson('http://'.self::HOST_B.'/api/applications')->assertForbidden();
        $this->withJwt($token)->getJson('http://'.self::HOST_B.'/api/user/media')->assertForbidden();

        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('user_media', 0);
        $this->assertNull($user->fresh()->company);
        $this->assertSame([], Storage::disk('private')->allFiles('user-media/'.$this->mandantB->slug));
    }

    /**
     * The exemption list is pinned on purpose: it must stay exactly these two
     * routes, each bound to its own method, so a future route cannot inherit it
     * by reusing a name and a wildcard entry cannot creep in.
     */
    /**
     * The exemption list is pinned on purpose: it must stay exactly these two
     * routes, each bound to its own method AND to its own URI, so a future
     * route cannot inherit it by reusing a name and a wildcard entry cannot
     * creep in.
     *
     * The URI is the part `EXEMPT_ROUTES`' own docblock claims. The NAME is
     * the only thing the middleware matches on, so the URI is what says WHICH
     * route the name currently points at. Without this assertion the guarantee
     * is unfalsifiable: moving `api.auth.me` onto, say,
     * `/api/admin/users/me` would keep the test green while making the
     * exemption cover a completely different endpoint.
     */
    public function test_the_exemption_list_is_exactly_logout_and_me_with_their_methods(): void
    {
        $this->assertSame([
            'api.auth.logout' => 'POST',
            'api.auth.me' => 'GET',
        ], EnsureMandantMembership::EXEMPT_ROUTES);

        $expectedUris = [
            'api.auth.logout' => 'api/auth/logout',
            'api.auth.me' => 'api/auth/me',
        ];

        $routes = app(Router::class)->getRoutes();

        foreach (EnsureMandantMembership::EXEMPT_ROUTES as $name => $method) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, sprintf('Die ausgenommene Route "%s" existiert nicht.', $name));
            $this->assertContains($method, $route->methods());
            $this->assertSame($expectedUris[$name], $route->uri());
        }
    }

    /**
     * M3: both exemptions write a log line per request (`logExempt()` /
     * `deny()`) and are the only routes that answer for an account without a
     * role in the current mandant, so both are rate limited: a replay loop
     * must not be able to turn them into an unbounded log-write amplifier.
     *
     * The bucket is per authenticated user, so it can only ever bite the
     * offending account itself, and the two routes carry SEPARATE prefixes so
     * a reload loop on `/auth/me` cannot lock the user out of `/auth/logout`
     * (the one call that has to work to end his session).
     */
    public function test_the_two_exempt_routes_are_rate_limited_per_user(): void
    {
        $user = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($user);

        // 60/min is deliberately far above what a real SPA needs (one /auth/me
        // per page load), so the whole budget fits into this test.
        for ($request = 1; $request <= 60; $request++) {
            $this->withJwt($token)
                ->getJson('http://'.self::HOST_A.'/api/auth/me')
                ->assertOk();
        }

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_A.'/api/auth/me')
            ->assertStatus(429);

        // A different account has its own budget ...
        $other = $this->tokenFor($this->memberOf($this->mandantA));

        $this->withJwt($other)
            ->getJson('http://'.self::HOST_A.'/api/auth/me')
            ->assertOk();

        // ... and the two exempt routes do not share one.
        $this->withJwt($token)
            ->postJson('http://'.self::HOST_A.'/api/auth/logout')
            ->assertOk();
    }

    /* ---------------------------------------------------------------------
     | No mandant context / no session — the guard stays inert.
     -------------------------------------------------------------------- */

    public function test_request_without_a_resolved_mandant_is_not_blocked(): void
    {
        // An account with NO role assignment at all (only its home mandant
        // column) on a request that resolves no mandant at all: console/CLI and
        // tests run without a host, and there is nothing to be a member of.
        $user = User::factory()->forMandant($this->mandantA)->create();
        $token = $this->tokenFor($user);

        MandantContext::reset();

        $this->withJwt($token)
            ->putJson('http://no-mandant.invalid/api/user/profile', ['company' => 'Kein Mandant'])
            ->assertOk();

        $this->assertSame('Kein Mandant', $user->fresh()->company);
        $this->assertFalse(MandantContext::hasCurrent());
    }

    public function test_public_routes_are_unaffected_by_a_foreign_cookie(): void
    {
        $user = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($user);
        $this->accreditationOf($this->mandantB);

        // The public accreditation list / portal / QR verification of the
        // FOREIGN mandant are not `auth:api` routes: a cookie must neither be
        // authenticated nor rejected there — and the guard must not even spend
        // a query.
        DB::enableQueryLog();

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/accreditations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/portal/overview')
            ->assertOk();

        $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/verify/unknown-token')
            ->assertNotFound();

        $membershipQueries = $this->roleUserQueryCount();

        DB::disableQueryLog();

        $this->assertSame(0, $membershipQueries, 'Öffentliche Routen dürfen keine Mitgliedschafts-Query kosten.');
    }

    public function test_membership_costs_exactly_one_query_per_authenticated_request(): void
    {
        $user = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($user);

        // `GET /api/user/media` is the cheapest authenticated route that does
        // not load the role relation itself, so the only `role_user` query in
        // the log is the membership check.
        DB::enableQueryLog();

        $this->withJwt($token)->getJson('http://'.self::HOST_A.'/api/user/media')->assertOk();

        $membershipQueries = $this->roleUserQueryCount();

        DB::disableQueryLog();

        $this->assertSame(1, $membershipQueries);
    }

    /**
     * The placement is a deliberate part of the design, so it is pinned here
     * instead of left implicit: the check must run AFTER authentication (it
     * needs the resolved user) and AFTER every rate limiter (a rejected
     * cross-mandant request stays inside the `throttle:apply` / `throttle:media`
     * budget — the same order the login route uses: `throttle:login` first,
     * `mayLogInOnCurrentMandant()` second), while still running BEFORE the
     * controller action.
     */
    public function test_the_check_is_positioned_after_auth_and_after_the_rate_limiters(): void
    {
        $kernel = app(HttpKernel::class);

        $property = new \ReflectionProperty($kernel, 'middlewarePriority');
        $priority = $property->getValue($kernel);

        $index = array_search(EnsureMandantMembership::class, $priority, true);

        $this->assertIsInt($index, 'Die Middleware muss in der Priority-Liste stehen.');

        $position = fn (string $middleware): int => (int) array_search($middleware, $priority, true);

        $this->assertGreaterThan(
            $position(AuthenticatesRequests::class),
            $index,
            'Die Mitgliedschaftsprüfung muss NACH der Authentifizierung laufen.',
        );
        $this->assertGreaterThan(
            $position(ThrottleRequests::class),
            $index,
            'Die Mitgliedschaftsprüfung muss NACH den Rate-Limitern laufen.',
        );
        $this->assertGreaterThan(
            $position(SubstituteBindings::class),
            $index,
            'Die Mitgliedschaftsprüfung muss NACH dem Route-Model-Binding laufen.',
        );

        // It really is the last middleware of the api group.
        $group = $kernel->getMiddlewareGroups()['api'];

        $this->assertContains(EnsureMandantMembership::class, $group);
        $this->assertSame(EnsureMandantMembership::class, end($group));
    }

    public function test_model_helper_reflects_the_membership_invariant(): void
    {
        $user = $this->memberOf($this->mandantA);

        $this->assertTrue($user->isMemberOfMandant($this->mandantA->id));
        $this->assertFalse($user->isMemberOfMandant($this->mandantB->id));
        $this->assertTrue($this->superAdmin()->isMemberOfMandant($this->mandantB->id));

        // Without any mandant there is nothing to be a member of — and a
        // missing resolved context must not blow up.
        MandantContext::reset();

        $this->assertFalse($user->isMemberOfMandant());
    }

    /**
     * `isMemberOfMandant()` and `isSuperAdmin()` must not disagree about the
     * same `role_user` row: `isSuperAdmin()` requires the GLOBAL assignment
     * (`mandant_id IS NULL AND team_id IS NULL`), while the super-admin branch
     * of `isMemberOfMandant()` used to check `mandant_id` only. A corrupt row
     * that carried a team id was therefore "a global super admin" for the
     * per-request gate and "not a super admin" for every permission check.
     *
     * FAILS WITHOUT THE FIX: `isMemberOfMandant()` answered true.
     */
    public function test_a_super_admin_row_with_a_team_id_counts_for_neither_predicate(): void
    {
        $admin = User::factory()->create();

        // A REAL team, so only the scope of the pivot row is what is off — the
        // row itself stays referentially valid.
        $team = $this->mandantA->teams()->create([
            'name' => 'FC Mustermann',
            'slug' => 'fc-mustermann',
        ]);

        RoleUser::create([
            'user_id' => $admin->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => $team->id,
        ]);

        $this->assertFalse($admin->isSuperAdmin(), 'ein Super-Admin mit Team-Scope ist kein globaler Super-Admin');
        $this->assertFalse(
            $admin->isMemberOfMandant($this->mandantB->id),
            'die Mitgliedschafts-Prüfung muss dieselbe Zeile gleich lesen wie isSuperAdmin()',
        );
    }

    /* ---------------------------------------------------------------------
     | M2 — no 404-vs-403 existence oracle for non-members
     -------------------------------------------------------------------- */

    /**
     * `SubstituteBindings` resolves the route model BEFORE
     * `EnsureMandantMembership` runs. `user_media` was the one bound model
     * WITHOUT a scoped `resolveRouteBindingQuery()` (it carries no
     * `mandant_id` column), so a global `find` answered 403 for a row that
     * exists in ANY mandant and 404 for an unknown id — a cross-tenant
     * existence oracle a replayed cookie could mine id by id.
     *
     * The M2 fix scopes the `UserMedia` binding to the current mandant's
     * storage-path prefix, so a FOREIGN row is now as invisible as an unknown
     * id. Both answers must be identical.
     *
     * FAILS WITHOUT THE FIX: foreign row → 403 (binding succeeds, membership
     * denies), unknown id → 404.
     */
    public function test_a_foreign_media_row_is_indistinguishable_from_an_unknown_id_for_a_non_member(): void
    {
        $attacker = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($attacker);

        // A media row that EXISTS, but belongs to the attacker's own mandant A
        // and is therefore foreign on B. Its `path` carries A's slug — the only
        // stored tenant marker of `user_media`.
        $foreign = UserMedia::create([
            'user_id' => $this->memberOf($this->mandantA)->id,
            'type' => 'portrait',
            'path' => 'user-media/'.$this->mandantA->slug.'/1/portrait/portrait.jpg',
            'mime' => 'image/jpeg',
            'size' => 123,
            'original_name' => 'portrait.jpg',
        ]);

        $existing = $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/user/media/'.$foreign->id);

        $unknown = $this->withJwt($token)
            ->getJson('http://'.self::HOST_B.'/api/user/media/'.($foreign->id + 100000));

        $this->assertSame(404, $existing->getStatusCode());
        $this->assertSame(
            $unknown->getStatusCode(),
            $existing->getStatusCode(),
            'Ein Non-Member darf an der Antwort nicht erkennen, ob eine fremde Media-Zeile existiert.',
        );
    }

    /**
     * The same probe against an ALREADY-scoped model has no distinguishable
     * case: `Accreditation::resolveRouteBindingQuery()` filters by
     * `mandant_id`, so a foreign row fails to resolve exactly like an unknown
     * id — both 404, before the membership check can answer 403. This pins the
     * cross-tenant property (Option B is a no-op for scoped models) so a
     * future change that un-scopes a binding or reorders the middleware is
     * caught.
     *
     * PASSES BEFORE AND AFTER THE M2 FIX.
     */
    public function test_a_foreign_accreditation_is_indistinguishable_from_an_unknown_id_for_a_non_member(): void
    {
        $attacker = $this->memberOf($this->mandantA);
        $token = $this->tokenFor($attacker);

        $foreign = $this->accreditationOf($this->mandantA);

        $existing = $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/accreditations/'.$foreign->id.'/apply');

        $unknown = $this->withJwt($token)
            ->postJson('http://'.self::HOST_B.'/api/accreditations/'.($foreign->id + 100000).'/apply');

        $existing->assertNotFound();
        $unknown->assertNotFound();

        $this->assertSame($unknown->getStatusCode(), $existing->getStatusCode());
    }

    /**
     * `users` was the SECOND bound model without a scoped
     * `resolveRouteBindingQuery()` (after `UserMedia`), so the same split
     * existed on `PUT /api/admin/users/{user}/roles`: a global `find` let a
     * foreign row through to the membership check (403) while an unknown id
     * failed to bind (404).
     *
     * The attacker is a `mandant_admin` of A — an account that legitimately
     * reaches this very endpoint on its OWN host, so the 403 below can only
     * come from the membership check and never from a missing permission.
     *
     * FAILS WITHOUT THE FIX: foreign user → 403 (binding succeeds, membership
     * denies), unknown id → 404.
     */
    public function test_a_foreign_user_is_indistinguishable_from_an_unknown_id_for_a_non_member(): void
    {
        $attacker = $this->memberOf($this->mandantA, 'mandant_admin');
        $token = $this->tokenFor($attacker);

        // A user that EXISTS, but holds a role only in mandant A and is
        // therefore foreign on B.
        $foreign = $this->memberOf($this->mandantA, 'user');

        $existing = $this->withJwt($token)
            ->putJson('http://'.self::HOST_B.'/api/admin/users/'.$foreign->id.'/roles', [
                'roles' => [['role' => 'verifier']],
            ]);

        $unknown = $this->withJwt($token)
            ->putJson('http://'.self::HOST_B.'/api/admin/users/'.($foreign->id + 100000).'/roles', [
                'roles' => [['role' => 'verifier']],
            ]);

        $this->assertSame(404, $existing->getStatusCode());
        $this->assertSame(
            $unknown->getStatusCode(),
            $existing->getStatusCode(),
            'Ein Non-Member darf an der Antwort nicht erkennen, ob die User-ID irgendwo existiert.',
        );

        // … and nothing was written on either path.
        $this->assertSame(
            0,
            RoleUser::query()
                ->where('user_id', $foreign->id)
                ->where('role_id', Role::query()->where('slug', 'verifier')->value('id'))
                ->count(),
        );
    }

    /**
     * The scope must not over-restrict. `users` is the one model with a
     * legitimate MULTI-mandant shape: `role_user.mandant_id` is nullable, one
     * account may hold roles in several mandants at once, and the global
     * `super_admin` row has BOTH `mandant_id` and `team_id` null. A naive
     * `where('users.mandant_id', $currentId)` would therefore have 404'd the
     * very targets `PUT /api/admin/users/{user}/roles` exists for.
     *
     * Pinned at the binding itself (not through a route, whose 403/404 mix
     * would blur the signal), as the falsifiable statement it is:
     *
     *   `resolveRouteBinding()` resolves a user ⟺ `isMemberOfMandant()` says yes.
     *
     * The four shapes: member of the current mandant; a member of the current
     * mandant whose HOME mandant (`users.mandant_id`) is a different one; the
     * global `super_admin`; and — as the negative control — a user of a foreign
     * mandant only.
     */
    public function test_the_scoped_user_binding_resolves_exactly_the_members_of_the_current_mandant(): void
    {
        $member = $this->memberOf($this->mandantA, 'user');
        $superAdmin = $this->superAdmin();

        // Holds a role in A AND in B, but its home mandant is B: on A's host it
        // must still resolve — the scope reads `role_user`, not `users`.
        $multiMandant = $this->memberOf($this->mandantB, 'user');
        $this->assign($multiMandant, $this->mandantA, 'verifier');

        $foreign = $this->memberOf($this->mandantB, 'user');

        MandantContext::set($this->mandantA);

        foreach ([$member, $multiMandant, $superAdmin] as $expected) {
            $this->assertTrue(
                (new User)->resolveRouteBinding($expected->id)?->is($expected),
                sprintf('User #%d ist Mitglied von A und muss binden.', $expected->id),
            );
        }

        $this->assertNull(
            (new User)->resolveRouteBinding($foreign->id),
            'Ein User ohne Rolle im aktuellen Mandant darf nicht binden.',
        );

        // The relation that decides it is the membership predicate, not a
        // narrowed subset of it.
        $this->assertTrue($member->isMemberOfMandant($this->mandantA->id));
        $this->assertTrue($multiMandant->isMemberOfMandant($this->mandantA->id));
        $this->assertTrue($superAdmin->isMemberOfMandant($this->mandantA->id));
        $this->assertFalse($foreign->isMemberOfMandant($this->mandantA->id));

        // … and it flips with the current mandant: on B the same multi-mandant
        // account still binds, the pure A member does not.
        MandantContext::set($this->mandantB);

        $this->assertNotNull((new User)->resolveRouteBinding($multiMandant->id));
        $this->assertNotNull((new User)->resolveRouteBinding($superAdmin->id));
        $this->assertNull((new User)->resolveRouteBinding($member->id));
    }

    /**
     * The real workflow the scope must not break: a `mandant_admin` of A
     * replaces the role set of a user of A and gets 200. `AdminUserTest` covers
     * the endpoint's semantics; what is new here is that the target survives
     * route-model binding on a request whose host really resolves mandant A.
     */
    public function test_a_mandant_admin_can_still_manage_the_roles_of_a_member(): void
    {
        $actor = $this->memberOf($this->mandantA, 'mandant_admin');
        $target = $this->memberOf($this->mandantA, 'user');

        $this->withJwt($this->tokenFor($actor))
            ->putJson('http://'.self::HOST_A.'/api/admin/users/'.$target->id.'/roles', [
                'roles' => [['role' => 'verifier']],
            ])
            ->assertOk();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $target->id,
            'mandant_id' => $this->mandantA->id,
            'team_id' => null,
        ]);
    }

    /**
     * The scope is INERT without a resolved mandant (seeders, console commands,
     * tests that never set a context), exactly like every other scoped binding —
     * otherwise a CLI command could no longer load a user by id.
     */
    public function test_the_scoped_user_binding_is_inert_without_a_current_mandant(): void
    {
        $foreign = $this->memberOf($this->mandantB, 'user');

        MandantContext::reset();

        $this->assertFalse(MandantContext::hasCurrent());
        $this->assertNotNull(
            (new User)->resolveRouteBinding($foreign->id),
            'ohne MandantContext bleibt das Binding ungefiltert.',
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     -------------------------------------------------------------------- */

    private function memberOf(Mandant $mandant, string $roleSlug = 'user'): User
    {
        $user = User::factory()->forMandant($mandant)->create();

        $this->assign($user, $mandant, $roleSlug);

        return $user;
    }

    private function assign(User $user, Mandant $mandant, string $roleSlug): void
    {
        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();

        RoleUser::create([
            'user_id' => $admin->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $admin;
    }

    private function accreditationOf(Mandant $mandant): Accreditation
    {
        $category = $mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.$mandant->slug,
        ]);

        return $mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);
    }

    /**
     * Mint a JWT the way the login flow does — with its OWN mandant as the
     * current one, i.e. the token is issued in the legitimate context and only
     * the replay onto the foreign host is illegitimate.
     */
    private function tokenFor(User $user): string
    {
        MandantContext::set($user->mandant);

        $token = auth('api')->login($user);

        // `login()` leaves TWO pieces of process-global state behind, and both
        // survive between the requests of one test: the token in the
        // `JWT::$token` singleton, and the user memoised on the `api` guard —
        // a container singleton, while a real request boots a fresh app and
        // therefore a fresh guard.
        //
        // `forgetJwtAuthState()` drops both, which is what makes the comment
        // this call used to carry TRUE: the guard is unresolved again until
        // `auth:api` runs, so a public route below is provably inert and a
        // 401 can only come from the request. Calling `forgetUser()` alone left
        // the singleton in place, so the class answered 17 of its own tests
        // out of memory with the cookie channel completely dead — green, but
        // for the wrong reason, and blind to a channel break. MEASURED with a
        // `TestCase::call()` override clearing the singleton before every
        // request: the class stays green through the channel and through this
        // call, which is the property that makes it worth having.
        $this->forgetJwtAuthState();

        return $token;
    }

    private function withJwt(string $token): static
    {
        return $this->withJwtCookie($token);
    }

    private function roleUserQueryCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'role_user'))
            ->count();
    }
}

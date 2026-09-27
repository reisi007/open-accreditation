<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

/**
 * The host half of the tenant chain, measured end to end:
 * **Host header → `MandantContextMiddleware` → `MandantContext::resolve()` →
 * `GET /api/auth/me` → `current_mandant_id`**.
 *
 * The two ends of that chain were tested, the middle never was:
 *
 *  - `AuthMeTest::test_me_exposes_current_mandant_id_from_host_resolution`
 *    (line 69) is named after host resolution but sets the context by hand —
 *    `MandantContext::set($mandant)`, with a comment saying it "simulate[s] the
 *    resolved mandant for the request host".
 *  - `AdminTeamTest::test_super_admin_on_non_primary_domain_sees_current_mandants_teams`
 *    (line 426) asserts the same field, likewise with a hand-set context.
 *  - `MandantContextTest` drives the real middleware, but asserts
 *    `MandantContext::current()` after a request to the **portal root** `/`,
 *    not the `/api/auth/me` response the SPA actually derives its tenant from.
 *
 * So the half that does the work on a domain switch — the Host header reaching
 * the resolver, and the resolver's mandant arriving in the `/me` payload the
 * frontend reads (`frontend/src/api/types.ts` → `current_mandant_id`) — was
 * asserted by nobody. That is what this file closes.
 *
 * HOW THE REAL HOST HEADER IS SET (the existing repo pattern, not an invention):
 * an **absolute URL** passed to `get()`/`getJson()`. `MakesHttpRequests::call()`
 * hands it to `Request::create()`, which derives `HTTP_HOST` from the URL, so
 * `$request->getHost()` returns that host. Precedents in this suite:
 * `MandantContextTest.php:152` (`$this->get('http://bundesliga.test/')`),
 * `MandantContextTest.php:188`, `TrustHostsTest.php:64`, `TrustHostsTest.php:99`,
 * `TrustedHostsConfigTest.php:135`. There is no `withServerVariables(['HTTP_HOST' …])`
 * convention anywhere in this repo — grep for it returns only `REMOTE_ADDR`
 * (proxy/throttle tests), which is a different header for a different purpose.
 *
 * Two environment switches are needed for the paths a `testing`-env request can
 * never reach, again following `MandantContextTest` / `TrustHostsTest`:
 * `app()->detectEnvironment(fn () => 'production')` and a `setRunningInConsole(false)`
 * reflection. They matter because `MandantContextMiddleware::skipsUnknownMandant()`
 * is `runningInConsole() || environment('testing')` — PHPUnit runs as a console
 * process, so an unknown host would silently continue instead of 404.
 */
class MandantHostHeaderResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // `CACHE_STORE=array` is per-process but NOT per test method, and
        // `resolve()` caches host→mandant-id under `mandant.domain.{host}` for
        // `mandants.cache_ttl` while `hostnames()` caches the `trustHosts`
        // allow-list. A warm entry from a previous test would answer for a
        // hostname whose row no longer exists (or, worse, for a stale id), so
        // every test starts from a cold, honest cache and an empty container.
        // The context is deliberately NEVER set manually in this file — that
        // would defeat the purpose.
        Cache::flush();
        MandantContext::forgetHostnames();
        MandantContext::reset();
    }

    protected function tearDown(): void
    {
        // Production-simulated requests activate the `TrustHosts` middleware,
        // which writes Symfony's static trusted-host patterns; they must not
        // leak into later tests.
        Request::setTrustedHosts([]);

        MandantContext::forgetHostnames();
        MandantContext::reset();
        Cache::flush();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* 1 + 2 — a real Host header decides the mandant in /api/auth/me */
    /* ------------------------------------------------------------------ */

    public function test_me_on_a_non_primary_domain_reports_that_mandants_id(): void
    {
        $secondary = $this->mandantWithDomain('verband-a', 'verband-a.test');
        $this->primaryWithDomain('haupt', 'haupt.test');

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('http://verband-a.test/api/auth/me')
            ->assertOk();

        $this->assertSame(
            $secondary->id,
            $response->json('data.current_mandant_id'),
            'the Host header must decide the tenant, not the primary mandant and not a hand-set context',
        );

        // The middleware and the resource must agree — `/me` is only the
        // observable surface of the container the middleware filled.
        $this->assertTrue(MandantContext::current()?->is($secondary));
    }

    public function test_me_on_the_primary_domain_reports_the_primary_mandants_id(): void
    {
        $this->mandantWithDomain('verband-a', 'verband-a.test');
        $primary = $this->primaryWithDomain('haupt', 'haupt.test');

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('http://haupt.test/api/auth/me')
            ->assertOk();

        $this->assertSame($primary->id, $response->json('data.current_mandant_id'));
        $this->assertTrue(MandantContext::current()?->is($primary));
    }

    /**
     * The domain switch itself, on ONE account and ONE token: the only thing
     * that changes between the two requests is the Host header, so the
     * `current_mandant_id` flip cannot be explained by the session, the user
     * or a leftover container binding.
     */
    public function test_the_same_token_reports_a_different_mandant_per_host(): void
    {
        $secondary = $this->mandantWithDomain('verband-a', 'verband-a.test');
        $primary = $this->primaryWithDomain('haupt', 'haupt.test');

        $this->actingAsApi($this->superAdmin())
            ->getJson('http://verband-a.test/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $secondary->id);

        $this->actingAsApi($this->superAdmin())
            ->getJson('http://haupt.test/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $primary->id);
    }

    /**
     * A SECOND domain of a non-primary mandant resolves to the same mandant —
     * the resolver keys on `mandant_domains.hostname`, not on "the one domain
     * the mandant happens to have". This is what makes `www.<domain>` style
     * aliases work at all.
     */
    public function test_every_domain_of_a_mandant_resolves_to_that_mandant(): void
    {
        $secondary = $this->mandantWithDomain('verband-a', 'verband-a.test');
        MandantDomain::factory()->for($secondary)->create(['hostname' => 'www.verband-a.test']);
        $this->primaryWithDomain('haupt', 'haupt.test');

        foreach (['verband-a.test', 'www.verband-a.test'] as $host) {
            $this->actingAsApi($this->superAdmin())
                ->getJson('http://'.$host.'/api/auth/me')
                ->assertOk()
                ->assertJsonPath('data.current_mandant_id', $secondary->id, $host);
        }
    }

    /**
     * The Host header arrives case-insensitively and with a port attached
     * (`Request::getHost()` strips the port, `resolve()` lower-cases), and both
     * forms must still land on the right mandant — `resolveHost()` lower-cases
     * and `isLoopback()` explicitly tolerates a port suffix.
     */
    public function test_the_host_header_is_matched_case_insensitively_and_with_a_port(): void
    {
        $secondary = $this->mandantWithDomain('verband-a', 'verband-a.test');
        $this->primaryWithDomain('haupt', 'haupt.test');

        // `MandantDomainController::store` lower-cases on write, so an
        // upper-case / ported request has to normalise onto the stored row.
        $this->actingAsApi($this->superAdmin())
            ->getJson('http://Verband-A.test:8443/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $secondary->id);
    }

    /* ------------------------------------------------------------------ */
    /* 3 — unknown host → 404 */
    /* ------------------------------------------------------------------ */

    /**
     * An allow-listed host that maps to no mandant is a 404 — the documented
     * contract of `resolve()` returning `null` (its docblock, lines 99-101) and
     * of `MandantContextMiddleware::handle()`'s `abort(404, 'Mandant not found')`.
     *
     * The request is **authenticated** on purpose: `/api/auth/me` would answer
     * 401 without a token, and a 404 on an anonymous request could not be told
     * apart from one caused by the host layer. The super_admin token proves the
     * 404 comes from the host, not from `auth:api`.
     */
    public function test_an_allow_listed_host_without_a_mandant_is_not_found(): void
    {
        config(['security.trusted_hosts' => 'verwaist.test']);
        $this->pretendToBeAProductionRequest();

        $this->primaryWithDomain('haupt', 'haupt.test');

        // No `mandant_domains` row for this host: allow-listed, but owned by
        // nobody → `resolve()` returns null → 404.
        $this->actingAsApi($this->superAdmin())
            ->getJson('http://verwaist.test/api/auth/me')
            ->assertNotFound();

        $this->assertNull(MandantContext::current(), 'a 404 must not leave a mandant in the container');
    }

    /**
     * The second way to reach `resolve() === null`: the host IS a domain of a
     * mandant, but that mandant is inactive. `hostnames()` plucks every
     * `mandant_domains` row regardless of the owner's `is_active`, so the host
     * is allow-listed, `resolve()`'s `Mandant::active()->find()` returns null,
     * and a deactivated tenant turns into a 404 rather than a silently
     * half-live one.
     */
    public function test_a_host_whose_mandant_is_inactive_is_not_found(): void
    {
        $this->pretendToBeAProductionRequest();

        $inactive = Mandant::factory()->create(['slug' => 'inaktiv', 'is_active' => false]);
        MandantDomain::factory()->for($inactive)->create(['hostname' => 'inaktiv.test']);

        $this->actingAsApi($this->superAdmin())
            ->getJson('http://inaktiv.test/api/auth/me')
            ->assertNotFound();

        $this->assertNull(MandantContext::current());
    }

    /**
     * A host outside the allow-list never reaches the mandant layer at all —
     * Symfony rejects it with a 400 first. Pinned here because the 404 tests
     * above depend on that ordering: without the allow-list, "unknown host"
     * would be a 400 and the 404 contract would look untestable.
     */
    public function test_a_host_outside_the_allow_list_is_rejected_with_400_before_the_mandant_layer(): void
    {
        $this->pretendToBeAProductionRequest();

        $this->primaryWithDomain('haupt', 'haupt.test');

        $this->actingAsApi($this->superAdmin())
            ->getJson('http://fremd.example/api/auth/me')
            ->assertStatus(400);
    }

    /* ------------------------------------------------------------------ */
    /* 4 — loopback fallback */
    /* ------------------------------------------------------------------ */

    /**
     * `localhost` / `127.0.0.1` / `::1` (with and without a port) and any
     * `*.localhost` subdomain have no `mandant_domains` row, so `resolve()`
     * returns null and `MandantContextMiddleware` falls back to
     * `MandantContext::default()` — the `is_primary` mandant — in
     * `local`/`testing`. That is what keeps `php artisan serve`, the Vite dev
     * server and the CI probes off the 404 path. Asserted through
     * `/api/auth/me`, so the fallback is measured at the surface the SPA reads,
     * not only in the container.
     *
     * The IPv6 rows are the ones with a normalising step in front of them: the
     * host arrives as `'[::1]'` and `isLoopback()` unwraps it. The shape
     * precondition and the measured before/after live in
     * `test_the_ipv6_loopback_host_arrives_bracketed_and_still_reaches_the_primary`
     * below, so the table here stays the single home of the *claim*.
     */
    #[DataProvider('loopbackHosts')]
    public function test_a_loopback_host_falls_back_to_the_primary_mandant(string $url): void
    {
        $this->mandantWithDomain('verband-a', 'verband-a.test');
        $primary = $this->primaryWithDomain('haupt', 'haupt.test');

        $this->actingAsApi($this->superAdmin())
            ->getJson($url.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $primary->id);

        $this->assertTrue(MandantContext::current()?->is($primary));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function loopbackHosts(): array
    {
        return [
            'localhost' => ['http://localhost'],
            'localhost with port' => ['http://localhost:5173'],
            'IPv4 loopback' => ['http://127.0.0.1'],
            'IPv4 loopback with port' => ['http://127.0.0.1:8000'],
            'localhost subdomain' => ['http://mandant-a.localhost'],
            'IPv6 loopback' => ['http://[::1]'],
            'IPv6 loopback with port' => ['http://[::1]:8000'],
        ];
    }

    /**
     * The fallback needs a primary mandant; without one there is nothing to
     * fall back to and the request simply continues without a tenant.
     */
    public function test_a_loopback_host_without_a_primary_yields_no_mandant(): void
    {
        $this->mandantWithDomain('verband-a', 'verband-a.test');

        $this->actingAsApi($this->superAdmin())
            ->getJson('http://127.0.0.1/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', null);
    }

    /**
     * The loopback fallback is a `local`/`testing` convenience ONLY — in
     * production a loopback Host is a foreign host and stays a 404
     * (`isLoopback($host) && app()->environment('local', 'testing')`).
     *
     * `::1` is listed explicitly: the IPv6 fix normalises the brackets inside
     * `isLoopback()`, and the guard it sits behind is what keeps that from
     * handing a **production** deployment a mandant on a loopback Host. If the
     * environment check were ever widened, this case is what turns red.
     */
    #[DataProvider('productionLoopbackUrls')]
    public function test_the_loopback_fallback_does_not_apply_in_production(string $url): void
    {
        $this->pretendToBeAProductionRequest();

        $this->primaryWithDomain('haupt', 'haupt.test');

        $this->actingAsApi($this->superAdmin())
            ->getJson($url.'/api/auth/me')
            ->assertNotFound();

        $this->assertNull(MandantContext::current());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function productionLoopbackUrls(): array
    {
        return [
            'IPv4 loopback' => ['http://127.0.0.1'],
            'IPv6 loopback' => ['http://[::1]'],
        ];
    }

    /**
     * WHY the IPv6 case above is not the trivially-passing one: the loopback
     * host reaches `isLoopback()` **bracketed**, at two independent layers,
     * and both are pinned here.
     *
     *  - `Request::getHost()` (what `resolveHost()` returns) keeps the brackets:
     *    `'[::1]'`. Symfony's `isHostValid()` accepts a Host only as a *balanced*
     *    `[...]` literal, so a bracketed host is all that can ever arrive —
     *    measured: `[::1` , `[]localhost[]` and `[::1]]` are all rejected with
     *    `SuspiciousOperationException`.
     *  - `parse_url('http://[::1]', PHP_URL_HOST)` keeps them as well and
     *    returns `'[::1]'`; the bare form is PHP garbage, not `'::1'` (it
     *    yields `':'`), which is why the pre-fix comparison list could never
     *    match.
     *
     * Measured consequence of the old comparison list (before
     * `trim($hostname, '[]')` was added to `isLoopback()`):
     *  - `local`, real HTTP request with `Host: [::1]:8000` → **404 on every
     *    route**, i.e. `php artisan serve --host='[::1]'` serves a dead app,
     *    while `localhost` / `127.0.0.1` returned the primary mandant.
     *  - `testing` (console escape hatch) → 200 but
     *    `current_mandant_id === null`: the fallback silently does not apply.
     *  - `production` → 404, unchanged — and correct, because there the fallback
     *    is off for *every* loopback spelling; see
     *    `test_the_loopback_fallback_does_not_apply_in_production`.
     */
    #[DataProvider('ipv6LoopbackUrls')]
    public function test_the_ipv6_loopback_host_arrives_bracketed_and_still_reaches_the_primary(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        $this->assertSame(
            '[::1]',
            $host,
            'precondition: the IPv6 literal arrives bracketed, which is why isLoopback() has to unwrap it before comparing against the bare `::1`',
        );

        $this->assertSame(
            '[::1]',
            Request::create($url)->getHost(),
            'precondition: `Request::getHost()` — the value `resolveHost()` returns — keeps the brackets too',
        );

        $this->mandantWithDomain('verband-a', 'verband-a.test');
        $primary = $this->primaryWithDomain('haupt', 'haupt.test');

        // Same assertions as every other `loopbackHosts` case, so the table
        // above is the single home of the claim; this test only carries the
        // reason the IPv6 rows are not self-evident.
        $this->actingAsApi($this->superAdmin())
            ->getJson($url.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_mandant_id', $primary->id);

        $this->assertTrue(MandantContext::current()?->is($primary));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ipv6LoopbackUrls(): array
    {
        return [
            'IPv6 loopback' => ['http://[::1]'],
            'IPv6 loopback with port' => ['http://[::1]:8000'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* One hostname, one mandant — the invariant behind `value('mandant_id')` */
    /* ------------------------------------------------------------------ */

    /**
     * `MandantContext::resolve()` reads `->where('hostname', $host)->value('mandant_id')`
     * with **no `first()` and no ordering** — on a hostname that existed twice
     * it would silently take whichever row the database happened to return
     * first, i.e. a cross-tenant leak decided by an execution plan.
     *
     * Nothing in the resolver prevents that; the **unique index** does
     * (`2026_08_13_000000_create_mandants_tables.php:43`,
     * `$table->string('hostname')->unique()`, whose docblock says the
     * constraint "doubles as the lookup index for MandantContext::resolve()"),
     * and `MandantDomainController::store()` additionally validates
     * `Rule::unique('mandant_domains', 'hostname')`. This test pins the
     * database-level half of that guarantee so a future migration that drops or
     * loosens the index cannot turn the resolver's un-ordered `value()` into a
     * tenant-isolation hole without a test going red.
     *
     * The violating insert runs in its own `DB::transaction()`: nested inside
     * `RefreshDatabase`'s outer transaction Laravel emits a SAVEPOINT, so the
     * rollback is scoped to the failed insert and the outer transaction stays
     * usable. That is what keeps this portable — on PostgreSQL a unique
     * violation aborts the whole transaction otherwise (SQLSTATE 25P02), and the
     * assertions below would die on "current transaction is aborted" instead of
     * reporting the missing constraint.
     */
    public function test_a_hostname_cannot_belong_to_two_mandants(): void
    {
        $secondary = $this->mandantWithDomain('verband-a', 'verband-a.test');
        $other = Mandant::factory()->create(['slug' => 'verband-b']);

        $thrown = null;

        try {
            DB::transaction(static function () use ($other): void {
                MandantDomain::factory()->for($other)->create(['hostname' => 'verband-a.test']);
            });
        } catch (QueryException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            QueryException::class,
            $thrown,
            'mandant_domains.hostname must be UNIQUE, otherwise resolve()\'s un-ordered ->value(\'mandant_id\') picks an arbitrary tenant',
        );

        $this->assertDatabaseCount('mandant_domains', 1);
        $this->assertSame(
            $secondary->id,
            MandantContext::resolve('verband-a.test')?->id,
            'the surviving row still owns the host',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function mandantWithDomain(string $slug, string $hostname): Mandant
    {
        $mandant = Mandant::factory()->create(['slug' => $slug, 'is_primary' => false]);
        MandantDomain::factory()->for($mandant)->create(['hostname' => $hostname]);

        return $mandant;
    }

    private function primaryWithDomain(string $slug, string $hostname): Mandant
    {
        $mandant = Mandant::factory()->create(['slug' => $slug, 'is_primary' => true]);
        MandantDomain::factory()->for($mandant)->create(['hostname' => $hostname]);

        return $mandant;
    }

    /**
     * The global `super_admin` (no mandant scope) — the same account the
     * frontend uses across every tenant domain, so its `/me` payload is purely
     * a function of the host.
     */
    private function superAdmin(): User
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

    /**
     * Make the middleware stack behave as it does in production.
     *
     * Both halves are required: `skipsUnknownMandant()` is
     * `runningInConsole() || environment('testing')`, and PHPUnit is a console
     * process in the `testing` environment — without this switch an unknown
     * host would sail through instead of 404.
     */
    private function pretendToBeAProductionRequest(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, false);
    }
}

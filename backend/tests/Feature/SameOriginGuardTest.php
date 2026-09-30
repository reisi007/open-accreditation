<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSameOrigin;
use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WP-1-b: `EnsureSameOrigin` — the CSRF defence-in-depth layer for the API.
 *
 * Every state-changing request must carry an `Origin` that belongs to the
 * request's own origin. The middleware is skipped for console/unit-test
 * requests (like Laravel's own `VerifyCsrfToken`), so the strict behaviour is
 * exercised with a production-simulated request (`detectEnvironment()` +
 * `setRunningInConsole(false)`) — the same technique `TrustHostsTest` uses.
 *
 * The requests run WITH a session (`actingAsApi`), which is the realistic CSRF
 * scenario: the attacker's page rides on the victim's cookie. It is also
 * required to reach the guard at all, because `auth:api` is sorted to the
 * front of the pipeline and answers an unauthenticated request with 401 before
 * the group-appended guard runs.
 *
 * Two tenants with real domains are used, because the interesting property is
 * exactly that an `Origin` naming tenant A cannot drive a request to tenant B.
 */
class SameOriginGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'bundesliga.test';

    private const OTHER_TENANT = 'region-alb.test';

    private Mandant $mandant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($this->mandant)->create(['hostname' => self::TENANT]);

        $other = Mandant::factory()->create(['slug' => 'region-alb']);
        MandantDomain::factory()->for($other)->create(['hostname' => self::OTHER_TENANT]);

        $this->user = User::factory()->forMandant($this->mandant)->create(['email' => 'max@example.com']);
        RoleUser::create([
            'user_id' => $this->user->id,
            'role_id' => Role::query()->where('slug', 'user')->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Happy path */
    /* ------------------------------------------------------------------ */

    public function test_same_origin_request_passes(): void
    {
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://'.self::TENANT)
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertOk()
            ->assertCookieExpired(config('jwt.cookie_key_name'));
    }

    public function test_same_origin_request_with_an_explicit_port_passes(): void
    {
        $this->asHttpRequest();

        // A non-standard port on both sides (proxy on :8443): the Origin port
        // has to match the port the request was sent to.
        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://'.self::TENANT.':8443')
            ->postJson('https://'.self::TENANT.':8443/api/auth/logout')
            ->assertOk();
    }

    public function test_same_origin_local_development_origin_passes(): void
    {
        // Vite serves the SPA on http://localhost:5173 and proxies /api to this
        // backend with `changeOrigin: true`, which rewrites the Host header to
        // the dev-server port. The Origin port therefore legitimately differs
        // in dev — `local` is the only environment that tolerates it.
        //
        // This is dev shape #1 (same host, different port). The second shape —
        // a loopback ALIAS, i.e. a different host AND port — is covered by
        // `test_local_dev_proxy_loopback_alias_origin_passes` below, and the
        // port case stays rejected outside `local`
        // (`test_mismatching_port_is_rejected_outside_local`).
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('http://localhost/api/auth/logout')
            ->assertOk();
    }

    public function test_get_requests_are_never_blocked(): void
    {
        $this->asHttpRequest();

        // `GET`/`HEAD` are safe by definition and carry no `Origin` for
        // same-origin requests — the guard must stay out of the way.
        $this->actingAsApi($this->user)
            ->getJson('https://'.self::TENANT.'/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'max@example.com');
    }

    /* ------------------------------------------------------------------ */
    /* Blocked origins */
    /* ------------------------------------------------------------------ */

    public function test_cross_origin_is_rejected_with_403(): void
    {
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://evil.example')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anfrage von fremder Herkunft abgelehnt.');
    }

    public function test_sibling_tenant_origin_is_rejected_with_403(): void
    {
        // The multi-tenant case: a page served from ANOTHER mandant's domain
        // must not be able to drive a state-changing request here — even though
        // that host is a perfectly valid, allow-listed tenant domain.
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://'.self::OTHER_TENANT)
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_missing_origin_is_rejected_with_403(): void
    {
        $this->asHttpRequest();

        // A browser always sends `Origin` on a non-GET/HEAD request, so a
        // request without one is not a browser request — and one that also
        // carries browser fetch metadata (`Sec-Fetch-Site`) is a spoofed
        // browser request. Rejected.
        $this->actingAsApi($this->user)
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anfrage von fremder Herkunft abgelehnt.');
    }

    public function test_strict_mode_rejects_a_missing_origin_from_any_client(): void
    {
        // `security.require_origin_header` closes the non-browser exception:
        // from then on EVERY state-changing API request needs an `Origin`.
        config(['security.require_origin_header' => true]);
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_a_non_browser_api_client_without_origin_is_allowed(): void
    {
        // Documented exception: no `Origin` AND no `Sec-Fetch-*` = not a
        // browser, therefore not a cross-site browser request. This is the
        // signature of the Playwright `APIRequestContext` the E2E suite uses
        // for its setup calls (verified: it sends only accept/host/UA …).
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertOk();
    }

    public function test_empty_origin_is_rejected_with_403(): void
    {
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', '   ')
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_null_origin_is_rejected_with_403(): void
    {
        $this->asHttpRequest();

        // `Origin: null` = sandboxed iframe or `Referrer-Policy: no-referrer`.
        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'null')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_downgraded_scheme_is_rejected_with_403(): void
    {
        $this->asHttpRequest();

        // Same host, but plaintext: a MITM-able origin is NOT this origin.
        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'http://'.self::TENANT)
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_mismatching_port_is_rejected_outside_local(): void
    {
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://'.self::TENANT.':8443')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_unparsable_origins_are_rejected_with_403(): void
    {
        $this->asHttpRequest();

        $origins = [
            'not-a-url',
            '//'.self::TENANT,
            'javascript:alert(1)',
            'https://'.self::TENANT.'@evil.example',
            'https://'.self::TENANT.'/evil',
            'https://'.self::TENANT.', https://evil.example',
        ];

        foreach ($origins as $origin) {
            $this->actingAsApi($this->user)
                ->withHeader('Origin', $origin)
                ->postJson('https://'.self::TENANT.'/api/auth/logout')
                ->assertForbidden('origin: '.$origin);
        }
    }

    public function test_a_blocked_request_never_reaches_the_controller(): void
    {
        $this->asHttpRequest();

        // The guard is a hard stop: neither the JWT is invalidated nor the
        // profile is written.
        $token = auth('api')->login($this->user);

        // Through the ONE channel, and with the singleton dropped — otherwise
        // this 403 would not be evidence about the guard at all. MEASURED: with
        // the cookie call deleted entirely the request still answered 403,
        // because `auth('api')->login()` had left the token in the
        // process-global `JWT::$token` and the guard resolved the user from
        // there. The 403 was the singleton, not the origin check. With the
        // channel the same probe answers 401 (`auth:api` runs before the
        // group-appended guard — see `EnsureSameOrigin`'s docblock), which is
        // what makes the 403 below attributable to the origin mismatch.
        $this->withJwtCookie($token);
        $this->forgetJwtAuthState();

        $this->assertFalse(
            $this->inMemoryJwtTokenIsSet(),
            'PREMISE: the request must be authenticated through the cookie channel, not out of process-global state.'
        );

        $this->withHeader('Origin', 'https://'.self::OTHER_TENANT)
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();

        // `setToken()` is the explicit, non-memoised proof that the blocked
        // request invalidated nothing — the token itself still authenticates.
        $this->assertNotNull(auth('api')->setToken($token)->authenticate());
    }

    /* ------------------------------------------------------------------ */
    /* The `local` dev-proxy exception (Vite `changeOrigin: true`) */
    /* ------------------------------------------------------------------ */

    public function test_local_dev_proxy_loopback_alias_origin_passes(): void
    {
        // The CI shape that blocked the whole local stack with a 403 on
        // `POST /api/auth/login`: `VITE_API_PROXY=http://127.0.0.1:8000` plus
        // `changeOrigin: true` makes the Vite proxy rewrite `Host` to
        // `127.0.0.1:8000`, while the browser still sends
        // `Origin: http://localhost:5173`. Host AND port differ — two spellings
        // of the one loopback interface, which `local` accepts.
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('http://127.0.0.1:8000/api/auth/logout')
            ->assertOk()
            ->assertCookieExpired(config('jwt.cookie_key_name'));
    }

    public function test_local_dev_proxy_loopback_alias_origin_logs_in(): void
    {
        // The exact reported symptom was the 403 on the session-ESTABLISHING
        // route, so that route is pinned explicitly: the dev-proxy exception is
        // not a route exemption and must not be mistaken for one.
        $this->asLocalHttpRequest();
        $this->routeLoopbackHostToTenant();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('http://127.0.0.1:8000/api/auth/login', [
                'email' => 'max@example.com',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));
    }

    #[DataProvider('loopbackAliasProvider')]
    public function test_local_accepts_every_loopback_spelling(string $origin, string $uri): void
    {
        // The rule is "loopback <-> loopback", not "localhost <-> 127.0.0.1":
        // every loopback spelling of either side is the same interface, with or
        // without an explicit port.
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', $origin)
            ->postJson($uri.'/api/auth/logout')
            ->assertOk();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function loopbackAliasProvider(): array
    {
        return [
            'CI shape: localhost origin, 127.0.0.1 request' => ['http://localhost:5173', 'http://127.0.0.1:8000'],
            'reverse direction: 127.0.0.1 origin, localhost request' => ['http://127.0.0.1:5173', 'http://localhost:8000'],
            'both sides identical, port differs' => ['http://127.0.0.1:5173', 'http://127.0.0.1:8000'],
            'origin without an explicit port' => ['http://localhost', 'http://127.0.0.1:8000'],
            'IPv6 loopback origin' => ['http://[::1]:5173', 'http://127.0.0.1:8000'],
            'expanded IPv6 loopback origin' => ['http://[0:0:0:0:0:0:0:1]:5173', 'http://127.0.0.1:8000'],
            'IPv4-mapped IPv6 loopback origin' => ['http://[::ffff:127.0.0.1]:5173', 'http://127.0.0.1:8000'],
            'the whole 127.0.0.0/8 block is loopback' => ['http://127.0.0.2:5173', 'http://127.0.0.1:8000'],
        ];
    }

    #[DataProvider('foreignOriginProvider')]
    public function test_a_foreign_origin_is_still_rejected_in_local(string $origin): void
    {
        // The security boundary of the dev exception: a NON-loopback `Origin`
        // is rejected in `local` too. `local` is the only environment that
        // tolerates a host difference, and only between two loopback hosts.
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', $origin)
            ->postJson('http://127.0.0.1:8000/api/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anfrage von fremder Herkunft abgelehnt.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignOriginProvider(): array
    {
        return [
            // The reported case: https origin against a loopback request host.
            'https origin, loopback request host' => ['https://evil.example'],
            // Same scheme, so the HOST/loopback check is provably the rejecting
            // step here and not incidentally the scheme check.
            'http origin, loopback request host' => ['http://evil.example'],
            'a sibling tenant host' => ['https://'.self::OTHER_TENANT],
            'a public name that merely starts with the loopback literal' => ['http://127.0.0.1.evil.example'],
            'a public name that merely ends with the loopback literal' => ['http://evil.example.localhost.attacker.test'],
            'a LAN address' => ['http://192.168.1.10:5173'],
            'a routable public address' => ['http://203.0.113.7:5173'],
        ];
    }

    public function test_local_does_not_turn_a_loopback_origin_into_a_wildcard(): void
    {
        // The regression this rule must NOT introduce — "any host difference is
        // fine in dev". A loopback `Origin` may not drive a request to a real
        // tenant domain, not even in `local`: the request host has to be
        // loopback as well, so no attacker origin can be re-anchored onto a
        // deployed mandant site.
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anfrage von fremder Herkunft abgelehnt.');
    }

    public function test_loopback_origin_is_rejected_outside_local(): void
    {
        // The alias exception is gated on the ENVIRONMENT. In
        // staging/production a loopback `Origin` is as foreign as any other —
        // the dev exception cannot be reached by a same-origin port that happens
        // to look like the Vite setup.
        $this->asHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('https://'.self::TENANT.'/api/auth/logout')
            ->assertForbidden();
    }

    public function test_strict_mode_closes_the_non_browser_exception_in_local_as_well(): void
    {
        // `REQUIRE_ORIGIN_HEADER=true` is orthogonal to the dev exception: a
        // bare first-party client (no `Origin`, no `Sec-Fetch-*`) stays
        // rejected on the local dev origin too.
        config(['security.require_origin_header' => true]);
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->postJson('http://127.0.0.1:8000/api/auth/logout')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anfrage von fremder Herkunft abgelehnt.');
    }

    public function test_strict_mode_does_not_break_the_local_dev_proxy_alias(): void
    {
        // The mirror image: `REQUIRE_ORIGIN_HEADER` only tightens the MISSING
        // `Origin` case. With an `Origin` present it changes nothing, so the Vite
        // dev proxy keeps working in a strict deployment.
        config(['security.require_origin_header' => true]);
        $this->asLocalHttpRequest();

        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('http://127.0.0.1:8000/api/auth/logout')
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* `_method` override — the cross-site form vector */
    /* ------------------------------------------------------------------ */

    public function test_form_encoded_method_override_with_foreign_origin_is_rejected(): void
    {
        $this->asHttpRequest();

        // Symfony's method override is enabled by the HTTP kernel, so a
        // cross-site HTML form (`enctype=application/x-www-form-urlencoded`)
        // can reach a PUT route with a plain POST. `$request->method()` reports
        // the EFFECTIVE method, so the guard still evaluates the PUT — the
        // override cannot be used to slip past.
        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://evil.example')
            ->post('https://'.self::TENANT.'/api/user/profile', [
                '_method' => 'PUT',
                'city' => 'Hamburg',
            ])
            ->assertForbidden();

        $this->assertNull($this->user->fresh()->city);
    }

    public function test_form_encoded_method_override_passes_on_the_same_origin(): void
    {
        $this->asHttpRequest();

        // Counterpart to the test above: the block is caused by the origin and
        // not by the `_method` override itself.
        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://'.self::TENANT)
            ->post('https://'.self::TENANT.'/api/user/profile', [
                '_method' => 'PUT',
                'city' => 'Hamburg',
            ])
            ->assertOk();

        $this->assertSame('Hamburg', $this->user->fresh()->city);
    }

    public function test_method_override_header_with_foreign_origin_is_rejected(): void
    {
        $this->asHttpRequest();

        // `X-HTTP-Method-Override` is the second override channel Symfony
        // honours, and it is honoured during route matching — so the guard sees
        // the effective PUT.
        $this->actingAsApi($this->user)
            ->withHeader('Origin', 'https://evil.example')
            ->withHeader('X-HTTP-Method-Override', 'PUT')
            ->post('https://'.self::TENANT.'/api/user/profile', ['city' => 'Hamburg'])
            ->assertForbidden();

        $this->assertNull($this->user->fresh()->city);
    }

    /* ------------------------------------------------------------------ */
    /* Session-establishing routes are NOT exempt */
    /* ------------------------------------------------------------------ */

    public function test_login_with_a_cross_site_origin_is_rejected(): void
    {
        $this->asHttpRequest();

        // Login/register ESTABLISH the session and need no existing cookie, so
        // `SameSite=Lax` does not protect them — an exemption was a pure
        // weakening. Before it was removed this answered 200 (and register 201):
        // a cross-site form signs the victim into the attacker's account, and
        // every document the victim uploads from then on is readable there.
        $this->withHeader('Origin', 'https://evil.example')
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->postJson('https://'.self::TENANT.'/api/auth/login', [
                'email' => 'max@example.com',
                'password' => 'password',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anfrage von fremder Herkunft abgelehnt.');

        $this->assertGuest();
    }

    public function test_register_with_a_cross_site_origin_is_rejected(): void
    {
        $this->asHttpRequest();

        $this->withHeader('Origin', 'https://evil.example')
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->postJson('https://'.self::TENANT.'/api/auth/register', [
                'name' => 'Max Mustermann',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_login_from_a_same_origin_browser_passes(): void
    {
        $this->asHttpRequest();

        // Counterpart: the block above is caused by the foreign origin, not by
        // login being a state-changing route.
        $this->withHeader('Origin', 'https://'.self::TENANT)
            ->postJson('https://'.self::TENANT.'/api/auth/login', [
                'email' => 'max@example.com',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertCookie(config('jwt.cookie_key_name'));
    }

    public function test_login_from_a_non_browser_api_client_without_origin_passes(): void
    {
        $this->asHttpRequest();

        // No `Origin` and no `Sec-Fetch-*` = a first-party non-browser client
        // (curl, a mobile app, the Playwright `APIRequestContext` the E2E suite
        // uses for setup calls), which cannot ride the victim's cookie jar.
        // This is the documented `isBrowserRequest()` exception, not a route
        // exemption.
        $this->postJson('https://'.self::TENANT.'/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_register_from_a_non_browser_api_client_without_origin_passes(): void
    {
        // The subject is the Origin guard, not the mail. Registration sends an
        // `ActivationMail`, and without this fake the send really dials
        // `MAIL_HOST:MAIL_PORT` as pinned in `phpunit.xml`; a refused connection
        // becomes a 500 and the test's result depended on whether a mail catcher
        // happened to be listening. MEASURED: 500 instead of 201 with nothing on
        // 127.0.0.1:1025. The sibling `RoleUserScopeUniqueTest::test_the_
        // registration_endpoint_never_leaves_a_second_role_user_row()` had the
        // same ambient dependency.
        Mail::fake();

        $this->asHttpRequest();

        $this->postJson('https://'.self::TENANT.'/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }

    public function test_strict_mode_also_requires_an_origin_on_login(): void
    {
        // `security.require_origin_header` closes the non-browser exception, so
        // even a plain API client must present one.
        config(['security.require_origin_header' => true]);
        $this->asHttpRequest();

        $this->postJson('https://'.self::TENANT.'/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_activate_is_a_safe_method_and_never_blocked(): void
    {
        $this->asHttpRequest();

        // `GET` + unknown token: the guard is inert for safe methods, and the
        // route needs no exemption for that.
        $this->getJson('https://'.self::TENANT.'/api/auth/activate/unknown-token')
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Registration + console/unit-test escape hatch */
    /* ------------------------------------------------------------------ */

    public function test_guard_is_registered_on_the_api_group(): void
    {
        $groups = app(Kernel::class)->getMiddlewareGroups();

        $this->assertArrayHasKey('api', $groups);
        $this->assertContains(EnsureSameOrigin::class, $groups['api']);
    }

    public function test_guard_is_inert_for_console_and_unit_test_requests(): void
    {
        // Every other feature test in the suite issues bare POST/PUT/DELETE
        // requests without an `Origin`; this documents that contract.
        $this->postJson('/api/auth/login', [
            'email' => 'max@example.com',
            'password' => 'password',
        ])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Simulate a real HTTP request in production: the guard (like Laravel's
     * `VerifyCsrfToken`) skips console and unit-test requests, so both
     * conditions have to be lifted.
     */
    private function asHttpRequest(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);
    }

    /**
     * Same technique as `asHttpRequest()`, but simulating a real HTTP request in
     * the `local` environment — the only one in which the Vite dev-proxy
     * exception applies.
     */
    private function asLocalHttpRequest(): void
    {
        app()->detectEnvironment(fn () => 'local');
        $this->setRunningInConsole(false);
    }

    /**
     * Make the loopback dev host (`127.0.0.1`, the Vite proxy target) resolve
     * to this test's mandant through the ordinary `mandant_domains` lookup, so a
     * mandant-scoped route resolves deterministically instead of relying on the
     * primary/fallback mandant.
     */
    private function routeLoopbackHostToTenant(): void
    {
        MandantDomain::factory()->for($this->mandant)->create(['hostname' => '127.0.0.1']);
    }

    private function setRunningInConsole(bool $value): void
    {
        $property = new \ReflectionProperty(Application::class, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $value);
    }
}

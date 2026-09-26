<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

/**
 * B3: the trustHosts allow-list is built from `mandant_domains.hostname` plus
 * safe local/dev defaults. Symfony validates every request Host against it and
 * rejects foreign hosts with a 400 before any mandant logic runs; hosts on the
 * allow-list but owned by no mandant still 404 via MandantContextMiddleware.
 */
class TrustHostsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Production-simulated requests activate the TrustHosts middleware,
        // which sets Symfony's static trusted-host patterns — reset them so
        // they cannot leak into later tests.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_allow_list_includes_db_domains_and_defaults(): void
    {
        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        $hosts = app(TrustHosts::class)->hosts();

        $this->assertContains(preg_quote('bundesliga.test', '{}'), $hosts);
        $this->assertContains('localhost', $hosts);
        $this->assertContains('127.0.0.1', $hosts);
        $this->assertContains('^\[::1\]$', $hosts);
        $this->assertContains('^(.+\.)?test$', $hosts);
        $this->assertContains('^(.+\.)?localhost$', $hosts);
    }

    public function test_allow_list_is_defensive_with_empty_database(): void
    {
        $hosts = app(TrustHosts::class)->hosts();

        $this->assertContains('localhost', $hosts);
        $this->assertContains('^(.+\.)?test$', $hosts);
    }

    public function test_allow_listed_db_host_passes_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga', 'is_active' => true]);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        $this->get('http://bundesliga.test/')->assertOk();

        $this->assertTrue(MandantContext::current()?->is($bundesliga));
    }

    public function test_foreign_host_is_rejected_with_400_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $this->get('http://evil.example/')->assertStatus(400);
    }

    public function test_wildcard_test_domain_is_not_allow_listed_in_production(): void
    {
        // WP-1-d: the dev wildcards (`^(.+\.)?test$`, `^(.+\.)?localhost$`) are
        // only shipped OUTSIDE production. In production `foo.test` is not on
        // the allow-list at all, so Symfony rejects it with 400 before the
        // mandant resolution is even attempted.
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $this->get('http://foo.test/')->assertStatus(400);
    }

    public function test_allow_listed_but_mandantless_host_404s_outside_production(): void
    {
        // Outside production `*.test` IS allow-listed, so an unowned host
        // reaches MandantContextMiddleware and gets its 404 — the second layer
        // behind the allow-list still works. `staging` (not `production`, so
        // the dev wildcards ship) with a non-console request, so neither
        // middleware takes its console/test escape hatch.
        app()->detectEnvironment(fn () => 'staging');
        $this->setRunningInConsole(false);

        $this->get('http://foo.test/')->assertStatus(404);
    }

    public function test_health_endpoint_is_untouched_by_trust_hosts_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $this->get('http://127.0.0.1/up')->assertOk();
    }

    public function test_subdomains_of_the_app_url_host_are_not_implicitly_trusted_in_production(): void
    {
        // WF-2-c: `trustHosts()` was called with the default `$subdomains =
        // true`, so the framework appended `^(.+\.)?<APP_URL host>$` to the
        // allow-list in production as well — a wildcard nobody declared,
        // exactly the class rules 1-3 above exclude. `app.url` is pinned so
        // the assertion does not depend on the developer's local `.env`, and
        // the host is deliberately NOT a mandant domain, so the only thing
        // that could trust it is the APP_URL expansion.
        config(['app.url' => 'https://app-url-host.example']);

        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $hosts = app(TrustHosts::class)->hosts();

        $this->assertNotContains('^(.+\.)?app\-url\-host\.example$', $hosts, 'no APP_URL subdomain wildcard');
        $this->assertNotContains('app\-url\-host\.example', $hosts, 'the APP_URL host is not trusted implicitly either');

        // A subdomain of the APP_URL host is a foreign host: Symfony rejects
        // it with a 400, exactly like any other undeclared host. Before the
        // fix the framework-appended wildcard answered 200 here (bounded:
        // only `/up` escaped the MandantContext 404).
        $this->get('http://sub.app-url-host.example/up')->assertStatus(400);
    }

    private function setRunningInConsole(bool $value): void
    {
        $reflection = new \ReflectionClass($this->app);
        $property = $reflection->getProperty('isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $value);
    }
}

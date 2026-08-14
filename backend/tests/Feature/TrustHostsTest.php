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

    public function test_wildcard_test_domain_passes_trust_hosts_but_404s_without_mandant(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        // `foo.test` is allow-listed via `^(.+\.)?test$`, but no mandant owns
        // it → the MandantContextMiddleware unknown-host 404 still applies.
        $this->get('http://foo.test/')->assertStatus(404);
    }

    public function test_health_endpoint_is_untouched_by_trust_hosts_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $this->get('http://127.0.0.1/up')->assertOk();
    }

    private function setRunningInConsole(bool $value): void
    {
        $reflection = new \ReflectionClass($this->app);
        $property = $reflection->getProperty('isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $value);
    }
}

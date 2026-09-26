<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Support\MandantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

/**
 * WP-1-d: the `Host` allow-list in `bootstrap/app.php`.
 *
 * Three properties are enforced here: the dev wildcards never ship in
 * production, the static part of the list is env-gateable via
 * `TRUSTED_HOSTS`, and a database outage can no longer silently degrade the
 * allow-list to a list without a single tenant domain (which turned every
 * request to every real tenant into a 400 for the duration of the outage).
 */
class TrustedHostsConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MandantContext::forgetHostnames();
    }

    protected function tearDown(): void
    {
        // Production-simulated requests activate the TrustHosts middleware,
        // which sets Symfony's static trusted-host patterns.
        Request::setTrustedHosts([]);
        MandantContext::forgetHostnames();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Dev wildcards */
    /* ------------------------------------------------------------------ */

    public function test_dev_wildcards_are_allow_listed_outside_production(): void
    {
        $hosts = $this->allowList(env: 'staging');

        $this->assertContains('^(.+\.)?test$', $hosts);
        $this->assertContains('^(.+\.)?localhost$', $hosts);
    }

    public function test_dev_wildcards_are_not_allow_listed_in_production(): void
    {
        $hosts = $this->allowList(env: 'production');

        $this->assertNotContains('^(.+\.)?test$', $hosts);
        $this->assertNotContains('^(.+\.)?localhost$', $hosts);
    }

    public function test_loopback_defaults_stay_allow_listed_in_production(): void
    {
        // Container health probes and `docker exec` checks come from loopback
        // and must not be locked out by the hardening.
        $hosts = $this->allowList(env: 'production');

        $this->assertContains('127.0.0.1', $hosts);
        $this->assertContains('localhost', $hosts);
        $this->assertContains('^\[::1\]$', $hosts);
    }

    public function test_dead_port_pattern_is_gone(): void
    {
        // `getHost()` strips the port, so `localhost:5173` could never match —
        // it only looked like documentation.
        foreach (['staging', 'production'] as $environment) {
            $this->assertNotContains('localhost:5173', $this->allowList(env: $environment));
        }
    }

    /* ------------------------------------------------------------------ */
    /* env gate */
    /* ------------------------------------------------------------------ */

    public function test_trusted_hosts_env_replaces_the_dev_wildcards_but_keeps_loopback(): void
    {
        config(['security.trusted_hosts' => '^intranet\.example\.com$,  ^ops\.example\.com$']);

        $hosts = $this->allowList(env: 'production');

        $this->assertContains('^intranet\.example\.com$', $hosts);
        $this->assertContains('^ops\.example\.com$', $hosts);
        $this->assertNotContains('^(.+\.)?test$', $hosts);
        $this->assertNotContains('^(.+\.)?localhost$', $hosts);

        // Loopback is merged in unconditionally: a `TRUSTED_HOSTS` value is
        // about which *static non-loopback* hosts are allowed, and dropping
        // 127.0.0.1 would answer 400 to every container health probe / LB
        // check on `GET /up` — the container then restarts in a loop, in
        // exactly the hardened configuration a non-empty `TRUSTED_HOSTS`
        // produces.
        $this->assertContains('localhost', $hosts);
        $this->assertContains('127.0.0.1', $hosts);
        $this->assertContains('^\[::1\]$', $hosts);
    }

    public function test_a_configured_trusted_hosts_value_never_drops_loopback_in_any_environment(): void
    {
        foreach (['staging', 'production'] as $environment) {
            config(['security.trusted_hosts' => '^intranet\.example\.com$']);

            $hosts = $this->allowList(env: $environment);

            $this->assertContains('127.0.0.1', $hosts, $environment);
            $this->assertContains('localhost', $hosts, $environment);
            $this->assertContains('^\[::1\]$', $hosts, $environment);
        }
    }

    public function test_the_health_endpoint_stays_reachable_with_a_configured_trusted_hosts_value(): void
    {
        config(['security.trusted_hosts' => '^intranet\.example\.com$']);

        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        // The Docker `HEALTHCHECK` / LB probe path. Before the loopback union
        // this answered 400 with a non-empty `TRUSTED_HOSTS`.
        $this->get('http://127.0.0.1/up')->assertOk();
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_a_configured_trusted_hosts_value_is_still_enforced(): void
    {
        config(['security.trusted_hosts' => '^intranet\.example\.com$']);

        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $this->get('http://evil.example/up')->assertStatus(400);
    }

    public function test_trusted_hosts_env_is_merged_with_the_mandant_domains(): void
    {
        config(['security.trusted_hosts' => '^intranet\.example\.com$']);

        $mandant = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($mandant)->create(['hostname' => 'bundesliga.test']);

        $hosts = $this->allowList(env: 'production');

        $this->assertContains('^intranet\.example\.com$', $hosts);
        $this->assertContains(preg_quote('bundesliga.test', '{}'), $hosts);
    }

    /* ------------------------------------------------------------------ */
    /* Database dependency */
    /* ------------------------------------------------------------------ */

    public function test_the_domain_lookup_is_cached(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($mandant)->create(['hostname' => 'bundesliga.test']);

        $this->assertContains(preg_quote('bundesliga.test', '{}'), $this->allowList(env: 'staging'));
        $this->assertNotNull(Cache::get(MandantContext::HOSTNAMES_CACHE_KEY), 'precondition: warm cache');

        // A domain added behind the cache's back must NOT show up — that is the
        // point of the cache (one `pluck` per TTL instead of per request).
        MandantDomain::factory()->for($mandant)->create(['hostname' => 'later.test']);

        $this->assertNotContains(preg_quote('later.test', '{}'), $this->allowList(env: 'staging'));

        MandantContext::forgetHostnames();

        $this->assertContains(preg_quote('later.test', '{}'), $this->allowList(env: 'staging'));
    }

    public function test_a_database_outage_keeps_serving_from_the_warm_cache(): void
    {
        $mandant = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($mandant)->create(['hostname' => 'bundesliga.test']);

        $hosts = $this->allowList(env: 'production');
        $this->assertContains(preg_quote('bundesliga.test', '{}'), $hosts);

        // Warm cache + no table: a transient DB blip must not turn every
        // request to every real tenant into a 400.
        Schema::drop('mandant_domains');
        MandantContext::forgetHostnames();
        Cache::put(MandantContext::HOSTNAMES_CACHE_KEY, ['bundesliga.test'], 3600);

        $this->assertContains(preg_quote('bundesliga.test', '{}'), $this->allowList(env: 'production'));
    }

    public function test_a_cold_database_outage_fails_loudly_in_production(): void
    {
        Log::spy();

        Schema::drop('mandant_domains');
        MandantContext::forgetHostnames();

        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        // A 500, never a silent 400-for-every-tenant. The old code swallowed
        // the exception and served an allow-list without a single real domain.
        $this->get('http://bundesliga.test/')->assertStatus(500);

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message): bool => str_contains($message, 'mandant domain list is unavailable')
        );
    }

    public function test_a_database_outage_keeps_the_dev_defaults_outside_production(): void
    {
        Schema::drop('mandant_domains');
        MandantContext::forgetHostnames();

        // Console context: the mandant middleware continues without a mandant,
        // and the allow-list falls back to the dev defaults (install/first
        // boot) instead of aborting.
        $hosts = $this->allowList(env: 'staging');

        $this->assertContains('localhost', $hosts);
        $this->assertContains('^(.+\.)?test$', $hosts);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The allow-list the `trustHosts()` callback produces for the given
     * environment.
     *
     * @return array<int, string>
     */
    private function allowList(string $env): array
    {
        app()->detectEnvironment(fn () => $env);

        return app(TrustHosts::class)->hosts();
    }

    private function setRunningInConsole(bool $value): void
    {
        $property = new \ReflectionProperty(Application::class, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $value);
    }
}

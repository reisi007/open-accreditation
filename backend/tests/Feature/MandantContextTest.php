<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Models\MandantDomain;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MandantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        MandantContext::reset();
    }

    public function test_resolve_finds_mandant_by_hostname(): void
    {
        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga', 'name' => 'Bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        $mandant = MandantContext::resolve('bundesliga.test');

        $this->assertNotNull($mandant);
        $this->assertTrue($mandant->is($bundesliga));
    }

    public function test_resolve_is_case_insensitive_and_trims_whitespace(): void
    {
        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        $mandant = MandantContext::resolve('  Bundesliga.TEST ');

        $this->assertNotNull($mandant);
        $this->assertTrue($mandant->is($bundesliga));
    }

    public function test_resolve_returns_null_for_unknown_host(): void
    {
        $this->assertNull(MandantContext::resolve('unknown.invalid'));
    }

    public function test_resolve_ignores_inactive_mandants(): void
    {
        $inactive = Mandant::factory()->create(['is_active' => false]);
        MandantDomain::factory()->for($inactive)->create(['hostname' => 'inactive.test']);

        $this->assertNull(MandantContext::resolve('inactive.test'));
    }

    public function test_resolve_is_cached_and_queries_the_domain_only_once(): void
    {
        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        DB::enableQueryLog();

        $first = MandantContext::resolve('bundesliga.test');
        $second = MandantContext::resolve('bundesliga.test');

        $domainQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains((string) $query['query'], 'mandant_domains'))
            ->count();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($bundesliga->id, $first->id);
        $this->assertSame($bundesliga->id, $second->id);
        $this->assertSame(1, $domainQueries);
    }

    public function test_forget_host_clears_the_resolve_cache(): void
    {
        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        MandantContext::resolve('bundesliga.test');
        MandantContext::forgetHost('bundesliga.test');

        DB::enableQueryLog();

        MandantContext::resolve('bundesliga.test');

        $domainQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains((string) $query['query'], 'mandant_domains'))
            ->count();

        $this->assertSame(1, $domainQueries);
    }

    public function test_middleware_sets_current_mandant_for_known_host(): void
    {
        $bundesliga = Mandant::factory()->create(['slug' => 'bundesliga', 'name' => 'Bundesliga']);
        MandantDomain::factory()->for($bundesliga)->create(['hostname' => 'bundesliga.test']);

        $this->get('http://bundesliga.test/')->assertOk();

        $this->assertTrue(MandantContext::current()?->is($bundesliga));
    }

    public function test_middleware_continues_without_mandant_for_unknown_host_in_testing(): void
    {
        $this->get('http://unknown.invalid/')->assertOk();

        $this->assertNull(MandantContext::current());
    }

    public function test_unknown_host_returns_404_in_http_context(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->setRunningInConsole(false);

        $this->get('http://unknown.invalid/')->assertNotFound();
    }

    public function test_default_returns_the_primary_mandant(): void
    {
        Mandant::factory()->create(['slug' => 'bundesliga', 'is_primary' => false]);
        $main = Mandant::factory()->create(['slug' => 'main', 'is_primary' => true]);

        $this->assertTrue(MandantContext::default()?->is($main));
    }

    public function test_default_ignores_inactive_primary_and_falls_back_to_config_slug(): void
    {
        config(['mandants.fallback_mandant' => 'fallback']);

        Mandant::factory()->create(['slug' => 'main', 'is_primary' => true, 'is_active' => false]);
        $fallback = Mandant::factory()->create(['slug' => 'fallback']);

        $this->assertTrue(MandantContext::default()?->is($fallback));
    }

    public function test_for_current_mandant_scope_filters_domains_by_current_mandant(): void
    {
        $mandantA = Mandant::factory()->create();
        $mandantB = Mandant::factory()->create();

        $domainA = MandantDomain::factory()->for($mandantA)->create(['hostname' => 'a.test']);
        MandantDomain::factory()->for($mandantB)->create(['hostname' => 'b.test']);

        MandantContext::set($mandantA);

        $domains = MandantDomain::forCurrentMandant()->get();

        $this->assertCount(1, $domains);
        $this->assertTrue($domains->first()->is($domainA));
    }

    public function test_for_current_mandant_scope_returns_empty_without_current_mandant(): void
    {
        Mandant::factory()->create();
        MandantDomain::factory()->create(['hostname' => 'a.test']);

        $this->assertCount(0, MandantDomain::forCurrentMandant()->get());
    }

    private function setRunningInConsole(bool $value): void
    {
        $reflection = new \ReflectionClass($this->app);
        $property = $reflection->getProperty('isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $value);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Support\MandantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A3 / `features/02-domain-model.md`: EVERY hostname-writing path must
 * invalidate the `MandantContext` caches — the seeder is the one such path that
 * is not a controller.
 *
 * Two caches are in play, and both are stale-tolerant by design:
 *
 *  1. `MandantContext::hostnames()` — the whole hostname list behind the
 *     `trustHosts` allow-list, cached for `mandants.cache_ttl` (3600 s). A host
 *     that is not on that list is rejected with a 400 BEFORE any mandant
 *     lookup runs, so a newly seeded host is unreachable for up to an hour
 *     behind a *healthy* deployment.
 *  2. `MandantContext::resolve()` — the per-host mapping, which also caches a
 *     negative `MISSING` sentinel for `NEGATIVE_CACHE_TTL_SECONDS` (60 s). A
 *     host looked up before it was seeded keeps resolving to "no mandant" even
 *     though the row is right there.
 *
 * The database was never the bug in either case — these tests therefore assert
 * on the CACHE, not on `mandant_domains`.
 */
class SeederHostnameCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `CACHE_STORE=array` is per-process, but the seeder touches exactly the
        // two keys under test — start every test from a cold allow-list so an
        // entry cannot survive from a previous test method.
        MandantContext::forgetHostnames();
        MandantContext::reset();
    }

    protected function tearDown(): void
    {
        MandantContext::forgetHostnames();
        MandantContext::reset();

        parent::tearDown();
    }

    public function test_a_reseed_drops_the_cached_hostname_allow_list(): void
    {
        $this->givenADevDatabaseThatPredatesTheSeededHostnames();

        // The allow-list the running server already computed for that state.
        $this->assertSame(['localhost'], MandantContext::hostnames());
        $this->assertNotNull(
            Cache::get(MandantContext::HOSTNAMES_CACHE_KEY),
            'precondition: warm hostname-list cache',
        );

        // The local re-seed next to that running server: `db:seed --force`,
        // `scripts/e2e-up.sh`, or a deploy with `RUN_SEEDER=true`.
        app(DatabaseSeeder::class)->run();

        // Not the database — the database is right in both states. The cache is
        // the defect: while it holds `['localhost']`, every request to
        // `accreditation.test` is answered 400 for up to an hour.
        $this->assertNull(
            Cache::get(MandantContext::HOSTNAMES_CACHE_KEY),
            'the seeder writes hostnames, so it must drop the cached allow-list like every other hostname-writing path',
        );

        $hostnames = MandantContext::hostnames();

        $this->assertIsArray($hostnames, 'the reseeded hostname list must be re-readable');
        $this->assertContains('accreditation.test', $hostnames);
        $this->assertContains('www.accreditation.test', $hostnames);
        $this->assertContains('bundesliga.test', $hostnames);
        $this->assertContains('www.bundesliga.test', $hostnames);
    }

    public function test_a_reseed_clears_the_negative_cache_of_a_previously_unresolved_host(): void
    {
        $this->givenADevDatabaseThatPredatesTheSeededHostnames();

        // A request to a host that does not exist yet caches a negative entry —
        // the W-6 negative lookup, here for `bundesliga.test`.
        $this->assertNull(MandantContext::resolve('bundesliga.test'));
        $this->assertSame(
            MandantContext::MISSING,
            Cache::get('mandant.domain.bundesliga.test'),
            'precondition: the negative sentinel is cached',
        );

        app(DatabaseSeeder::class)->run();

        $this->assertNull(
            Cache::get('mandant.domain.bundesliga.test'),
            'a freshly seeded host must not keep answering from the negative cache',
        );
        $this->assertSame(
            'bundesliga',
            MandantContext::resolve('bundesliga.test')?->slug,
            'the seeded host must resolve to its mandant immediately, without waiting out the negative-cache TTL',
        );
    }

    public function test_the_seeder_stays_idempotent_with_the_invalidation_in_place(): void
    {
        app(DatabaseSeeder::class)->run();

        $first = MandantContext::hostnames();

        // Re-running must not duplicate rows … and must not leave the allow-list
        // dropped forever either: the second run re-warms it.
        app(DatabaseSeeder::class)->run();

        $this->assertSame($first, MandantContext::hostnames());
        $this->assertDatabaseCount('mandant_domains', 5);
    }

    /**
     * The state a long-lived dev DB is in before the Herd hostnames were ever
     * seeded: a `main` mandant with just the `localhost` domain, no
     * `bundesliga` mandant. Everything the seeder adds is then a NEW host,
     * which is exactly the case the caches get wrong.
     */
    private function givenADevDatabaseThatPredatesTheSeededHostnames(): void
    {
        $main = Mandant::factory()->create([
            'slug' => 'main',
            'name' => 'Hauptseite',
            'is_primary' => true,
        ]);

        $main->domains()->create(['hostname' => 'localhost']);
    }
}

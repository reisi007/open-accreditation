<?php

namespace Tests\Feature;

use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\QrTokenService;
use App\Support\MandantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WF-1-b: the QR token issuance of a bulk allocation must not be an N+1.
 *
 * `AllocationService::issueQrTokens()` bulk-loads the applications that were
 * just approved, but the v2 QR token carries the mandant id of the
 * application's accreditation, and `QrTokenService::mandantIdOf()` reads it from
 * the relation. Without `with('accreditation:id,mandant_id')` every single row
 * lazy-loads its accreditation: one extra `select * from accreditations where
 * id = ?` per approved application (measured before the fix: 10 approvals ⇒ 38
 * queries, 10 of them single-row accreditation lookups; 500 badges ⇒ 500 extra
 * round-trips).
 *
 * The test pins the *shape* of the queries rather than a magic total, so it
 * cannot become flaky while the surrounding allocation path grows.
 */
class AllocationQrTokenQueryTest extends TestCase
{
    use RefreshDatabase;

    private static int $categorySeq = 0;

    private AllocationService $allocation;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocation = app(AllocationService::class);
        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_a_bulk_allocation_never_looks_an_accreditation_up_row_by_row(): void
    {
        $approved = 6;
        $accreditation = $this->accreditationWithApplicants($approved);

        $queries = $this->recordQueries(fn () => $this->allocation->approveAllEligible($accreditation));

        // The token really was issued for every approved row …
        $tokens = Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->pluck('qr_token', 'id');

        $this->assertCount($approved, $tokens);
        foreach ($tokens as $token) {
            $this->assertIsString($token);
            $this->assertNotNull(app(QrTokenService::class)->parse($token));
        }

        // … without a single-row `accreditations` lookup. Any such query is a
        // lazy load of the relation the bulk query is supposed to eager-load.
        $rowByRow = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'from "accreditations"')
                && str_contains($query['sql'], '"id" = ?'),
        ));

        $this->assertSame([], $rowByRow, implode(PHP_EOL, array_column($rowByRow, 'sql')));
    }

    public function test_the_number_of_accreditation_queries_does_not_grow_with_the_approval_count(): void
    {
        $small = $this->accreditationQueriesFor(2);
        $large = $this->accreditationQueriesFor(8);

        // A relation that is eager-loaded costs one query per bulk, not one per
        // row: the count must be identical, not 4× larger.
        $this->assertSame(
            $small,
            $large,
            sprintf('accreditation queries: %d for 2 approvals, %d for 8 approvals', $small, $large),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * How many `accreditations` queries a bulk approval of `$count`
     * applications performs.
     */
    private function accreditationQueriesFor(int $count): int
    {
        $accreditation = $this->accreditationWithApplicants($count);

        $queries = $this->recordQueries(fn () => $this->allocation->approveAllEligible($accreditation));

        $this->assertSame(
            $count,
            Application::query()->where('accreditation_id', $accreditation->id)->where('status', 'approved')->count(),
            'precondition: every applicant was approved',
        );

        return count(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'from "accreditations"'),
        ));
    }

    private function accreditationWithApplicants(int $count): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => $count,
        ]);

        for ($i = 0; $i < $count; $i++) {
            Carbon::setTestNow('2026-08-01 '.str_pad((string) (9 + $i), 2, '0', STR_PAD_LEFT).':00:00');

            Application::create([
                'accreditation_id' => $accreditation->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'requested',
                'priority' => false,
            ]);
        }

        Carbon::setTestNow();

        return $accreditation;
    }

    /**
     * Run `$callback` and return every database query it issued as
     * `['sql' => …, 'bindings' => …]`.
     *
     * `DB::listen` has no removal API, but the listener only appends to the
     * array captured by this invocation, and the application instance (which
     * owns the event dispatcher) is flushed in `tearDown()`.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function recordQueries(Closure $callback): array
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $callback();

        return $queries;
    }
}

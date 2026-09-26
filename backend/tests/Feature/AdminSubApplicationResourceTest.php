<?php

namespace Tests\Feature;

use App\Http\Resources\AdminSubApplicationResource;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP-6-f: `AdminSubApplicationResource::accreditationData()` read
 * `$this->subAccreditation` BEFORE checking `relationLoaded('accreditation')`.
 *
 * Consequences if the eager load is ever dropped from the caller:
 *  - `$this->subAccreditation` lazy-loads, i.e. one extra query PER ROW
 *    (N+1 across the approvals list), and
 *  - the method then returns `null` for the whole `accreditation` block anyway
 *    because `accreditation` is not loaded — so the extra query buys nothing
 *    and the API silently loses the main-accreditation context (category,
 *    event, date) instead of failing loudly.
 *
 * The sibling `subAccreditationData()` had it right all along. Both are pinned
 * here: the query count, the resulting shape, and the positive control with
 * the eager load in place. `user` is eager-loaded in every case, exactly like
 * `AdminSubApplicationController::index`/`update` do, so the query count
 * isolates the sub/accreditation relations under test.
 */
class AdminSubApplicationResourceTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private Accreditation $accreditation;

    private SubAccreditation $subAccreditation;

    private SubApplication $subApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a']);

        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse',
        ]);

        $this->accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $this->subAccreditation = SubAccreditation::create([
            'accreditation_id' => $this->accreditation->id,
            'type' => 'park',
            'quota' => 4,
        ]);

        $applicant = User::factory()->create();

        $this->subApplication = SubApplication::create([
            'sub_accreditation_id' => $this->subAccreditation->id,
            'application_id' => Application::create([
                'accreditation_id' => $this->accreditation->id,
                'user_id' => $applicant->id,
                'status' => 'approved',
            ])->id,
            'user_id' => $applicant->id,
            'status' => 'requested',
        ]);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    /* -------------------------------------------------------------------
     | Without the eager load: no query, and no half-populated payload
     | ------------------------------------------------------------------- */

    public function test_resolving_the_payload_without_any_eager_load_issues_no_query(): void
    {
        $sub = $this->loadUserOnly();

        $queries = $this->captureSqlOf(fn () => (new AdminSubApplicationResource($sub))->toArray(request()));

        $this->assertSame(
            [],
            $queries,
            'a resource must never lazy-load on the read path — the sibling `subAccreditationData()` '
            .'checks `relationLoaded` first, and `accreditationData()` must do the same',
        );
    }

    public function test_resolving_the_payload_without_any_eager_load_keeps_the_flat_fields(): void
    {
        $sub = $this->loadUserOnly();

        $payload = (new AdminSubApplicationResource($sub))->toArray(Request::create('/'));

        $this->assertSame($sub->id, $payload['id']);
        $this->assertSame('requested', $payload['status']);
        $this->assertFalse($payload['priority']);
        $this->assertNull($payload['reason']);
        $this->assertNull($payload['sub_accreditation']);
        $this->assertNull($payload['accreditation']);
    }

    /* -------------------------------------------------------------------
     | Half-loaded: the accreditation block must not be reached through
     | ------------------------------------------------------------------- */

    public function test_a_loaded_sub_accreditation_without_a_loaded_accreditation_is_query_free(): void
    {
        $sub = SubApplication::query()
            ->with(['user:id,email,name', 'subAccreditation'])
            ->findOrFail($this->subApplication->id);

        $queries = $this->captureSqlOf(fn () => (new AdminSubApplicationResource($sub))->toArray(request()));

        $this->assertSame(
            [],
            $queries,
            'reading `accreditation` off an unloaded relation would cost one query per row and yield nothing',
        );

        $payload = (new AdminSubApplicationResource($sub))->toArray(Request::create('/'));

        $this->assertSame($this->subAccreditation->id, $payload['sub_accreditation']['id']);
        $this->assertNull($payload['accreditation']);
    }

    public function test_a_collection_without_the_eager_load_stays_at_zero_queries(): void
    {
        // The production shape: `AdminSubApplicationController::index` maps a
        // whole page through this resource, so the lazy load was an N+1 — one
        // wasted query per row, each of which bought nothing because the
        // `accreditation` block was dropped anyway.
        foreach (range(1, 2) as $ignored) {
            $applicant = User::factory()->create();

            SubApplication::create([
                'sub_accreditation_id' => $this->subAccreditation->id,
                'application_id' => Application::create([
                    'accreditation_id' => $this->accreditation->id,
                    'user_id' => $applicant->id,
                    'status' => 'approved',
                ])->id,
                'user_id' => $applicant->id,
                'status' => 'requested',
            ]);
        }

        $rows = SubApplication::query()->with('user:id,email,name')->get();

        $this->assertCount(3, $rows);

        $queries = $this->captureSqlOf(
            fn () => AdminSubApplicationResource::collection($rows)->toArray(request()),
        );

        $this->assertSame([], $queries, 'three rows must not cost three extra queries');
    }

    /* -------------------------------------------------------------------
     | Positive control: with the eager load the blocks are populated
     | ------------------------------------------------------------------- */

    public function test_the_eager_loaded_payload_still_carries_both_blocks(): void
    {
        $sub = $this->loadFully();

        $queries = $this->captureSqlOf(fn () => (new AdminSubApplicationResource($sub))->toArray(request()));

        $this->assertSame([], $queries, 'the eager load must be sufficient — no lazy access at all');

        $payload = (new AdminSubApplicationResource($sub))->toArray(Request::create('/'));

        $this->assertSame($this->subApplication->id, $payload['id']);
        $this->assertSame($this->subApplication->user_id, $payload['user']['id']);

        $this->assertSame($this->subAccreditation->id, $payload['sub_accreditation']['id']);
        $this->assertSame('park', $payload['sub_accreditation']['type']);
        $this->assertSame(4, $payload['sub_accreditation']['available']);

        $this->assertSame($this->accreditation->id, $payload['accreditation']['id']);
        $this->assertSame('Presse', $payload['accreditation']['category']['name']);
        $this->assertNull($payload['accreditation']['event']);
    }

    /**
     * Only the `user` relation loaded — the shape the resource must survive
     * without touching `subAccreditation`.
     */
    private function loadUserOnly(): SubApplication
    {
        return SubApplication::query()
            ->with(['user:id,email,name'])
            ->findOrFail($this->subApplication->id);
    }

    /**
     * The shape `AdminSubApplicationController::index`/`update` load.
     */
    private function loadFully(): SubApplication
    {
        return SubApplication::query()
            ->with(['user:id,email,name', 'subAccreditation.accreditation.category'])
            ->findOrFail($this->subApplication->id);
    }

    /**
     * @param  callable(): mixed  $callback
     * @return list<string>
     */
    private function captureSqlOf(callable $callback): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }
}

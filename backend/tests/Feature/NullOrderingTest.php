<?php

namespace Tests\Feature;

use App\Models\Mandant;
use App\Support\MandantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP-6-a (R-D6): the portal event calendar and the event participant list sort
 * a NULLABLE column ascending, and the two engines disagree about where NULLs
 * belong.
 *
 * - Postgres: `ORDER BY col ASC` puts NULLs **last** (the SQL standard leaves
 *   the position implementation-defined; PG chose NULLS LAST).
 * - SQLite: `ORDER BY col ASC` puts NULLs **first**.
 *
 * So the *same query* rendered the public calendar differently in production
 * than in the test suite: undated events last on PG, first on SQLite. Two
 * independent tables were affected (`events.date`,
 * `event_participants.sort_order` — both nullable by design).
 *
 * The fix is explicit ANSI SQL, `ORDER BY col ASC NULLS LAST`, which both
 * engines understand (Postgres since 9.x, SQLite since 3.30 — the test suite
 * runs 3.45). These tests pin BOTH the emitted SQL form (so a silent
 * regression back to `orderBy()` is caught even on an engine where the two
 * happen to agree) AND the resulting order.
 *
 * **Deliberate behaviour change:** the effective display order on SQLite
 * changed. Undated events / slot-less participants now sort AFTER the
 * numbered ones — which is what production has always done. The SQLite suite
 * previously asserted the opposite and therefore was asserting the bug.
 */
class NullOrderingTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The reason: the engine default is NOT the same on both engines
     | ------------------------------------------------------------------- */

    public function test_the_engine_default_asc_null_position_is_pinned_per_engine(): void
    {
        $this->insertRawEvent('ohne Datum', null);
        $this->insertRawEvent('mit Datum', '2026-05-01');

        $order = DB::table('events')->orderBy('date')->pluck('title')->all();

        // This is the divergence WP-6-a exists for. Should a future engine
        // change its default, this test flags it: the explicit `nulls last`
        // clauses in the application would still be correct, but the raw
        // comparison below would no longer describe the two engines.
        $expected = DB::connection()->getDriverName() === 'sqlite'
            ? ['ohne Datum', 'mit Datum']  // SQLite sorts NULLs FIRST
            : ['mit Datum', 'ohne Datum']; // Postgres sorts NULLs LAST

        $this->assertSame($expected, $order, 'engine NULL-order default changed');
    }

    /* ---------------------------------------------------------------------
     | Portal event calendar (events.date)
     | ------------------------------------------------------------------- */

    public function test_portal_calendar_query_pins_the_explicit_nulls_last_clause(): void
    {
        $this->mandant->events()->create(['title' => 'A', 'date' => '2026-05-01']);
        $this->mandant->events()->create(['title' => 'B', 'date' => null]);

        $sql = $this->captureSqlOf(fn () => $this->getJson('/api/portal/events')->assertOk());

        $this->assertStringContainsString(
            'events.date asc nulls last',
            $this->singleSelectOn($sql, 'events'),
            'the portal calendar must order with an explicit NULLS LAST, not the engine default',
        );
    }

    public function test_portal_calendar_lists_undated_events_after_the_dated_ones(): void
    {
        $this->mandant->events()->create(['title' => 'Zweites', 'date' => '2026-09-01']);
        $this->mandant->events()->create(['title' => 'Ohne Datum', 'date' => null]);
        $this->mandant->events()->create(['title' => 'Erstes', 'date' => '2026-08-01']);

        $response = $this->getJson('/api/portal/events')->assertOk();

        $this->assertSame(
            ['Erstes', 'Zweites', 'Ohne Datum'],
            array_column($response->json('data'), 'title'),
        );
    }

    public function test_portal_calendar_still_breaks_ties_on_id(): void
    {
        $first = $this->mandant->events()->create(['title' => 'Erste Anmeldung', 'date' => '2026-08-01']);
        $second = $this->mandant->events()->create(['title' => 'Zweite Anmeldung', 'date' => '2026-08-01']);

        $response = $this->getJson('/api/portal/events')->assertOk();

        $this->assertSame(
            [$first->id, $second->id],
            array_column($response->json('data'), 'id'),
        );
    }

    /* ---------------------------------------------------------------------
     | Event participants (event_participants.sort_order)
     | ------------------------------------------------------------------- */

    public function test_participants_relation_query_pins_the_explicit_nulls_last_clause(): void
    {
        $event = $this->mandant->events()->create(['title' => 'Spiel', 'date' => '2026-08-01']);

        $this->assertStringContainsString(
            'event_participants.sort_order asc nulls last',
            $event->participants()->toSql(),
            'the participants relation must order with an explicit NULLS LAST, not the engine default',
        );
    }

    public function test_participants_without_a_sort_order_come_after_the_numbered_slots(): void
    {
        $event = $this->mandant->events()->create(['title' => 'Spiel', 'date' => '2026-08-01']);

        $event->participants()->create(['name' => 'Heim', 'sort_order' => 10]);
        $event->participants()->create(['name' => 'Ohne Slot', 'sort_order' => null]);
        $event->participants()->create(['name' => 'Gast', 'sort_order' => 20]);

        $this->assertSame(
            ['Heim', 'Gast', 'Ohne Slot'],
            $event->participants()->pluck('name')->all(),
        );

        // …and the same order through the eager-loaded relation, which is how
        // the admin/portal resources read it.
        $this->assertSame(
            ['Heim', 'Gast', 'Ohne Slot'],
            $event->load('participants')->participants->pluck('name')->all(),
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Insert an event through the query builder, bypassing the `date` cast so
     * a NULL really reaches the column.
     */
    private function insertRawEvent(string $title, ?string $date): void
    {
        DB::table('events')->insert([
            'mandant_id' => $this->mandant->id,
            'title' => $title,
            'date' => $date,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Run the callback and return the SQL of every query it issued.
     *
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

    /**
     * The one select of the list that reads from the given table.
     *
     * @param  list<string>  $queries
     */
    private function singleSelectOn(array $queries, string $table): string
    {
        $matches = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "'.$table.'"'),
        ));

        $this->assertCount(
            1,
            $matches,
            'expected exactly one select on `'.$table.'`, got: '.implode(' | ', $matches),
        );

        return $matches[0];
    }
}

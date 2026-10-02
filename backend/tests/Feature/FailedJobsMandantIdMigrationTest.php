<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The dead-letter queue's mandant scope must arrive through a migration that
 * can still RUN — the defect of 2026-10-02, in replay form.
 *
 * WHAT HAPPENED (measured, not hypothesised). Commit 70aa03d added
 * `failed_jobs.mandant_id` by editing `0001_01_01_000002_create_jobs_table`
 * in place, on the reading of the migration policy ("before the first prod
 * deploy, extend existing migrations instead of adding a new one"). Laravel's
 * migrator skips every file name already recorded in the `migrations` table
 * (`Migrator::pendingMigrations()`), so on any database that had already run
 * that file the edit was invisible: `php artisan migrate` printed
 * `INFO Nothing to migrate.` and the column never appeared. The API that
 * reads it answered
 *
 *     SQLSTATE[42703]: column "mandant_id" does not exist
 *     (select * from "failed_jobs" where "mandant_id" is not null …)
 *
 * The suite stayed green through all of it, because `RefreshDatabase` /
 * `migrate:fresh` re-runs EVERY file from zero and therefore always had the
 * column. "The column exists" is not the property that was broken and cannot
 * be what a test for it asserts.
 *
 * THE PROPERTY, stated once: **a migration that creates a column must not be
 * an edit to a file an existing database has already run.** Two halves, and
 * each half is asserted separately because they fail differently:
 *
 *   1. {@see test_an_already_migrated_database_still_receives_the_column()}
 *      replays the broken world on the suite's own connection and runs
 *      `migrate` — the behavioural half. It fails if the column is
 *      re-homed in the already-run file, because then `migrate` has nothing
 *      left to do and the column stays missing.
 *   2. {@see test_the_column_is_not_created_by_an_already_run_migration_file()}
 *      pins the same rule on the source, naming the culprit file. It fails
 *      earlier, more cheaply, and — unlike (1) — says WHICH file to look at.
 *
 * (1) is the load-bearing one; (2) is the cheap pin that keeps the diagnosis
 * from being archaeology. Both are here on purpose: a behavioural test alone
 * reports "column missing" and leaves the next reader to work out which file
 * to open.
 */
class FailedJobsMandantIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** The file commit 70aa03d edited in place. */
    private const ALREADY_RUN_FILE = '0001_01_01_000002_create_jobs_table.php';

    /** The file that owns the column after the fix. */
    private const ADDITIVE_FILE = '2026_10_02_180000_add_mandant_id_to_failed_jobs_table.php';

    /* ---------------------------------------------------------------------
     | 1 — behavioural: replay the exact database that produced the 500
     | ------------------------------------------------------------------- */

    /**
     * Rebuilds the state the bug lived in, then runs `migrate` and requires
     * the column back.
     *
     * The reconstruction is deliberately not a fixture — it is the two facts
     * that were true on the broken database and are NOT true here after the
     * fix:
     *
     *   - `0001_01_01_000002_create_jobs_table` stands in `migrations`, so
     *     Laravel will never open that file again;
     *   - `failed_jobs` has no `mandant_id` column.
     *
     * Everything else is the ordinary migrated schema, which is what the real
     * database had. Then `php artisan migrate` runs, and the only file it may
     * legitimately open is the additive one.
     *
     * Uses the suite's own connection and its `RefreshDatabase` transaction, so
     * it works identically on SQLite and on PostgreSQL and needs no second
     * database (a second one would collide with `backend/AGENTS.md`'s
     * concurrency rule under `paratest`).
     */
    public function test_an_already_migrated_database_still_receives_the_column(): void
    {
        // Premise 1: the create-tables file is recorded as run. Assert it,
        // because without it this test would pass for the wrong reason (a
        // `migrate` that re-runs everything from scratch).
        $this->assertContains(
            '0001_01_01_000002_create_jobs_table',
            $this->ranMigrations(),
            'premise: the jobs migration must already stand in the ledger, '
            .'otherwise this test proves nothing about already-migrated databases',
        );

        // Premise 2: no additive migration has run yet. `RefreshDatabase` HAS
        // run it (it migrates the whole tree), so the ledger row is removed —
        // which is precisely the state of the broken database: the file did
        // not exist yet when that database was migrated.
        $this->assertContains(
            '2026_10_02_180000_add_mandant_id_to_failed_jobs_table',
            $this->ranMigrations(),
            'premise: the additive migration must be part of the tree, otherwise `migrate` '
            .'cannot possibly add it',
        );
        DB::table('migrations')
            ->where('migration', '2026_10_02_180000_add_mandant_id_to_failed_jobs_table')
            ->delete();

        // Premise 3: the column is missing — the exact 42703 state.
        //
        // The index goes FIRST, and that is an engine difference, not
        // tidiness: SQLite refuses the whole operation otherwise (measured
        // `1 error in index failed_jobs_mandant_id_index after drop column`),
        // while Postgres drops a dependent index by itself. Dropping it
        // explicitly is the one order that works on both — and this test has
        // to pass on both, or the `backend-pgsql` gate stops being a check.
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropIndex('failed_jobs_mandant_id_index');
        });
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropColumn('mandant_id');
        });
        $this->assertFalse(
            Schema::hasColumn('failed_jobs', 'mandant_id'),
            'premise: the replay must start from a failed_jobs WITHOUT the column',
        );
        $this->assertNotContains(
            '2026_10_02_180000_add_mandant_id_to_failed_jobs_table',
            $this->ranMigrations(),
            'premise: the additive migration must be pending, otherwise this test is a no-op',
        );

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(
            Schema::hasColumn('failed_jobs', 'mandant_id'),
            'an already-migrated database must receive failed_jobs.mandant_id from `migrate`. '
            .'It did not, which is SQLSTATE 42703 all over again.',
        );

        // And the shape the API depends on, not merely the column's existence:
        // the provider writes NULL for a non-mail job, and every list query
        // filters on the mandant.
        $column = collect(Schema::getColumns('failed_jobs'))->firstWhere('name', 'mandant_id');
        $this->assertNotNull($column);
        $this->assertTrue(
            $column['nullable'],
            'mandant_id must be nullable: a non-mail dead letter carries no mandant.',
        );
        $this->assertContains(
            'failed_jobs_mandant_id_index',
            Schema::getIndexListing('failed_jobs'),
            'the DLQ list filters on mandant_id; the index is part of the contract.',
        );

        // The ledger must record the file, or the next process inherits the
        // same "already migrated, still missing" state all over again.
        $this->assertContains('2026_10_02_180000_add_mandant_id_to_failed_jobs_table', $this->ranMigrations());
    }

    /* ---------------------------------------------------------------------
     | 2 — the rule on the source: name the culprit
     | ------------------------------------------------------------------- */

    /**
     * The policy half, on the source, so the failure names a file.
     *
     * `Schema::hasColumn()` is green either way on a fresh database — that is
     * the whole reason the defect survived a green suite. This assertion looks
     * at the one thing that differs between "the column is created by a
     * pending migration" and "the column is created by a file an existing
     * database already ran", and it does so on the file the defect actually
     * touched.
     *
     * It is a source inspection and that is on purpose: no runtime state of a
     * freshly migrated test database can distinguish the two. What runtime
     * CAN distinguish them is what test 1 does; this one localises.
     */
    public function test_the_column_is_not_created_by_an_already_run_migration_file(): void
    {
        $path = database_path('migrations/'.self::ALREADY_RUN_FILE);

        $this->assertFileExists($path);

        // COMMENTS ARE STRIPPED FIRST, and that is the load-bearing detail.
        // A first cut asserted on the raw file text and went red on this very
        // file's own comment — which names the additive migration — i.e. it
        // would have punished the one thing a reader needs: the warning that
        // tells the next editor where the column went. The property is not
        // "the string appears in the file"; it is "the file CREATES the
        // column". Comments cannot create columns, so they cannot violate it.
        $this->assertStringNotContainsString(
            'mandant_id',
            $this->withoutComments(File::get($path)),
            self::ALREADY_RUN_FILE.' must not create failed_jobs.mandant_id. Laravel skips a '
            .'file name already recorded in `migrations`, so an edit here reaches only '
            .'`migrate:fresh` databases and never an already-migrated one — measured '
            .'SQLSTATE 42703. A new column belongs in a new file (backend/AGENTS.md, '
            .'"Migration Policy").',
        );

        // The counterpart, so the rule above cannot be satisfied by simply
        // deleting both sides: some migration file has to own the column.
        $this->assertFileExists(
            database_path('migrations/'.self::ADDITIVE_FILE),
            'a new column needs its own migration file; none found',
        );
    }

    /* ---------------------------------------------------------------------
     | 3 — the guard clauses earn their place
     | ------------------------------------------------------------------- */

    /**
     * A real fleet contains databases migrated BEFORE the in-place edit (no
     * column) and databases `migrate:fresh`ed AFTER it (column, created by the
     * already-run file). The second kind is this repo's own test database — so
     * an unguarded `ALTER TABLE … ADD COLUMN` would abort with SQLSTATE 42701
     * "column mandant_id of relation failed_jobs already exists" and take the
     * ledger down with it.
     *
     * Measured against PostgreSQL 17 for the DB with the column and the index
     * (SQLSTATE 42P07 on an unguarded `Schema::getIndexes()` comparison — the
     * framework returns index DESCRIPTORS there, not names; `getIndexListing()`
     * is the API that returns names). This test is the same replay on the
     * suite's own connection, and it is engine-agnostic because both guards are
     * `Schema::` introspection rather than engine SQL.
     */
    public function test_running_the_additive_migration_twice_is_harmless(): void
    {
        $this->assertTrue(Schema::hasColumn('failed_jobs', 'mandant_id'));
        $this->assertContains('failed_jobs_mandant_id_index', Schema::getIndexListing('failed_jobs'));

        // A second `migrate` in the same test is a no-op by the ledger, so the
        // guards are exercised directly instead — which is the honest thing to
        // do: the ledger would otherwise hide them.
        $this->runMigrationFile(self::ADDITIVE_FILE);

        $this->assertTrue(Schema::hasColumn('failed_jobs', 'mandant_id'), 're-run dropped the column');
        $this->assertContains(
            'failed_jobs_mandant_id_index',
            Schema::getIndexListing('failed_jobs'),
            're-run must not drop or duplicate the index',
        );
    }

    /* ---------------------------------------------------------------------
     | helpers
     | ------------------------------------------------------------------- */

    /**
     * @return list<string>
     */
    private function ranMigrations(): array
    {
        return DB::table('migrations')->pluck('migration')->all();
    }

    /**
     * Strip comments from PHP source.
     *
     * Uses the tokenizer rather than a regex: a `#`-to-`-end-of-line` or
     * `/* … *\/` strip would corrupt string literals containing those
     * characters, and this feeds an assertion whose failure means "someone
     * edited a migration that has already run".
     */
    private function withoutComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }

    /**
     * Run one migration file against the suite's connection, bypassing the
     * ledger — the only way to reach the guard clauses, which by design do
     * nothing when the ledger already has the file.
     */
    private function runMigrationFile(string $file): void
    {
        $migration = require database_path('migrations/'.$file);

        $migration->up();
    }
}

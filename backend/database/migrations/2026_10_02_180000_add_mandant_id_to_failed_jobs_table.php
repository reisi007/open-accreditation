<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dead-letter queue gains its mandant scope — as its OWN migration.
 *
 * WHY THIS FILE EXISTS INSTEAD OF A LINE IN `0001_01_01_000002` (measured
 * 2026-10-02, the day the defect was found): commit 70aa03d added
 * `failed_jobs.mandant_id` by editing `create_jobs_table` in place, on the
 * reading of the migration policy ("before the first prod deploy, extend
 * existing migrations"). Laravel's migrator skips every file name that
 * already stands in the `migrations` table, so that edit reached only
 * `migrate:fresh` databases. Against a database that had already run the
 * file, `php artisan migrate` answered `INFO Nothing to migrate.` and the
 * column never arrived:
 *
 *     SQLSTATE[42703]: Undefined column: 7 ERROR:  column "mandant_id" does not exist
 *     (Connection: pgsql, Database: accriditation,
 *      SQL: select * from "failed_jobs" where "mandant_id" is not null ...)
 *
 * That is the whole class of defect in one command: the test suite could not
 * see it, because `RefreshDatabase`/`migrate:fresh` re-runs every file from
 * zero and therefore always had the column.
 *
 * THE COLUMN, unchanged from 70aa03d. Deriving the scope out of the `payload`
 * blob would be brittle (the value lives in a PHP-serialized command nested
 * inside JSON), so the owning mandant is a real, indexed column, written by
 * `App\Queue\Failed\MandantAwareFailedJobProvider`. Nullable and WITHOUT a
 * foreign key on purpose: a failed mail must survive the deletion of its
 * mandant (the SMTP host may have been misconfigured right when the Verband
 * was removed), and a non-mail job carries no mandant at all.
 *
 * WHY IT IS IDEMPOTENT — this is repair work, and repair work has THREE
 * possible starting states, not two. The in-place edit existed for a day, so
 * a real fleet contains all of them:
 *
 *   (A) migrated BEFORE the edit — no column.          -> adds column + index
 *   (B) `migrate:fresh` AFTER the edit — column exists. -> no-op
 *   (C) a manual `ALTER TABLE failed_jobs ADD mandant_id ...` (the workaround
 *       named in the bug report) — column exists, index does not.
 *                                                     -> adds the index only
 *
 * State (B) is not hypothetical: it is what every `RefreshDatabase` run of
 * this repo's own suite produces, and it is the state of the developer
 * database at the moment this file was written. Hence: converge, do not
 * assume. Both guards are load-bearing, each with its own measured failure:
 *
 *   - unguarded `ADD COLUMN` on state (B) -> SQLSTATE 42701 "column mandant_id
 *     of relation failed_jobs already exists", and the ledger row is NOT
 *     written (measured on PostgreSQL 17: `Ledger-Eintrag nach Fehlschlag: 0`).
 *     A migration that cannot record itself is not a broken migration, it is
 *     a permanently wedged schema — every later `migrate` fails the same way,
 *     on every environment, until someone drops the column by hand.
 *   - unguarded `CREATE INDEX` on states (B) and (C) -> SQLSTATE 42P07
 *     "relation failed_jobs_mandant_id_index already exists". The first cut of
 *     this file made exactly that mistake and was caught by measurement, not by
 *     review: it compared `Schema::getIndexes()`, which returns index
 *     DESCRIPTORS (`['name' => …, 'columns' => …]`), against a string — never a
 *     match, so the guard never guarded. `Schema::getIndexListing()` returns the
 *     names. The lesson is kept because the failure was silent: the column was
 *     correct on every database while the index blew up on two of three.
 *
 * A migration is not the place to be clever, so this is the ONE migration in
 * the repo that guards, and the reason is written down above rather than left
 * to a reader. Every later schema change is plain, unguarded `up()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('failed_jobs', 'mandant_id')) {
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->unsignedBigInteger('mandant_id')->nullable();
            });
        }

        if (! in_array('failed_jobs_mandant_id_index', Schema::getIndexListing('failed_jobs'), true)) {
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->index('mandant_id');
            });
        }
    }

    /**
     * Never executed (`backend/AGENTS.md`: down() methods are not run).
     */
    public function down(): void
    {
        //
    }
};

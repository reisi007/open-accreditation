<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * W12 venue master data replaces the two free-text location columns:
     * `teams.home_venue` and `events.venue` are DROPPED (replaced, not
     * supplemented — a second source of truth is exactly what this change
     * removes) and both tables get a nullable `venue_id` FK instead.
     *
     * `onDelete: restrict` on both: the API refuses to delete a referenced
     * venue with 409, but "deactivate instead of delete" is a *policy* and the
     * FK is the *enforcement* — a direct DELETE (console, tinker, a future
     * code path that forgets the guard) must not silently orphan or null out
     * the location of a team or an event.
     *
     * No backfill: there is no production data yet (Go-Live parked), so
     * dropping the text columns cannot lose anything. `is_active` defaults to
     * true on the new table, and every row that had a free-text venue now has
     * `venue_id = null` — exactly what "no location configured" meant before.
     *
     * The drops are guarded by `hasColumn()`. A *fresh* database never creates
     * the two text columns (the `create_teams_table` / `create_events_table`
     * migrations no longer declare them), while a dev/Prod database created
     * before this change still has them — the guard drops the column only where
     * it exists, so the migration is correct on both paths.
     *
     * SQLite note: `dropColumn` compiles to `ALTER TABLE … DROP COLUMN`, which
     * SQLite supports from 3.35 on (3.45.2 in CI); both engines take the same
     * path, so this stays portable (§2).
     */
    public function up(): void
    {
        if (Schema::hasColumn('teams', 'home_venue')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn('home_venue');
            });
        }

        if (Schema::hasColumn('events', 'venue')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('venue');
            });
        }

        Schema::table('teams', function (Blueprint $table) {
            // P2b-F2: `->index()` BEFORE `->constrained()` — named column index
            // `teams_venue_id_index`, clean constraint name.
            $table->foreignId('venue_id')
                ->nullable()
                ->index()
                ->constrained('venues')
                ->restrictOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('venue_id')
                ->nullable()
                ->index()
                ->constrained('venues')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The explicit `dropIndex()` before `dropConstrainedForeignId()` is not
     * cosmetic: SQLite refuses `ALTER TABLE … DROP COLUMN` while any index
     * still references the column
     * (`error in index teams_venue_id_index after drop column`), and
     * `dropConstrainedForeignId()` drops the FK/column but leaves the named
     * column index Laravel created next to it. Both engines take the same
     * path here.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['venue_id']);
            $table->dropConstrainedForeignId('venue_id');
            $table->string('home_venue')->nullable();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['venue_id']);
            $table->dropConstrainedForeignId('venue_id');
            $table->string('venue')->nullable();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P2c-F3 data hygiene: the initial `role_user` schema kept `team_id` as a
     * plain nullable integer because the `teams` table (P2a) did not exist
     * yet — no FK could be declared there. Deleting a team therefore left
     * orphaned role_user rows with a stale `team_id`. This migration closes
     * the gap: deleting a team now cascades to its role_user assignments.
     * The `mandant_id` FK stays as-is (cascadeOnDelete). Runs after the
     * teams table exists (migration ordering).
     */
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: `dropForeign` requires dropping every FK of the
     * table on SQLite (table rebuild), which would needlessly weaken the
     * schema. `down()` migrations are never executed in this project.
     */
    public function down(): void
    {
        // no-op
    }
};

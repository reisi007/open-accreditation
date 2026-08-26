<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * BE-R1-F1 data hygiene: the initial `users` schema kept `mandant_id` as a
     * plain nullable integer because the `mandants` table did not exist yet
     * (migration order) — the FK was never added later. Deleting a mandant
     * therefore left orphaned user rows with a stale `mandant_id`. This
     * migration closes the gap: deleting a mandant now sets the user's
     * `mandant_id` to NULL (global accounts already have NULL, so they are
     * unaffected). Runs after the mandants table exists (migration ordering).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('mandant_id')->references('id')->on('mandants')->nullOnDelete();
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

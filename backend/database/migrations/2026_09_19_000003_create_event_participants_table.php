<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W4: cardinality-free event participants (Teilnehmer).
     *
     * One row per participant slot, decoupled from the old implicit
     * "one event = one team" assumption:
     *   - 1 team  → Single
     *   - 2 teams → Versus (default pairing)
     *   - N teams → tournament groups sharing one time slot
     *
     * `team_id` is nullable with `nullOnDelete`: deleting a team keeps the slot
     * as a placeholder instead of cascading the participant away. A null
     * `team_id` marks an external/placeholder participant whose label comes
     * from `name`. `name` overrides the team name (`name_effective` = explicit
     * > team name > null). `logo_path` optionally carries a non-team image
     * (reserved for the W6 media service; team participants use the team logo).
     *
     * Uniqueness (portable across Postgres and SQLite — plain unique indexes,
     * no partial indexes):
     *   - `(event_id, team_id)` — a team takes part at most once per event.
     *     Both engines treat NULLs as distinct, so any number of placeholder
     *     rows (team_id = NULL) coexist.
     *   - `(event_id, sort_order)` — deterministic display order per event.
     *
     * The event owns its participants: deleting the event cascades them away.
     */
    public function up(): void
    {
        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` on both FKs —
            // named column indexes and clean constraint names on Postgres.
            $table->foreignId('event_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'team_id']);
            $table->unique(['event_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_participants');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P2b events (Events/Spiele): mandant-level when `team_id` is null,
     * team-level when set. Events cascade-delete with their mandant and team.
     * Date columns are plain `date` (no time), deadlines are optional. The
     * `(mandant_id, active)` index serves the admin list / active filter.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` on both FKs —
            // named column indexes (`events_mandant_id_index`,
            // `events_team_id_index`), clean constraint names from
            // `constrained()`. The reversed order would set the FK definition's
            // `index` attribute (the constraint NAME) and Postgres compiles
            // `constraint "1"` for two FKs in one table, breaking
            // `migrate:fresh`.
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('date')->nullable();
            $table->string('venue')->nullable();
            $table->string('competition')->nullable();
            $table->date('deadline_start')->nullable();
            $table->date('deadline_end')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['mandant_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

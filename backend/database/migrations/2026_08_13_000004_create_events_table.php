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
            // No `->index()` on the FK definitions: `ForeignKeyDefinition` is a
            // Fluent, so `->index()` would overwrite its `index` attribute —
            // that's the constraint NAME. Postgres then compiles
            // `constraint "1"` (duplicate name for two FKs in one table),
            // which breaks `migrate:fresh`. `constrained()` creates the FK
            // only; the index must be a separate command if needed.
            $table->foreignId('mandant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P2b categories (Kategorien): mandant-level when `team_id` is null,
     * team-level when set. Slug uniqueness is level-scoped:
     *
     * - mandant-level: `(mandant_id, slug)` — enforced via the generated
     *   column `mandant_slug`, which carries the slug only for rows with
     *   `team_id IS NULL`. A plain `(mandant_id, slug)` unique would wrongly
     *   forbid the same slug across two teams, and a plain partial unique
     *   index is a Postgres feature. The generated column is the portable
     *   emulation (SQLite and Postgres both support
     *   `generated always as … stored`).
     * - team-level: `(mandant_id, team_id, slug)` — both engines treat NULL
     *   `team_id` rows as distinct, so mandant-level rows never collide here.
     *
     * A team-level slug may reuse a mandant-level slug (override, resolved by
     * `Category::effectiveForTeam()`). Team-level categories cascade-delete
     * with their team, so a deleted Verein takes its category overrides with
     * it.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` on both FKs —
            // named column indexes (`categories_mandant_id_index`,
            // `categories_team_id_index`), clean constraint names from
            // `constrained()`. The reversed order would set the FK definition's
            // `index` attribute (the constraint NAME) and Postgres compiles
            // `constraint "1"` for two FKs in one table, breaking
            // `migrate:fresh`.
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();

            // Mandant-level slug uniqueness via a generated column that only
            // carries the slug for rows with `team_id IS NULL`. It must be
            // nullable: `string()` defaults to NOT NULL, but team-level rows
            // (team_id set) compute NULL here, which would violate the column
            // on Postgres.
            $table->string('mandant_slug')->nullable()->storedAs('case when team_id is null then slug end');
            $table->unique(['mandant_id', 'mandant_slug']);
            $table->unique(['mandant_id', 'team_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

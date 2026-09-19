<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W2 event types (mandant-specific event/competition kinds, e.g.
     * `bundesliga`, `cup`) with an optional public logo and a JSON `presets`
     * envelope (fachliches schema in W3).
     *
     * `slug` is unique per mandant only — two Verbände may use the same slug.
     * The type dies with its mandant (cascade). `events.event_type_id` is a
     * nullable FK with `nullOnDelete`: deleting a type keeps the event row and
     * clears the reference, while the free-text `events.competition` column
     * stays the fallback for events without a type. JSON uses Laravel's
     * portable `json` type (no raw `jsonb`) so the SQLite `:memory:` test
     * suite keeps working.
     */
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` so the FK keeps a
            // clean constraint name and Postgres does not compile
            // `constraint "1"`.
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->json('presets')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['mandant_id', 'slug']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('event_type_id')
                ->nullable()
                ->index()
                ->constrained('event_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_type_id');
        });

        Schema::dropIfExists('event_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * W12 venues (Spielstätten/Austragungsorte) as mandant-level master data.
     * ONE table, referenced by BOTH `teams.venue_id` (the Verein's default
     * venue) and `events.venue_id` (an event that deviates from it) — see the
     * second migration and `features/venue-master-data.md`.
     *
     * `name` is unique per mandant via a PLAIN composite unique
     * (`venues_mandant_id_name_unique`), deliberately not a partial/conditional
     * index over active rows only: a partial index is not portable between
     * Postgres and SQLite (§2 portability rule) and it would buy nothing.
     * A deactivated name therefore stays *taken* — the row is REACTIVated
     * instead of duplicated, so historical team/event references keep
     * resolving to the same name after a reactivation.
     *
     * Venues are never deleted while referenced (policy "deactivate instead of
     * delete"); the DB `restrict` on the two referencing FKs is the
     * enforcement. The venue itself dies with its mandant (cascade).
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` so the FK gets a
            // clean constraint name and Postgres does not compile
            // `constraint "1"`.
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mandant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};

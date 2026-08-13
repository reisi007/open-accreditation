<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P3b accreditation model (Akkreditierung) — the request objects of the
     * platform:
     *
     * - `accreditations` — a quota + deadline window for one category (Kategorie)
     *   within a scope (`event` | `league` | `season`, plain Laravel string,
     *   validated by the controllers — no DB enum for Postgres/SQLite
     *   portability). `event_id` is set (and required) only for `scope=event`;
     *   `team_id = null` → mandant-level, set → team-level (like categories/
     *   events). Applications cascade-delete with their accreditation; a deleted
     *   event only nulls `event_id` (the accreditation itself survives). The
     *   `(mandant_id, active)` index serves the admin list / active filter.
     * - `applications` — one row per user per accreditation. The unique
     *   `(accreditation_id, user_id)` constraint is the database-level guard
     *   against duplicate applications (the controller mirrors it with a 422).
     *   The status string is `requested|approved|denied|blacklisted`
     *   (`requested` default); quota is deliberately NOT enforced here —
     *   overbooking is allowed, the P3c allocation engine decides.
     * - `blacklists` — schema for the P3c blacklist enforcement (per mandant,
     *   email and/or domain). No enforcement logic yet, deliberately.
     */
    public function up(): void
    {
        Schema::create('accreditations', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` — named column
            // indexes, clean FK constraint names (see the categories migration).
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('scope');
            $table->unsignedInteger('quota');
            $table->date('deadline_start')->nullable();
            $table->date('deadline_end')->nullable();
            $table->boolean('auto_approve')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['mandant_id', 'active']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accreditation_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status')->default('requested');
            $table->boolean('priority')->default(false);
            $table->text('reason')->nullable();
            $table->timestamps();

            // Doppel-Antrag guard: one application per user per accreditation.
            $table->unique(['accreditation_id', 'user_id']);
        });

        Schema::create('blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('domain')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blacklists');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('accreditations');
    }
};

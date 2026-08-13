<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P2a teams (Vereine): optional per mandant. Slug uniqueness is scoped to
     * the mandant — one Verband cannot contain duplicate team slugs, but two
     * Verbände may use the same slug. The team dies with its mandant (cascade).
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            // No `->index()` on the FK definition: `ForeignKeyDefinition` is a
            // Fluent, so `->index()` overwrites its `index` attribute — the
            // constraint NAME — and Postgres compiles `constraint "1"`.
            $table->foreignId('mandant_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('home_venue')->nullable();
            $table->timestamps();
            $table->unique(['mandant_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P3e: the blacklists table gets its duplicate guards. Both unique
     * constraints are composite with `mandant_id`, so duplicate entries are
     * only forbidden within one mandant. The nullable `email`/`domain`
     * columns stay portable: Postgres and SQLite both treat NULLs as distinct
     * in a unique index, so multiple entries with only a domain (email NULL)
     * or only an email (domain NULL) remain legal — the controller already
     * normalizes input and pre-checks duplicates for a clean 422.
     */
    public function up(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->unique(['mandant_id', 'email']);
            $table->unique(['mandant_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->dropUnique(['mandant_id', 'email']);
            $table->dropUnique(['mandant_id', 'domain']);
        });
    }
};

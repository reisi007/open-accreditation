<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Multi-tenancy foundation: a Mandant (Verband) owns exactly one or more
     * hostnames (mandant_domains). The unique constraint on `hostname` doubles
     * as the lookup index for MandantContext::resolve().
     */
    public function up(): void
    {
        Schema::create('mandants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('header_path')->nullable();
            $table->text('impressum_text')->nullable();
            $table->text('privacy_text')->nullable();
            $table->json('smtp_config')->nullable();
            $table->boolean('teams_enabled')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mandant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mandant_id')->constrained()->cascadeOnDelete()->index();
            $table->string('hostname')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mandant_domains');
        Schema::dropIfExists('mandants');
    }
};

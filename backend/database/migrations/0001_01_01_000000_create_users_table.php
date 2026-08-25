<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // BE-R1: the owning mandant ("home" mandant) of this account —
            // the uniqueness anchor for the per-mandant email unique below.
            // Registration sets it from MandantContext (host-derived, never
            // user input). NULL marks a GLOBAL account without mandant binding
            // (the bootstrap super admin from DatabaseSeeder). No FK constraint
            // here: this base Laravel migration runs BEFORE the mandants table
            // exists (migration order); integrity is enforced at the
            // application layer (registration / seeder).
            //
            // Portability note: in a composite unique index a NULL `mandant_id`
            // never collides — Postgres AND SQLite both allow any number of
            // rows with NULL in an indexed column, so multiple global accounts
            // stay legal while mandant-scoped emails are deduplicated per
            // mandant.
            $table->foreignId('mandant_id')->nullable()->index();
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // P1b profile fields (accreditation application data).
            $table->string('title')->nullable();
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('street')->nullable();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('company')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax')->nullable();
            $table->string('branch')->nullable();
            $table->string('position')->nullable();
            $table->boolean('vest_available')->default(false);
            $table->string('vest_number')->nullable();

            // Per-mandant email uniqueness (BE-R1): the same person may hold
            // one account per mandant domain with the same email; uniqueness
            // is enforced within a mandant only, global accounts (mandant_id
            // NULL) are exempt via NULL semantics.
            $table->unique(['mandant_id', 'email']);

            // Self-registration activation flow (activation mail link).
            $table->string('activation_token', 64)->nullable()->unique();
            $table->timestamp('activation_token_expires_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

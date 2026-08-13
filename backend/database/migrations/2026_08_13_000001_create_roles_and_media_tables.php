<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Roles are scoped per mandant through the `role_user` pivot:
     * - `super_admin` is global (mandant_id = NULL, team_id = NULL).
     * - `mandant_admin` / `verifier` / `user` are scoped to one mandant.
     * - `team_admin` additionally carries a team_id (P2).
     *
     * `user_media` stores private (auth-gated) user photos/attachments on a
     * non-public disk; the `path` column must never be exposed as a public URL.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mandant_id')->nullable()->constrained()->cascadeOnDelete();
            // Plain nullable integer: the `teams` table is introduced in P2,
            // so no FK constraint can be declared here yet.
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unique(['user_id', 'role_id', 'mandant_id', 'team_id'], 'role_user_scope_unique');
            $table->timestamps();
        });

        Schema::create('user_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('original_name')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_media');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WP-6-c: the login hot path took a full sequential scan of `users`.
     *
     * `users` only carried `unique(['mandant_id', 'email'])`. A composite
     * B-tree can only serve a predicate that constrains its LEADING column
     * first, so `where('email', …)` — which
     * `AuthController::findLoginUser()` issues on the `MandantContext::currentId() === null`
     * branch (unmapped host, CLI, tests) — could not use it. That branch is
     * the throttled-at-15/min-per-IP login endpoint, so the scan sat directly
     * in front of the cheapest way to burn CPU.
     *
     * A single-column index on `email` is portable ANSI SQL (no expression, no
     * partial index, no operator class), so it is added verbatim. The
     * per-mandant uniqueness contract of BE-R1 is untouched: this index is
     * NON-unique, the `(mandant_id, email)` unique index stays the enforcement
     * layer.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('email', 'users_email_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: `down()` migrations are never executed in this
     * project. A `down()` would need `drop index users_email_index`.
     */
    public function down(): void
    {
        // no-op — intentionally not reversible: `down()` is never executed in
        // this project (see backend/AGENTS.md), and a `migrate:refresh` would
        // drop the index without recreating it, leaving the login hot path on a
        // sequential scan. See features/02-domain-model.md.
    }
};

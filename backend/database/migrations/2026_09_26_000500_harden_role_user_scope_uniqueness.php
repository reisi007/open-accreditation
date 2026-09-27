<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WP-6-b: `role_user_scope_unique` on `(user_id, role_id, mandant_id,
     * team_id)` is INERT for every role that exists.
     *
     * NULLs are distinct in a composite unique index on Postgres AND on
     * SQLite. `super_admin` is global (`mandant_id` AND `team_id` NULL),
     * `mandant_admin` / `verifier` / `user` are mandant-scoped with a NULL
     * `team_id` — so for all four role kinds at least one indexed column is
     * NULL and the constraint can never collide. It only ever protected
     * `team_admin` rows that carry a team, i.e. it guarded nothing on the
     * self-registration path (`AuthController::register` writes
     * `team_id => null`) and let a duplicate global `super_admin` row in,
     * which `AdminUserResource::rolesPayload` then rendered twice.
     *
     * This is the exact NULL-distinct situation that
     * `0001_01_01_000000_create_users_table.php:27-30` documents for
     * `users` — but THERE it is intentional (several global accounts must
     * stay legal), so the technique must not be copied blindly. Here it is a
     * defect, and the fix normalises the NULL scopes inside the index:
     *
     *     create unique index role_user_scope_unique_coalesced
     *         on role_user (user_id, role_id,
     *                        (coalesce(mandant_id, 0)), (coalesce(team_id, 0)))
     *
     * Portability: `COALESCE` is ANSI SQL, and expression indexes are
     * supported by Postgres (since 9.2) and SQLite (since 3.9.0). Each
     * expression needs its own parenthesis pair — that is required by
     * Postgres and accepted by SQLite. The sentinel `0` is safe: `id` is an
     * auto-increment starting at 1, so no real scope can collide with it.
     * Both engines verified (Postgres 17.10 / SQLite 3.45.2).
     *
     * The new index is a strict SUPERSET of the old one (same four columns,
     * NULL scopes folded together), so the old index is dropped instead of
     * kept — two identical-strength indexes on a write-hot pivot would only
     * add write amplification.
     *
     * ACHTUNG — `->change()` is a trap on SQLite, verified 2026-09-27 against
     * SQLite 3.45.2 and Postgres 17.10. Laravel rebuilds the table for a column
     * change and re-emits the indexes from `pragma index_info`, which reports
     * `name = NULL` / `cid = -2` for expression columns
     * (`SQLiteGrammar::compileIndexes()`). A `->change()` on `role_user`
     * therefore re-creates this index as `(user_id, role_id)` — both COALESCE
     * columns silently gone, WHILE THE NAME SURVIVES. A name-only assertion
     * (`Schema::getIndexes()` contains `role_user_scope_unique_coalesced`)
     * still passes, so the loss is invisible to the usual check.
     *
     * Postgres is NOT affected: `PostgresGrammar::compileChange()` issues a
     * plain `ALTER TABLE` and never touches `PRAGMA`. The asymmetry is
     * SQLite-only.
     *
     * Severity is low, for two reasons. No `->change()` on `role_user` exists
     * today. And the failure direction is SAFE: the narrowed index is
     * STRICTER, so the first symptom is a loud test failure on SQLite (one
     * user, same role, two federations) while Postgres stays correct — CI
     * catches it, production does not silently lose data. If such a change is
     * ever needed, drop and re-create this index explicitly in the same
     * migration rather than relying on the rebuild.
     */
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique('role_user_scope_unique');
        });

        DB::statement(
            'create unique index role_user_scope_unique_coalesced on role_user'
            .' (user_id, role_id, (coalesce(mandant_id, 0)), (coalesce(team_id, 0)))'
        );
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: `down()` migrations are never executed in this
     * project. For the record, the exact statements a `down()` would need:
     *
     *   drop index role_user_scope_unique_coalesced;
     *   alter table role_user add unique (user_id, role_id, mandant_id, team_id);
     */
    public function down(): void
    {
        // no-op — intentionally not reversible: `down()` is never executed in
        // this project (see backend/AGENTS.md), and a `migrate:refresh` would
        // drop the old index without recreating it, leaving the table weaker
        // than before the refresh. See features/02-domain-model.md.
    }
};

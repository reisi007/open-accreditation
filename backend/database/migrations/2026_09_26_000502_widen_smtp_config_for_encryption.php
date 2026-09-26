<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WP-6-d (R-D9): prepare `mandants.smtp_config` for the
     * `encrypted:json` cast — the column type has to move from `json` to
     * `text` FIRST, and only SQLite would have hidden that.
     *
     * Laravel's `Encrypter::encrypt()` returns `base64_encode(json_encode([...]))`,
     * i.e. a bare base64 STRING, not a JSON document. Writing that into a
     * `json` column is rejected by Postgres:
     *
     *   ERROR: invalid input syntax for type json
     *   DETAIL: Token "eyJpdiI6IjEyIiwidiI6IjEiLCJtYWMiOiIiLCJ0YWciOiIifQ" is invalid.
     *
     * SQLite is blind to the problem because its grammar maps `json` to plain
     * `text` (no `json_valid()` check) — the exact dev/prod-vs-test divergence
     * §2 forbids. Empirically verified on Postgres 17.10.
     *
     * `text` is the type the `encrypted` cast is designed for, and it makes
     * both engines agree: the column now stores an opaque ciphertext and no
     * SMTP password is legible in a dump, a backup or a read replica.
     *
     * The `json` → `text` cast is allowed by Postgres even with existing rows
     * (verified), so this migration is not destructive. The rows themselves
     * become unreadable the moment the cast lands — see
     * `features/02-domain-model.md` for the operator action.
     */
    public function up(): void
    {
        Schema::table('mandants', function (Blueprint $table) {
            $table->text('smtp_config')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: `down()` migrations are never executed in this
     * project. A `down()` would need the ciphertext to be dropped, not
     * re-typed — an encrypted value is not valid JSON input, so reversing this
     * migration would need `smtp_config = null` first.
     */
    public function down(): void
    {
        // no-op — intentionally not reversible: `down()` is never executed in
        // this project (see backend/AGENTS.md), and a `migrate:refresh` would
        // put the column back to `json`, which rejects the ciphertext the
        // `encrypted:json` cast writes. See features/02-domain-model.md.
    }
};

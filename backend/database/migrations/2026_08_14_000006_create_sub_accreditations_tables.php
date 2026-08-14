<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P3d sub-accreditations (Park-/Sitzkarten, D9) — derived quota objects
     * that only exist on top of a main accreditation:
     *
     * - `sub_accreditations` — a per-main-accreditation quota + deadline
     *   window for one `type` (`park` | `seat`, plain Laravel string,
     *   validated by the controllers — no DB enum for Postgres/SQLite
     *   portability). Like accreditations, `quota` is a target count only:
     *   the sub_applications table may exceed it (overbooking), the P3d
     *   allocation engine decides who receives a slot. `auto_approve`/
     *   `active` mirror the accreditation flags (auto-trigger + public
     *   visibility). Sub-applications cascade-delete with their main
     *   accreditation.
     * - `sub_applications` — one row per approved main application
     *   (`application_id`) per sub-accreditation. The unique
     *   `(sub_accreditation_id, application_id)` constraint is the
     *   database-level guard against duplicate sub-applications. `user_id`
     *   denormalizes the applicant (it always equals `application.user_id`)
     *   so the allocation engine can check the mandant blacklist against the
     *   user email without a join. Status: `requested|approved|denied`
     *   (`requested` default) — quota is deliberately NOT enforced here, the
     *   SubAllocationService decides.
     */
    public function up(): void
    {
        Schema::create('sub_accreditations', function (Blueprint $table) {
            $table->id();
            // P2b-F2: `->index()` BEFORE `->constrained()` — named column
            // indexes, clean FK constraint names (see the categories migration).
            $table->foreignId('accreditation_id')->index()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('quota');
            $table->date('deadline_start')->nullable();
            $table->date('deadline_end')->nullable();
            $table->boolean('auto_approve')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['accreditation_id', 'type']);
        });

        Schema::create('sub_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_accreditation_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status')->default('requested');
            $table->boolean('priority')->default(false);
            $table->text('reason')->nullable();
            $table->timestamps();

            // Doppel-Antrag guard: one sub-application per main application
            // per sub-accreditation.
            $table->unique(['sub_accreditation_id', 'application_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_applications');
        Schema::dropIfExists('sub_accreditations');
    }
};

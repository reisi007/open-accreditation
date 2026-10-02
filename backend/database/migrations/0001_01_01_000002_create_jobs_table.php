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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        // Queue tables are born together (2026-10-02, Position 45): the
        // dead-letter queue is Laravel's `failed_jobs`, and a mandant_admin
        // may only ever see the dead letters OF HIS mandant. Deriving that
        // scope out of the `payload` blob would be brittle (the value lives
        // in a PHP-serialized command nested inside JSON), so the owning
        // mandant is a real, indexed column, written by
        // `App\Queue\Failed\MandantAwareFailedJobProvider`.
        //
        // Nullable and WITHOUT a foreign key on purpose: a failed mail must
        // survive the deletion of its mandant (the SMTP host may have been
        // misconfigured right when the Verband was removed), and a non-mail
        // job carries no mandant at all.
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->unsignedBigInteger('mandant_id')->nullable()->index();
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P4: badge templates (Ausweis-Vorlagen) — the layout description for the
     * A6 badge PDF, owned by one mandant. `layout` is a JSON array of
     * positioned fields `[{field, x, y, w, h, size, align}]` (validated by the
     * controller). `is_default` marks the template used when an export request
     * carries no `template_id`; "at most one default per mandant" is enforced
     * by `BadgeTemplateService` (no DB constraint — portable).
     */
    public function up(): void
    {
        Schema::create('badge_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('layout');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['mandant_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('badge_templates');
    }
};

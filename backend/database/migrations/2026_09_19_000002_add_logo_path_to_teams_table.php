<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W4: optional public club/team logo. The file lands below the W1 media
     * layout (`<host>/teams/<slug>/logo.<ext>` via `MediaPathService::teamFile`)
     * and is delivered auth-gated through the admin API. Kept as a separate,
     * freshly dated migration (D17) so the already-shipped `create_teams_table`
     * migration stays untouched. `logo_path` is nullable — a team without a
     * logo keeps falling back to the brand/root image.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};

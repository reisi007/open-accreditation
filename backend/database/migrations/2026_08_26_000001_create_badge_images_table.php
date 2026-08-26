<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * P4 / badge-template-editor ("Elementtyp `image`", features/badge-
     * template-editor.md): mandant-owned badge images for freely placed
     * `image` layout entries with an `{kind: upload, image_id}` source. Files
     * live on the private disk (`badge-images/{slug}/{uniq}.{ext}`) and are
     * only ever streamed through the auth-gated admin delivery route — the
     * stored `path` is never a public URL.
     */
    public function up(): void
    {
        Schema::create('badge_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mandant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime');
            $table->string('original_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('badge_images');
    }
};

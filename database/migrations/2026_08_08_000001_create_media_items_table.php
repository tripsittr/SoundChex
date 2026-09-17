<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // music | movie | show | book
            $table->string('title');
            $table->string('external_id')->nullable(); // TMDB ID, MusicBrainz MBID, OLID, etc.
            $table->string('external_source')->nullable(); // tmdb | musicbrainz | openlibrary | etc.
            $table->string('cover_image_url', 1024)->nullable();
            $table->string('file_path')->nullable(); // only set when a file was uploaded
            $table->string('processing_status')->default('pending'); // pending|processing|complete|failed|needs_review
            $table->unsignedTinyInteger('user_rating')->nullable(); // 1–10
            $table->boolean('owned')->default(false);
            $table->boolean('wishlist')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
            $table->index(['organization_id', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};

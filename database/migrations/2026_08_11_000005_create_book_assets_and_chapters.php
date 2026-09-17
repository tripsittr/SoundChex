<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything recoverable from a book beyond its text: the illustrations,
 * plates and maps embedded in the file, and the chapter outline its publisher
 * left in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('page');

            // Path on the storage disk to the extracted image.
            $table->string('path');

            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes')->nullable();
            $table->string('format', 12)->nullable();

            // A book's own artwork is mostly rules, bullets and logos. This
            // marks the ones worth showing: large enough, and not a shape a
            // decoration would take.
            $table->boolean('is_significant')->default(true);

            // Set on whichever image was promoted to the book's cover, so a
            // re-run doesn't pick a different one.
            $table->boolean('is_cover')->default(false);

            $table->timestamps();

            $table->index(['media_item_id', 'page']);
            $table->index(['media_item_id', 'is_significant']);

            // Re-extracting replaces rather than duplicates.
            $table->unique(['media_item_id', 'path']);
        });

        Schema::create('book_chapters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->unsignedInteger('page');

            // Outlines nest — parts containing chapters containing sections.
            // Stored as a depth rather than a parent id: the tree is only ever
            // rendered as an indented list, never queried through.
            $table->unsignedTinyInteger('depth')->default(0);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['media_item_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_chapters');
        Schema::dropIfExists('book_assets');
    }
};

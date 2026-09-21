<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A book reduced to reflowable text units, for the Kindle-style reader (S-295).
 *
 * Each row is one ordered chunk of a book — a chapter for EPUB, a page for PDF
 * (its embedded text, or the OCR of a scanned page). The device renders these as
 * a reflowable reader with its own font and size, the same on every platform,
 * rather than each client parsing the file. Extracted once and cached here;
 * re-extracted only if the file changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // The reading order. 1-based, contiguous.
            $table->unsignedInteger('position');

            // A chapter title (EPUB) or "Page N" (PDF); shown in the reader's
            // chapter list. Nullable — not every unit has a natural title.
            $table->string('title')->nullable();

            // The unit's plain text. Longtext: a chapter can be large.
            $table->longText('text');

            $table->timestamps();

            $table->unique(['media_item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_contents');
    }
};

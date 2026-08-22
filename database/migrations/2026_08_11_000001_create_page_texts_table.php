<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recognised text for pages that have none of their own.
 *
 * OCR is slow and deterministic, so a page is only ever recognised once and
 * the result is kept. This is what makes a scanned book searchable, selectable
 * and highlightable on every subsequent open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_texts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('page');

            // Plain reading text, for text mode and full-text search.
            $table->longText('text')->nullable();

            // Word boxes as [{t, x, y, w, h, c}, …] in 0..1 page fractions, so
            // the reader can lay a real selectable text layer over the scan at
            // any zoom. Fractions rather than pixels for the same reason
            // highlights use them: the render size is not known here.
            $table->json('words')->nullable();

            // Mean word confidence, 0-100. A low score is the signal that a
            // page needs a second pass at higher resolution.
            $table->unsignedTinyInteger('confidence')->nullable();

            // pending | running | complete | failed | skipped
            // 'skipped' means the page already had embedded text worth using.
            $table->string('status')->default('pending')->index();

            $table->string('engine')->nullable();

            $table->timestamps();

            // One row per page, and the reader looks pages up by exactly this.
            $table->unique(['media_item_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_texts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Highlights and margin notes, private to each reader.
 *
 * Annotations are stored per user for the same reason reading progress is:
 * two people reading the same file are reading their own copy of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'highlight' or 'note'. A note is a highlight with text attached,
            // but they're listed and filtered separately.
            $table->string('kind')->default('highlight');

            // Where the annotation lives. The shape differs per format, so it
            // is stored as opaque JSON and interpreted by the reader:
            //   PDF   { page, rects: [...], scale-independent 0..1 coords }
            //   EPUB  { cfi: "epubcfi(...)" }
            $table->json('location');

            // Page number for PDFs, null for EPUB. Denormalised so the notes
            // sidebar can sort and jump without parsing every location blob.
            $table->unsignedInteger('page')->nullable();

            // The highlighted text itself, so the sidebar can show what was
            // marked without re-opening and re-rendering the page.
            $table->text('excerpt')->nullable();

            $table->text('note')->nullable();

            // Highlight colour key (yellow, green, blue, pink) — not a hex
            // value, so the palette can be restyled without a data migration.
            $table->string('color')->default('yellow');

            $table->timestamps();

            // The reader loads every annotation for one book on open.
            $table->index(['media_item_id', 'user_id']);
            $table->index(['media_item_id', 'user_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};

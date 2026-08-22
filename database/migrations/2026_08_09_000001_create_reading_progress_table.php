<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each user stopped reading each book.
 *
 * Kept per user rather than on `media_items` because the library is shared —
 * two people reading the same book must not overwrite each other's place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Format-specific resume token: an EPUB CFI, a PDF page number, or
            // a comic page index. Opaque to the server — the reader that wrote
            // it is the only thing that interprets it.
            $table->string('location', 512)->nullable();

            // 0–100, for progress bars and "continue reading" ordering.
            $table->unsignedTinyInteger('percent')->default(0);

            $table->boolean('finished')->default(false);
            $table->timestamps();

            // One row per user per book, and the lookup is always this pair.
            $table->unique(['media_item_id', 'user_id']);

            // "Continue reading" sorts a user's books by recency.
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};

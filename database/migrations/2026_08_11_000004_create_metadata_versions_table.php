<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time snapshots of an item's metadata.
 *
 * Providers rewrite their own records — a studio corrects a title, a
 * contributor re-dates a release, an entry is merged into another. Without
 * history a re-enrichment silently replaces what was there, and whatever the
 * old value was is gone.
 *
 * A snapshot is taken before each run, so the previous state is always
 * recoverable regardless of what the provider returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // Every field of the item and its type-specific metadata row, plus
            // tags and credits. One blob rather than a row per field: the point
            // is to restore a coherent past state, not to query across them.
            $table->json('snapshot');

            // What triggered it: enrichment | manual | restore | import.
            $table->string('reason')->default('enrichment');

            // Which source produced the change, when one did.
            $table->string('source')->nullable();

            // Who caused it, when it was a person rather than the pipeline.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Fields that differed from the previous version, so the timeline
            // can summarise without diffing two blobs on every render.
            $table->json('changed_fields')->nullable();

            $table->timestamps();

            $table->index(['media_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_versions');
    }
};

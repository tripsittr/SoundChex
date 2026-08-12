<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caption and subtitle tracks for a film or episode.
 *
 * A title can carry several: different languages, forced narrative subtitles
 * for foreign dialogue only, and SDH tracks that describe sound. Each is a row
 * pointing at a WebVTT file, which is the only format browsers play natively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // BCP-47-ish code as the browser wants it ("en", "pt-BR").
            $table->string('language', 12);

            // Shown in the track picker: "English", "Português (Brasil)".
            $table->string('label');

            // embedded | sidecar | opensubtitles — where this came from, so a
            // re-scan can replace downloaded tracks without touching the ones
            // that came with the file.
            $table->string('source');

            // Path on the storage disk to the converted WebVTT.
            $table->string('path');

            // Original file for a sidecar, or the stream index for an embedded
            // track. Kept so a re-convert doesn't need to re-detect.
            $table->string('origin')->nullable();

            // Dialogue only for scenes in another language. Players should
            // default to these when the main audio is already understood.
            $table->boolean('forced')->default(false);

            // Subtitles for the deaf and hard of hearing: includes sound
            // effects and speaker names.
            $table->boolean('sdh')->default(false);

            // Preferred when several tracks share a language.
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('cue_count')->nullable();

            $table->timestamps();

            $table->index(['media_item_id', 'language']);

            // One track per language per source per origin — re-running a scan
            // updates rather than duplicating.
            $table->unique(['media_item_id', 'language', 'source', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitles');
    }
};

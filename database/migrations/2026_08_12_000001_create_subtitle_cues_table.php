<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual subtitle lines, so dialogue is searchable.
 *
 * Cues live in .vtt files on disk, which is right for playback — the browser
 * fetches the track and does the rest. It is wrong for search: grepping every
 * file in a library can't be ranked, paginated, or joined against the rating
 * gate.
 *
 * A row per cue is roughly 1,500 for a feature film, which is nothing beside
 * the video itself. SubtitleImporter already parses cues to count them, so
 * this stores what it currently throws away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_cues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subtitle_id')->constrained()->cascadeOnDelete();

            // Denormalised from the track so a search can filter by title and
            // apply the rating gate without joining through subtitles.
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // Seconds from the start, as a float — WebVTT is millisecond
            // precision and a deep link seeks straight to this.
            $table->float('start_seconds');
            $table->float('end_seconds')->nullable();

            $table->text('text');

            $table->timestamps();

            // The two shapes of query: "find this phrase anywhere" and
            // "find it within this title".
            $table->index('media_item_id');
            $table->index(['subtitle_id', 'start_seconds']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_cues');
    }
};

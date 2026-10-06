<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What ffprobe says about a file, and anything wrong with it (#489).
 *
 * **No video technical data was stored anywhere.** Resolution, HDR, codecs,
 * channels, languages and bitrate were all absent, which is why keep-best for
 * video fell back to comparing file sizes — a 10% bigger file won, whatever it
 * actually contained.
 *
 * And nothing checked quality at all: a truncated file, a CAM rip, a
 * transcoded "FLAC" and a file with no audio stream all imported as healthy.
 *
 * Two tables rather than columns on `media_items`, because a probe is a
 * *measurement of a file* with its own timestamp — re-probing after a remux
 * replaces it wholesale — and findings are a list that grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_probes', function (Blueprint $table): void {
            $table->id();

            // Cascades: a probe describes one file and is meaningless without
            // it. Unique, because a file has one current measurement.
            $table->foreignId('media_item_id')->unique()->constrained()->cascadeOnDelete();

            $table->timestamp('probed_at');

            $table->string('container')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('bitrate')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->string('video_codec')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->decimal('fps', 7, 3)->nullable();
            // none | hdr10 | hdr10plus | dv | hlg
            $table->string('hdr')->nullable();
            $table->unsignedTinyInteger('bit_depth')->nullable();

            // Lists, because a film has several audio tracks and several
            // subtitle tracks and the interesting questions are about the set
            // ("is there a track in a language I read?").
            $table->json('audio_streams')->nullable();
            $table->json('subtitle_streams')->nullable();

            // Everything ffprobe said. Keeping it means a question nobody has
            // asked yet — a colour primary, a rotation flag — is answerable
            // from stored data rather than by re-probing the whole library.
            $table->json('raw')->nullable();

            $table->timestamps();

            // "What is 4K?", "what has no audio?" — the queries keep-best and
            // the quality profile will ask.
            $table->index(['height', 'video_codec']);
        });

        Schema::create('quality_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // Which check found it, e.g. `no_audio_stream`, `truncated`.
            $table->string('check');
            // info | warn | bad — only `bad` opens a review item.
            $table->string('severity');

            // What was measured and what it was measured against, so a finding
            // explains itself without re-running the check.
            $table->string('value')->nullable();
            $table->string('threshold')->nullable();
            $table->json('detail')->nullable();

            $table->timestamps();

            // One open finding per check per item: re-probing must update
            // rather than accumulate duplicates of the same complaint.
            $table->unique(['media_item_id', 'check']);
            $table->index(['severity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_findings');
        Schema::dropIfExists('media_probes');
    }
};

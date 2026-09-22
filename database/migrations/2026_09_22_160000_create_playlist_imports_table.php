<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A playlist import in progress or finished (S-310).
 *
 * Porting a playlist runs in the background — parse, match every track, build the
 * playlist — so the client needs something to poll and, at the end, a record of
 * what matched and what did not. This row is that: the source, the status, the
 * counts, the resulting playlist, and the unmatched tracks kept as data so the
 * user can resolve them by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Where it came from: 'file' now; 'spotify', 'apple_music' later.
            $table->string('source')->default('file');
            $table->string('source_format')->nullable(); // m3u, csv, xspf

            $table->string('name')->nullable();          // the playlist's name

            // pending → processing → complete | failed
            $table->string('status')->default('pending')->index();

            $table->unsignedInteger('total_tracks')->default(0);
            $table->unsignedInteger('matched_tracks')->default(0);

            // The tracks nothing in the library matched, as data, so the user can
            // resolve each to an item or leave it out.
            $table->json('unmatched')->nullable();

            // The playlist created, once it exists.
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();

            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_imports');
    }
};

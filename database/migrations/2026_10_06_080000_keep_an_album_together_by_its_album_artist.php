<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The artist an album belongs to, as distinct from a track's own (#489).
 *
 * Music files under `Artist/Album/Track`, using the **track** artist -- so a
 * compilation scatters. Measured on this library: **160 albums would spread
 * across 429 artist folders**, and the Stranger Things soundtrack splits into
 * 14 folders for 14 tracks, one per track.
 *
 * `album_artist` is the tag that exists to answer this, and taggers have
 * written it for twenty years. Nothing read it except as a fallback when the
 * track artist was missing, which is backwards: the track artist names the
 * performer, the album artist names the shelf it goes on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_metadata', function (Blueprint $table): void {
            // Nullable, because most files legitimately have none -- a single
            // artist's album needs no distinction, and the filer falls back to
            // the track artist. Only a compilation needs this set.
            $table->string('album_artist')->nullable()->after('artist');

            // The browse query: every track on one shelf.
            $table->index(['album_artist', 'album']);
        });
    }

    public function down(): void
    {
        Schema::table('music_metadata', function (Blueprint $table): void {
            $table->dropIndex(['album_artist', 'album']);
            $table->dropColumn('album_artist');
        });
    }
};

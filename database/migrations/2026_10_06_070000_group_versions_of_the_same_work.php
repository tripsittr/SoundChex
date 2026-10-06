<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions of one work, kept rather than merged (#457, #475, #476).
 *
 * The rule the user set: *"If Spotify has 15 versions of a song for an artist,
 * we should too."* So a second copy that differs in any meaningful way is a
 * **version to keep**, not a duplicate to resolve — and the burden of proof is
 * on calling something a duplicate.
 *
 * Before this, one row was one file was one work, so the only verdicts
 * available were "duplicate" or "not". Measured on this library: ~29% of
 * identifier-matched "duplicates" were the same recording on a *different
 * release* — the album cut and the greatest-hits copy — and the owner was being
 * asked to adjudicate pairs that both belong.
 *
 * Three columns, not a table. A version is not a new kind of thing: it is a
 * `media_items` row that happens to share a work with another. Clients, plays,
 * playlists, ratings and the API all key on `media_items`, and changing that
 * is a rewrite of every client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            // The identity of the *work*, not the file: `mb:recording:<uuid>`,
            // `tmdb:movie:308266`, `tmdb:tv:1396:s01e02`, `isbn:978…`.
            //
            // Indexed because every "what else is this?" query starts here,
            // and nullable because an unidentified file has no work to key on
            // yet — which is most of a library mid-import.
            $table->string('work_key')->nullable()->after('match_confidence');

            // Which version this is: `remaster_2012`, `acoustic`, `live`,
            // `single`, `directors_cut`, `extended`. Null means the plain
            // release, which is the common case and must not be confused with
            // "unknown".
            $table->string('edition')->nullable()->after('work_key');

            // What an ambiguous "play this song" resolves to — a playlist
            // entry, a shuffle. It decides nothing about what is *browsable*:
            // every version stays visible in its own collection, which is the
            // user's instruction and the opposite of a hidden picker.
            $table->boolean('is_primary_version')->default(true)->after('edition');

            // The grouping query, and the one keep-best will ask.
            $table->index(['work_key', 'edition']);
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropIndex(['work_key', 'edition']);
            $table->dropColumn(['work_key', 'edition', 'is_primary_version']);
        });
    }
};

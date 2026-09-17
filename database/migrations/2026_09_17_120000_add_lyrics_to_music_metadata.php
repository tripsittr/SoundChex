<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lyrics live on the track's music metadata, fetched from a lyric provider and
 * cached here so the same lookup is not repeated on every play. Two forms are
 * stored: plain text, and the timed LRC form when the provider has it (for a
 * future synced view). `lyrics_checked_at` records the last attempt so a track
 * with genuinely no lyrics is not re-fetched on every open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_metadata', function (Blueprint $table): void {
            $table->longText('lyrics')->nullable()->after('energy');
            $table->longText('lyrics_synced')->nullable()->after('lyrics');
            $table->timestamp('lyrics_checked_at')->nullable()->after('lyrics_synced');
        });
    }

    public function down(): void
    {
        Schema::table('music_metadata', function (Blueprint $table): void {
            $table->dropColumn(['lyrics', 'lyrics_synced', 'lyrics_checked_at']);
        });
    }
};

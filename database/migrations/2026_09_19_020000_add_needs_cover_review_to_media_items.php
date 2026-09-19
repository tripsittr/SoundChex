<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a merged row whose two copies had different cover art (S-265).
 *
 * When a content-duplicate merge keeps one copy and deletes the other, the audio
 * decision is sound but the artwork may not be: embedded covers are unreliable
 * (a track can carry a compilation's cover), and the code cannot tell a wrong
 * cover from a right one. Rather than guess, the merge keeps the audio and — when
 * the two copies' covers differed — flags the survivor here so a person can
 * verify the cover. It is orthogonal to duplicate_status (the row stays Merged),
 * so it gets its own boolean rather than a new status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->boolean('needs_cover_review')->default(false)->index()->after('duplicate_match');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropColumn('needs_cover_review');
        });
    }
};

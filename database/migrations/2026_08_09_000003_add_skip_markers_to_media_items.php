<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timestamps for the skippable stretches of a video.
 *
 * Set manually for now. Automatic detection means fingerprinting audio across
 * several episodes of the same series, which needs an episode model this app
 * doesn't have yet — these columns are the storage that approach would use
 * anyway, so nothing is thrown away when it arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // Seconds from the start. Null means "no marker set", which is
            // different from 0 — a title sequence can genuinely begin at 0.
            $table->unsignedInteger('intro_start_seconds')->nullable()->after('matched_by');
            $table->unsignedInteger('intro_end_seconds')->nullable()->after('intro_start_seconds');

            // Where end credits begin, so playback can offer to skip them.
            $table->unsignedInteger('credits_start_seconds')->nullable()->after('intro_end_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn([
                'intro_start_seconds',
                'intro_end_seconds',
                'credits_start_seconds',
            ]);
        });
    }
};

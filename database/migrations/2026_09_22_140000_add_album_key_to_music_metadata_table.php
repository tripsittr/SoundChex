<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A canonical grouping key for an album (S-308).
 *
 * Albums are derived from track tags, so the same album written different ways —
 * "(Deluxe)", "(Remastered 2016)", different bracket or quote styles — would show
 * as separate albums. This stores the normalised key the browse groups on, so
 * those variants always collapse into one album regardless of how or when their
 * tracks were imported. Kept in step with `album` on save and backfilled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_metadata', function (Blueprint $table) {
            $table->string('album_key')->nullable()->after('album')->index();
        });
    }

    public function down(): void
    {
        Schema::table('music_metadata', function (Blueprint $table) {
            $table->dropColumn('album_key');
        });
    }
};

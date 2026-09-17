<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an original went once a playable version replaced it.
 *
 * A conversion used to live in media/converted/, reachable only through a
 * database column and invisible to every scan — so a rebuilt catalogue found
 * the unplayable original and pointed at that instead. The conversion becomes
 * the filed item now, and the original moves aside to an archive.
 *
 * Kept rather than deleted: the archive is what makes a bad transcode
 * recoverable, and originals are usually the better quality besides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('archived_path')->nullable()->after('converted_path');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('archived_path');
        });
    }
};

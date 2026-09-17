<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A browser-playable copy alongside the original.
 *
 * Kept as a separate column rather than replacing `file_path`: conversion is
 * lossy and the original is the user's own copy, so it stays untouched and
 * remains the file offered for download.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('converted_path')->nullable()->after('file_path');

            // pending | running | complete | failed — surfaced in the UI so a
            // long encode doesn't look like nothing happening.
            $table->string('transcode_status', 16)->nullable()->after('converted_path');
            $table->unsignedTinyInteger('transcode_percent')->default(0)->after('transcode_status');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn(['converted_path', 'transcode_status', 'transcode_percent']);
        });
    }
};

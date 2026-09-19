<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores each file's size in bytes (S-119).
 *
 * Without it, library-size figures were a random 200-item sample scaled up,
 * because a real total meant one `filesize()` per item (thousands) on the page
 * that loads most. Capturing the size once — at catalogue time, and backfilled
 * for existing items — turns that estimate into an exact `SUM(file_size)`.
 *
 * Nullable, because an item can exist before its file lands (a pending upload,
 * a not-yet-transferred row) and because old rows are backfilled lazily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('file_size');
        });
    }
};

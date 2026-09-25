<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether an item's file is missing from disk (S-389, S-396).
 *
 * Whether a file exists is a disk question — `hasReadableFile()` stats the
 * path — and a disk question cannot be asked in SQL. Without somewhere to
 * put the answer, hiding these rows would mean a stat per item per page, and
 * still could not fix a `COUNT(*)` or the play queue, which are exactly the
 * places a missing file shows up as a wrong number or a dead track.
 *
 * So the scanner answers it once and writes it here, and every read path
 * filters on the column. The cost is that it is only as fresh as the last
 * scan: a file deleted an hour ago stays listed until the next one. That is
 * the right trade for a library where files are added far more often than
 * they vanish — and when one does vanish, the row is hidden rather than
 * deleted, so restoring the file and re-scanning brings it straight back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->boolean('file_missing')->default(false)->index();
            // When the file was last found to be gone — so the admin screen can
            // say "missing since Tuesday" rather than just "missing", and so a
            // sweep can tell a fresh disappearance from a long-standing one.
            $table->timestamp('file_missing_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn(['file_missing', 'file_missing_at']);
        });
    }
};

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records *why* a duplicate was flagged.
 *
 * Byte-hash detection was the only kind, so the reason never needed storing.
 * Content-based detection (S-257) adds several — same ISRC, same MusicBrainz
 * recording, same AcoustID fingerprint, or a close tag+duration match. The
 * review screen shows the reason as a confidence signal, and — crucially — the
 * merge path reads it: a byte match may be deleted after a byte re-compare, but
 * a content match is two genuinely different files and is deleted only by an
 * explicit "keep this one" choice, never by the automatic byte comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            // null for rows flagged before this existed (all byte matches) and
            // for rows that are not duplicates.
            $table->string('duplicate_match')->nullable()->after('duplicate_status');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropColumn('duplicate_match');
        });
    }
};

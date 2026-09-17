<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How sure the pipeline is that it identified the right item.
 *
 * The organizer renames and relocates files from resolved metadata, so a wrong
 * match doesn't just mislabel a row — it files the user's actual file under the
 * wrong author. Recording confidence at match time lets the organizer refuse to
 * move anything it isn't sure about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // exact  — matched on an identifier (ISBN, MBID) or a verbatim title
            // fuzzy  — matched after normalising punctuation, censoring, subtitles
            // none   — nothing matched; metadata came from the file or filename
            $table->string('match_confidence', 16)->default('none')->after('processing_status');

            // Which source claimed the match, for the review UI.
            $table->string('matched_by', 64)->nullable()->after('match_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn(['match_confidence', 'matched_by']);
        });
    }
};

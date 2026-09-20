<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of what the metadata pipeline did on the last enrichment run, so the
 * Review hub can say *why* an item needs a look rather than only *that* it does.
 *
 * One JSON blob rather than a row per source: it is always read and written as
 * a whole ("the last run's story"), never queried source-by-source, and a
 * per-source table would join-explode across a large library for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // { ran_at, review_reason, sources: [{ name, outcome, confidence?, note? }] }
            $table->json('enrichment_report')->nullable()->after('match_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('enrichment_report');
        });
    }
};

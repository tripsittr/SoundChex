<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a human cleared an item from metadata review (S-302).
 *
 * "Looks fine" set the status to Complete, indistinguishable from a
 * pipeline-completed item — so a later re-enrichment would flag the same item
 * for review again and the reviewed songs kept coming back. This timestamp
 * records that a person has judged the item, so re-enrichment can leave their
 * decision alone. Null means no human has reviewed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};

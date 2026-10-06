<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not now" is an answer, and nothing could record it.
 *
 * `skip()` on the review page moved the cursor and wrote nothing at all, so a
 * skipped item stayed exactly where it was: the queue is ordered by
 * `duplicate_detected_at` then `id`, neither of which a skip changed. Reload the
 * page, search again, or come back tomorrow and the same item is at the top.
 * The owner reported it as *"when I try to skip them they come back to the top"*
 * — the button appeared to do nothing, because it did nothing.
 *
 * Deliberately **not** reusing `status`. A skip is not a resolution and not a
 * dismissal: the question is still open and still needs an answer, just not from
 * this person at this moment. Collapsing it into "dismissed" would hide a real
 * question forever, which is the failure the whole review rebuild exists to end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_items', function (Blueprint $table): void {
            $table->timestamp('snoozed_until')->nullable()->after('status');

            // The queue filters on it on every page load, alongside status.
            $table->index(['status', 'snoozed_until']);
        });

        // And on the item itself, which is what the queue is actually built
        // from. Measured on a real library: 86 of 124 items in the identify
        // queue have **no review row at all** -- the queue selects on columns
        // of `media_items` (`pipeline_state`, `processing_status`,
        // `duplicate_status`), and only items the backfill reached carry a
        // `review_items` row. Snoozing the review row alone would therefore
        // have worked for 38 items and silently done nothing for the other 86,
        // which is the same bug in a new place.
        Schema::table('media_items', function (Blueprint $table): void {
            $table->timestamp('review_snoozed_until')->nullable()->after('reviewed_at');
            $table->index('review_snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::table('review_items', function (Blueprint $table): void {
            $table->dropIndex(['status', 'snoozed_until']);
            $table->dropColumn('snoozed_until');
        });

        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropIndex(['review_snoozed_until']);
            $table->dropColumn('review_snoozed_until');
        });
    }
};

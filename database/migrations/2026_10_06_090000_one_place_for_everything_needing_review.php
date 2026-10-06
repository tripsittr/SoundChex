<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One record for everything that needs a person (#469).
 *
 * Review was a **status, not a record**: five columns on `media_items`
 * (`processing_status`, `match_confidence`, `duplicate_status`,
 * `needs_cover_review`, `reviewed_at`) plus a reports table, each added for one
 * feature. A new failure kind had nowhere to go, so it went nowhere -- which is
 * why the audit found seven ways to end up hidden from the library AND absent
 * from review at the same time.
 *
 * A row here can say *why*, *what the evidence was*, *what to do about it* and
 * *what was decided* -- none of which a boolean column can carry.
 *
 * The old columns are **left in place and still written**. Clients read
 * `processing_status`, and a release that changed both the storage and the
 * readers at once would be untestable. They come out once this has proven
 * itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // `system` or `user`. Which enum `reason` belongs to depends on it:
            // SystemReviewReason has thirteen cases, ReviewReason has the five
            // a client already shows, and merging them would break the apps.
            $table->string('source');
            $table->string('reason');

            // Who reported it, for a user report. Null for a system finding.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();

            // What was measured, so the screen can state the question without
            // re-deriving it -- the candidates that tied, the two paths of a
            // duplicate, the quality finding's numbers.
            $table->json('details')->nullable();

            // What resolving it should do, offered as the primary action.
            $table->json('suggested_action')->nullable();

            $table->string('status')->default('open');
            $table->json('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();

            // The queue's own query: what is open, oldest first.
            $table->index(['status', 'created_at']);
            $table->index(['media_item_id', 'status']);
            $table->index(['source', 'reason']);
        });

        // One OPEN item per reason per file. Re-running a stage must update the
        // existing complaint rather than stack a second copy of it -- which is
        // how the old duplicate flag came to re-fire on every sweep.
        //
        // Scoped to open rows via a partial index: a file can legitimately have
        // been reviewed for the same reason twice over its life, and a plain
        // unique constraint would refuse the second.
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX review_items_one_open_per_reason
                 ON review_items (media_item_id, reason) WHERE status = 'open'"
            );
        }
        // MySQL has no partial index; the application enforces it there via
        // firstOrCreate on the same key, which is what ReviewLog does anyway.
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
    }
};

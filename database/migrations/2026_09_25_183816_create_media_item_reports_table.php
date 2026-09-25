<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports sent from the apps: "this item is wrong, and here is why" (S-398).
 *
 * A table rather than columns on `media_items` for two reasons. Two people on
 * two profiles can each hit the same bad file, and the second report should
 * add to the first rather than overwrite it — an admin wants to know that two
 * people noticed. And a report is a thing with a life of its own: raised,
 * looked at, resolved or dismissed. That does not fit a nullable column.
 *
 * Nothing here decides what happens to the item. Flagging sets the item's own
 * `processing_status` to `needs_review`, which the library's scope already
 * hides (S-396); this table only records who asked and what they said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_item_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            // Who reported it. Both nullable: a report outlives the profile
            // that raised it — deleting a profile should not erase the fact
            // that a file is broken.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason');
            $table->text('note')->nullable();

            // Open until an admin settles it. `resolved_at` with no
            // `dismissed_at` means it was a real problem and got fixed;
            // `dismissed_at` means it was not a problem. Keeping both apart
            // means the panel can show "3 reports, 2 were nothing" rather
            // than flattening that into a single count.
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            // The two questions asked of this table: what is open, and what
            // has been said about this item.
            $table->index(['resolved_at', 'dismissed_at']);
            $table->index(['media_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_item_reports');
    }
};

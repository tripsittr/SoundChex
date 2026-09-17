<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets two people in a household keep their own place in the same book.
 *
 * `reading_progress` was created before profiles existed, with a unique index
 * on `(media_item_id, user_id)`. A household shares one login, so when profiles
 * arrived and the controller began keying rows on `(media_item_id, profile_id)`,
 * that index became wrong: the second profile to open a book produced a second
 * row for the same user, and the insert was refused.
 *
 * It surfaced as a **500 on saving a reading position** — not as a wrong page,
 * which is why it went unnoticed. The first reader of any book worked normally
 * and every reader after them hit a constraint the code did not know about.
 *
 * The replacement matches how rows are actually addressed. `user_id` stays in
 * the second index for the rows written before profiles existed, which are
 * keyed to the account alone and still have to be found.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Duplicates cannot exist yet — the old index prevented exactly the
        // rows that would be duplicates — so there is nothing to clean up
        // before the new one goes on. Asserted rather than assumed, because
        // creating a unique index over dirty data fails halfway.
        $duplicates = DB::table('reading_progress')
            ->select('media_item_id', 'profile_id')
            ->whereNotNull('profile_id')
            ->groupBy('media_item_id', 'profile_id')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "reading_progress holds {$duplicates} duplicate (media_item_id, profile_id) pairs; "
                    . 'resolve them before adding the unique index.',
            );
        }

        Schema::table('reading_progress', function (Blueprint $table): void {
            $table->dropUnique('reading_progress_media_item_id_user_id_unique');
        });

        Schema::table('reading_progress', function (Blueprint $table): void {
            // Partial indexes are not portable, and SQLite treats every NULL
            // as distinct — so pre-profile rows (profile_id NULL) do not
            // collide with each other under this index. That is the behaviour
            // wanted: they are addressed by user_id, below.
            $table->unique(['media_item_id', 'profile_id'], 'reading_progress_item_profile_unique');
            $table->index(['media_item_id', 'user_id'], 'reading_progress_item_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('reading_progress', function (Blueprint $table): void {
            $table->dropUnique('reading_progress_item_profile_unique');
            $table->dropIndex('reading_progress_item_user_index');
        });

        // Reversing this can fail, and should: once two profiles have their
        // own position in one book, those rows are duplicates under the old
        // index and one person's place would have to be destroyed to restore
        // it. Better to refuse than to pick a reader to forget.
        Schema::table('reading_progress', function (Blueprint $table): void {
            $table->unique(['media_item_id', 'user_id'], 'reading_progress_media_item_id_user_id_unique');
        });
    }
};

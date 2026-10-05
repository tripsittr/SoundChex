<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remember a duplicate decision as a pair, so the file stays searchable.
 *
 * A decision used to live on the copy itself: `duplicate_status` said "keeping
 * both", and the scanner skipped that row for ever after. That is the only way
 * it could avoid asking the same question twice, because the row records which
 * item it was compared against but nothing about what was decided — so the
 * scanner had to treat a decision about one pair as a decision about the file.
 *
 * The cost is that a file reviewed last week is never compared against anything
 * added since. Decide that two albums are both worth keeping, rip a third copy
 * next month, and nothing notices.
 *
 * Recording the pair separates the two: the scanner can look at every file
 * every time and stay quiet only about the pairs someone has already ruled on.
 *
 * The pair is stored lowest id first, so "A and B" and "B and A" are one row.
 * Which of the two the scanner happens to call the original depends on where
 * each file sits at the time, and that can change when a file is filed into the
 * library — the decision should not move with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_decisions', function (Blueprint $table) {
            $table->id();

            // Normalised: lower_item_id is always the smaller of the two ids.
            $table->foreignId('lower_item_id')
                ->constrained('media_items')
                // Nothing to remember about a pair once a side is gone.
                ->cascadeOnDelete();

            $table->foreignId('higher_item_id')
                ->constrained('media_items')
                ->cascadeOnDelete();

            // 'kept' or 'merged', mirroring DuplicateStatus. Kept for the
            // record only — the scanner cares that a decision exists, not
            // which way it went.
            $table->string('decision');

            $table->timestamp('decided_at');

            // One decision per pair; a second ruling replaces the first.
            $table->unique(['lower_item_id', 'higher_item_id']);
        });

        $this->backfill();
    }

    /**
     * Carry over the decisions already made.
     *
     * Without this, the first sweep after this migration would re-ask every
     * question the user has ever answered — which is precisely the failure the
     * old behaviour existed to prevent, and a far worse one than the gap being
     * fixed here.
     */
    private function backfill(): void
    {
        $rows = DB::table('media_items')
            ->whereNotNull('duplicate_of_id')
            ->whereIn('duplicate_status', ['kept', 'merged'])
            ->select('id', 'duplicate_of_id', 'duplicate_status', 'duplicate_detected_at')
            ->get();

        $decisions = [];

        foreach ($rows as $row) {
            $a = (int) $row->id;
            $b = (int) $row->duplicate_of_id;

            // A row pointing at itself would break the unique pair; it should
            // not exist, but this is not the place to find out the hard way.
            if ($a === $b) {
                continue;
            }

            $decisions[] = [
                'lower_item_id' => min($a, $b),
                'higher_item_id' => max($a, $b),
                'decision' => (string) $row->duplicate_status,
                'decided_at' => $row->duplicate_detected_at ?? now(),
            ];
        }

        foreach (array_chunk($decisions, 200) as $chunk) {
            // insertOrIgnore: two rows can name the same pair from both ends,
            // and the second is the same decision rather than a conflict.
            DB::table('duplicate_decisions')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_decisions');
    }
};

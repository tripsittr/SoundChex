<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A duplicate must be able to reach its original, resolved or not.
 *
 * `MediaItem` is scoped by `ResolvedScope`, which hides anything not
 * `Complete`. The `duplicateOf` relation inherited that, so an original still
 * waiting on enrichment came back as null — not hidden, broken. The merge
 * screen passed that null into `DuplicateDetector::decideKeeper()` and the page
 * failed with "Argument #1 ($a) must be of type MediaItem, null given".
 *
 * This is the failure `ResolvedScope`'s own documentation predicts: callers
 * that legitimately need unresolved items must opt out, and the duplicates
 * machinery is entirely admin and maintenance.
 */
class DuplicateOriginalIsReachableTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function item(ProcessingStatus $status, bool $fileMissing = false): MediaItem
    {
        self::$n++;

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Item '.self::$n,
            'file_path' => 'media/unsorted/item-'.self::$n.'.mp4',
            'owned' => true,
        ]);

        // Set afterwards so the model's own defaults cannot win, and without
        // the scope so the row can be found whatever state it is in.
        MediaItem::unresolved()
            ->whereKey($item->getKey())
            ->update([
                'processing_status' => $status->value,
                'file_missing' => $fileMissing,
            ]);

        return MediaItem::unresolved()->findOrFail($item->getKey());
    }

    private function link(MediaItem $duplicate, MediaItem $original): void
    {
        MediaItem::unresolved()
            ->whereKey($duplicate->getKey())
            ->update(['duplicate_of_id' => $original->getKey()]);
    }

    public function test_an_unresolved_original_is_still_reachable_from_its_duplicate(): void
    {
        $original = $this->item(ProcessingStatus::Pending);
        $duplicate = $this->item(ProcessingStatus::Complete);

        $this->link($duplicate, $original);

        $reloaded = MediaItem::unresolved()->findOrFail($duplicate->getKey());

        $this->assertNotNull(
            $reloaded->duplicateOf,
            'The original exists; the scope must not hide it from its own duplicate.',
        );

        $this->assertTrue($reloaded->duplicateOf->is($original));
    }

    public function test_it_also_works_for_an_original_whose_file_is_missing(): void
    {
        $original = $this->item(ProcessingStatus::Complete, fileMissing: true);
        $duplicate = $this->item(ProcessingStatus::Complete);

        $this->link($duplicate, $original);

        $reloaded = MediaItem::unresolved()->findOrFail($duplicate->getKey());

        $this->assertNotNull(
            $reloaded->duplicateOf,
            'A missing file is exactly the case the merge screen needs to show.',
        );
    }

    public function test_a_resolved_original_is_reachable_too(): void
    {
        $original = $this->item(ProcessingStatus::Complete);
        $duplicate = $this->item(ProcessingStatus::Complete);

        $this->link($duplicate, $original);

        $this->assertNotNull(MediaItem::findOrFail($duplicate->getKey())->duplicateOf);
    }
}

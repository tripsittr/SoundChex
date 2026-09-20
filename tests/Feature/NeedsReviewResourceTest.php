<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Resources\Duplicates\DuplicateResource;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The review resource is "Needs Review", and its badge counts every kind of
 * review work, not just pending duplicates (S-268).
 */
class NeedsReviewResourceTest extends TestCase
{
    use RefreshDatabase;

    private function item(array $attributes): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Song',
            'owned' => true,
        ]);

        // duplicate_status / needs_cover_review are guarded (set by the detector,
        // not mass assignment), so force them in for the fixture.
        if ($attributes !== []) {
            $item->forceFill($attributes)->save();
        }

        return $item->fresh();
    }

    public function test_the_nav_label_is_needs_review(): void
    {
        $this->assertSame('Needs Review', DuplicateResource::getNavigationLabel());
    }

    public function test_the_badge_counts_pending_duplicates_and_cover_reviews(): void
    {
        // Two pending duplicates + three covers awaiting a look = 5.
        $this->item(['duplicate_status' => DuplicateStatus::Pending]);
        $this->item(['duplicate_status' => DuplicateStatus::Pending]);
        $this->item(['duplicate_status' => DuplicateStatus::Merged, 'needs_cover_review' => true]);
        $this->item(['duplicate_status' => DuplicateStatus::Merged, 'needs_cover_review' => true]);
        $this->item(['duplicate_status' => DuplicateStatus::Merged, 'needs_cover_review' => true]);
        // Noise that must not count.
        $this->item(['duplicate_status' => DuplicateStatus::Kept]);
        $this->item([]);

        $this->assertSame('5', DuplicateResource::getNavigationBadge());
    }

    public function test_the_badge_is_null_when_nothing_needs_review(): void
    {
        $this->item(['duplicate_status' => DuplicateStatus::Merged]);
        $this->item([]);

        $this->assertNull(DuplicateResource::getNavigationBadge());
    }

    public function test_the_badge_counts_metadata_review_items_too(): void
    {
        // A metadata-flagged item is review work even with no duplicate state —
        // this is the case that used to be invisible on the review page (S-277).
        $this->item(['processing_status' => ProcessingStatus::NeedsReview]);
        $this->item(['processing_status' => ProcessingStatus::Failed]);
        $this->item(['duplicate_status' => DuplicateStatus::Pending]);

        $this->assertSame('3', DuplicateResource::getNavigationBadge());
    }

    public function test_metadata_flagged_items_are_in_the_review_queue(): void
    {
        // Previously excluded by whereNotNull('duplicate_status'); the hub query
        // now includes them so the Metadata tab has rows.
        $flagged = $this->item(['processing_status' => ProcessingStatus::NeedsReview]);
        $this->item([]); // a plain, complete item must stay out

        $ids = DuplicateResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($flagged->id, $ids);
        $this->assertCount(1, $ids);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
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
}

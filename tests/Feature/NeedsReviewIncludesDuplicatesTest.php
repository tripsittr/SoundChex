<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Filament\Resources\Movies\Pages\ListMovies;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A duplicate is a decision nobody has taken, so it belongs in the review
 * queue.
 *
 * The detector flags two files as the same and asks which to keep. That only
 * ever showed on the Duplicates screen, so a duplicate film or episode sat
 * there while the "Needs review" tab — the queue people actually work from —
 * reported nothing to do.
 */
class NeedsReviewIncludesDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function movie(array $attributes = []): MediaItem
    {
        self::$n++;

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Film '.self::$n,
            'file_path' => 'media/library/Movies/film-'.self::$n.'.mkv',
            'owned' => true,
        ]);

        if ($attributes !== []) {
            MediaItem::unresolved()->whereKey($item->getKey())->update($attributes);
        }

        return MediaItem::unresolved()->findOrFail($item->getKey());
    }

    /** @return array<int, int> ids the Needs review tab would show */
    private function reviewed(): array
    {
        $method = new ReflectionMethod(ListMovies::class, 'needsReviewQuery');

        return $method->invoke(null, MediaItem::unresolved()->where('type', MediaItemType::Movie->value))
            ->pluck('id')
            ->all();
    }

    public function test_an_unidentified_item_is_in_the_queue(): void
    {
        $item = $this->movie(['processing_status' => ProcessingStatus::NeedsReview->value]);

        $this->assertContains($item->id, $this->reviewed());
    }

    public function test_a_pending_duplicate_is_in_the_queue(): void
    {
        $original = $this->movie(['processing_status' => ProcessingStatus::Complete->value]);

        $copy = $this->movie([
            'processing_status' => ProcessingStatus::Complete->value,
            'duplicate_of_id' => $original->getKey(),
            'duplicate_status' => DuplicateStatus::Pending->value,
        ]);

        $this->assertContains($copy->id, $this->reviewed());
    }

    public function test_a_kept_duplicate_is_in_the_queue(): void
    {
        $original = $this->movie(['processing_status' => ProcessingStatus::Complete->value]);

        $copy = $this->movie([
            'processing_status' => ProcessingStatus::Complete->value,
            'duplicate_of_id' => $original->getKey(),
            'duplicate_status' => DuplicateStatus::Kept->value,
        ]);

        $this->assertContains($copy->id, $this->reviewed());
    }

    /**
     * The one that must not be included. Merged duplicates are settled, and
     * there are thousands of them — sweeping those in would bury the queue.
     */
    public function test_a_merged_duplicate_is_not_in_the_queue(): void
    {
        $original = $this->movie(['processing_status' => ProcessingStatus::Complete->value]);

        $copy = $this->movie([
            'processing_status' => ProcessingStatus::Complete->value,
            'duplicate_of_id' => $original->getKey(),
            'duplicate_status' => DuplicateStatus::Merged->value,
        ]);

        $this->assertNotContains($copy->id, $this->reviewed());
    }

    public function test_an_ordinary_item_is_not_in_the_queue(): void
    {
        $item = $this->movie(['processing_status' => ProcessingStatus::Complete->value]);

        $this->assertNotContains($item->id, $this->reviewed());
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Filament\Resources\Movies\Pages\ListMovies;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The two cover buttons.
 *
 * "Missing" is the one that matters day to day: an item that never got a cover
 * — usually because the source had no key configured at the time — is never
 * asked about again on its own. "All" is for when the artwork is there but
 * wrong.
 */
class CoverRefetchActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Queue::fake();
    }

    private function movie(?string $cover, ?int $parentId = null): MediaItem
    {
        self::$n++;

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Film '.self::$n,
            'file_path' => 'media/library/Movies/film-'.self::$n.'.mkv',
            'cover_image_url' => $cover,
            'owned' => true,
        ]);

        if ($parentId !== null) {
            $item->forceFill(['parent_id' => $parentId])->saveQuietly();
        }

        return $item;
    }

    private function page(): ListMovies
    {
        return new ListMovies;
    }

    private function scope(ListMovies $page, bool $onlyMissing): array
    {
        $method = new ReflectionMethod(ListMovies::class, 'coverScopeQuery');

        return $method->invoke($page, $onlyMissing)->pluck('id')->all();
    }

    public function test_missing_covers_selects_only_items_without_one(): void
    {
        $withCover = $this->movie('artwork/a.jpg');
        $without = $this->movie(null);

        $ids = $this->scope($this->page(), true);

        $this->assertContains($without->id, $ids);
        $this->assertNotContains($withCover->id, $ids);
    }

    public function test_all_covers_selects_both(): void
    {
        $withCover = $this->movie('artwork/a.jpg');
        $without = $this->movie(null);

        $ids = $this->scope($this->page(), false);

        $this->assertContains($without->id, $ids);
        $this->assertContains($withCover->id, $ids);
    }

    /**
     * A series row has no file to read artwork from, so re-enriching one spends
     * a request to achieve nothing.
     */
    public function test_a_child_row_is_left_out(): void
    {
        $parent = $this->movie(null);
        $child = $this->movie(null, $parent->id);

        $ids = $this->scope($this->page(), false);

        $this->assertNotContains($child->id, $ids);
    }

    public function test_it_queues_one_enrichment_per_item(): void
    {
        $this->movie(null);
        $this->movie(null);
        $this->movie('artwork/a.jpg');

        $method = new ReflectionMethod(ListMovies::class, 'queueCoverRefetch');
        $method->invoke($this->page(), true);

        Queue::assertPushed(EnrichMediaItemJob::class, 2);
    }

    public function test_it_queues_nothing_when_there_is_nothing_missing(): void
    {
        $this->movie('artwork/a.jpg');

        $method = new ReflectionMethod(ListMovies::class, 'queueCoverRefetch');
        $method->invoke($this->page(), true);

        Queue::assertNothingPushed();
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Folding away the playlists a double import already made (S-325).
 */
class MergeDuplicatePlaylistsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function track(string $title): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'music/'.str($title)->slug().'.mp3',
            'owned' => true,
        ]);
    }

    /** @param  array<int, MediaItem>  $tracks */
    private function playlist(string $name, array $tracks, ?User $owner = null): Collection
    {
        $playlist = Collection::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => $name,
        ]);

        foreach ($tracks as $i => $track) {
            $playlist->mediaItems()->attach($track->id, ['sort_order' => $i]);
        }

        return $playlist;
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $a = $this->playlist('Karaoke', [$this->track('One')]);
        $b = $this->playlist('Karaoke', [$this->track('Two')]);

        $this->artisan('playlists:merge-duplicates')->assertSuccessful();

        $this->assertNotNull($a->fresh());
        $this->assertNotNull($b->fresh());
    }

    public function test_the_oldest_playlist_keeps_the_name_and_the_rest_are_folded_in(): void
    {
        $shared = $this->track('Shared');
        $keep = $this->playlist('Karaoke', [$shared, $this->track('Only A')]);
        $drop = $this->playlist('Karaoke', [$shared, $this->track('Only B')]);

        $this->artisan('playlists:merge-duplicates --force')->assertSuccessful();

        $this->assertNull($drop->fresh(), 'The duplicate should be gone.');
        $this->assertNotNull($keep->fresh());

        // Three distinct tracks, not four: the shared one is not doubled.
        $this->assertSame(3, $keep->fresh()->mediaItems()->count());
    }

    public function test_folded_in_tracks_are_appended_after_the_ones_already_there(): void
    {
        $keep = $this->playlist('Karaoke', [$this->track('First'), $this->track('Second')]);
        $this->playlist('Karaoke', [$this->track('Third')]);

        $this->artisan('playlists:merge-duplicates --force')->assertSuccessful();

        $positions = $keep->fresh()->mediaItems()
            ->orderByPivot('sort_order')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->title => $item->pivot->sort_order])
            ->all();

        $this->assertSame(['First' => 0, 'Second' => 1, 'Third' => 2], $positions);
    }

    public function test_a_perfect_duplicate_leaves_the_kept_playlist_untouched(): void
    {
        $one = $this->track('One');
        $two = $this->track('Two');
        $keep = $this->playlist('Karaoke', [$one, $two]);
        $drop = $this->playlist('karaoke', [$one, $two]);

        $this->artisan('playlists:merge-duplicates --force')->assertSuccessful();

        $this->assertNull($drop->fresh());
        $this->assertSame(2, $keep->fresh()->mediaItems()->count());
    }

    public function test_names_are_matched_the_way_a_person_reads_them(): void
    {
        $keep = $this->playlist('Karaoke', [$this->track('One')]);
        $drop = $this->playlist('  karaoke ', [$this->track('Two')]);

        $this->artisan('playlists:merge-duplicates --force')->assertSuccessful();

        $this->assertNull($drop->fresh());
        $this->assertSame(2, $keep->fresh()->mediaItems()->count());
    }

    public function test_two_users_may_each_have_a_playlist_of_the_same_name(): void
    {
        // The obvious way to get this wrong is to group on name alone.
        $other = User::factory()->create();
        $mine = $this->playlist('Karaoke', [$this->track('One')]);
        $theirs = $this->playlist('Karaoke', [$this->track('Two')], $other);

        $this->artisan('playlists:merge-duplicates --force')->assertSuccessful();

        $this->assertNotNull($mine->fresh());
        $this->assertNotNull($theirs->fresh());
    }

    public function test_a_second_run_has_nothing_left_to_do(): void
    {
        $this->playlist('Karaoke', [$this->track('One')]);
        $this->playlist('Karaoke', [$this->track('Two')]);

        $this->artisan('playlists:merge-duplicates --force')->assertSuccessful();

        $this->artisan('playlists:merge-duplicates --force')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }
}

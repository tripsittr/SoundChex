<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a list page costs to render.
 *
 * Both of these were real: the songs page serialised its entire queue onto
 * every row — 96 copies of the same 26 KB on a 48-song page, 83% of 3 MB of
 * HTML — and the pages behind it ran a query per row for things that do not
 * change within a request.
 *
 * Counted rather than timed. A duration assertion is a flake on a loaded
 * machine; the query count and the payload shape are exactly what regressed,
 * and both fail loudly when it happens again.
 */
class ListPageCostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
        ]);
    }

    public function test_the_songs_list_writes_its_queue_once(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->track("Song {$i}");
        }

        $html = $this->browse()->get('/app/music')->assertOk()->getContent();

        // One queue for the list...
        $this->assertSame(1, substr_count($html, 'data-play-queue='));

        // Every row is still playable, and still knows where it starts: two
        // controls per row, the artwork and the title.
        $this->assertSame(24, substr_count($html, 'data-play-index='));

        // ...and no row inside it carries a copy of that queue. The rails
        // above are posters, which legitimately hold their own single track,
        // so this looks at the list rather than the whole page.
        preg_match('/<ol[^>]*data-play-queue=.*?<\/ol>/s', $html, $list);

        $this->assertNotEmpty($list, 'the songs list should carry the queue');
        $this->assertSame(0, substr_count($list[0], 'data-play="'));

        // And the queue itself appears once in it, not once per row.
        $this->assertSame(1, substr_count($list[0], 'data-play-queue='));
    }

    public function test_the_songs_page_does_not_query_per_row(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->track("Song {$i}");
        }

        $queries = $this->countQueries(fn () => $this->browse()->get('/app/music')->assertOk());

        // The page runs a fixed set of queries — the grid, the rails, the
        // counts — none of which scale with the number of rows. It ran 344
        // against a real library before: the profile once per call site (126),
        // a resume position per track (121), and the playlist list per row
        // (48). A generous ceiling, because the point is that it is bounded.
        $this->assertLessThan(
            60,
            $queries,
            "The songs page ran {$queries} queries; something is querying per row again.",
        );
    }

    public function test_the_album_and_artist_indexes_batch_their_covers(): void
    {
        // Each album's cover comes from a sample track, which was fetched with
        // its own find() inside the loop — 60 lookups on a page of 60 albums.
        for ($i = 1; $i <= 10; $i++) {
            $this->track("Track {$i}", album: "Album {$i}", artist: "Artist {$i}");
        }

        foreach (['/app/albums', '/app/artists'] as $url) {
            $queries = $this->countQueries(fn () => $this->browse()->get($url)->assertOk());

            $this->assertLessThan(
                12,
                $queries,
                "{$url} ran {$queries} queries; the covers are being fetched one at a time again.",
            );
        }
    }

    public function test_an_album_page_does_not_query_per_track(): void
    {
        // The album page builds a payload for every track, and each one asked
        // for its own resume position: a 14-track album ran 50 queries, and
        // the largest album here is 49 tracks.
        for ($i = 1; $i <= 14; $i++) {
            $this->track("Track {$i}", album: 'One Album');
        }

        $url = '/app/album?artist=' . urlencode('An Artist') . '&album=' . urlencode('One Album');

        $queries = $this->countQueries(fn () => $this->browse()->get($url)->assertOk());

        $this->assertLessThan(
            15,
            $queries,
            "The album page ran {$queries} queries; the resume positions are being fetched one at a time again.",
        );
    }

    public function test_an_item_page_does_not_query_per_album_track(): void
    {
        // The detail page queues the whole album behind the track being
        // viewed, so a one-track dead end becomes a record. Each of those
        // became a payload asking for its own resume position: 63 queries on
        // a real album, against 15 with the relation loaded once.
        for ($i = 1; $i <= 14; $i++) {
            $this->track("Track {$i}", album: 'One Album');
        }

        $item = MediaItem::query()->where('title', 'Track 1')->firstOrFail();

        $queries = $this->countQueries(
            fn () => $this->browse()->get("/app/item/{$item->id}")->assertOk(),
        );

        $this->assertLessThan(
            25,
            $queries,
            "The item page ran {$queries} queries; the album queue is asking per track again.",
        );
    }

    public function test_the_current_profile_is_resolved_once_per_request(): void
    {
        // Twenty call sites ask for this, and each built its own instance with
        // an empty cache before it was registered as a singleton.
        $this->actingAs($this->user);

        $first = app(CurrentProfile::class);
        $second = app(CurrentProfile::class);

        $this->assertSame($first, $second);
    }

    /** Signs in and unlocks, which is what the media routes require. */
    private function browse(): self
    {
        return $this->actingAs($this->user)->withSession([
            'profile_id' => $this->profile->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $run();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    private function track(string $title, ?string $album = null, string $artist = 'An Artist'): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => '/tmp/' . str($title)->slug() . '.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => $artist,
            'album' => $album,
        ]);

        return $item->fresh();
    }
}

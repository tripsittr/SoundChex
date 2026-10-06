<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\ShowMetadata;
use App\Models\User;
use App\Services\Metadata\Sources\Show\Tmdb;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shows and episodes get artwork from TMDB.
 *
 * Reported from a real library: every episode row was a grey TV glyph. The
 * cause was not the renderer and not the fallback — it was that **nothing
 * fetched the artwork in the first place**.
 *
 * `Tvdb` writes episode stills and is the only source that did, and it needs
 * its own API key. A server holding a TMDB key and no TVDB key therefore had
 * nothing fetching stills at all, while `Tmdb` was requesting the very episode
 * record that carries `still_path` and throwing it away.
 */
class ShowArtworkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        app(SettingsService::class)->set('tmdb_api_key', 'a-key', encrypt: true);
    }

    private function series(?string $cover = null): MediaItem
    {
        $series = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'The Midnight Gospel',
            'type' => MediaItemType::Show,
            'cover_image_url' => $cover,
            'processing_status' => ProcessingStatus::Complete,
        ]);

        ShowMetadata::create(['media_item_id' => $series->id, 'tmdb_id' => 90228]);

        return $series->fresh();
    }

    private function episode(MediaItem $series, ?string $cover = null): MediaItem
    {
        $episode = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'Taste of the King',
            'type' => MediaItemType::Show,
            'parent_id' => $series->id,
            'file_path' => 'shows/The Midnight Gospel S01E01.mkv',
            'cover_image_url' => $cover,
            'processing_status' => ProcessingStatus::Complete,
        ]);

        ShowMetadata::create([
            'media_item_id' => $episode->id,
            'season_number' => 1,
            'episode_number' => 1,
        ]);

        return $episode->fresh();
    }

    /** The shape TMDB actually returns for one episode. */
    private function fakeEpisode(?string $still = '/stillpath.jpg'): void
    {
        Http::fake([
            '*/tv/*/season/*/episode/*' => Http::response([
                'name' => 'Taste of the King',
                'air_date' => '2020-04-20',
                'still_path' => $still,
            ]),
            '*' => Http::response([], 404),
        ]);
    }

    /* ------------------------------------------------------- episodes --- */

    public function test_an_episode_gets_its_still_from_tmdb(): void
    {
        $series = $this->series();
        $episode = $this->episode($series);

        $this->fakeEpisode();

        app(Tmdb::class)->enrich($episode);

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/stillpath.jpg',
            $episode->fresh()->cover_image_url,
        );
    }

    /**
     * TMDB returns a bare path; the host and a size go in front of it. Storing
     * `/stillpath.jpg` as-is renders as a broken image — the same trap the
     * TVDB source documents for its own relative paths.
     */
    public function test_a_bare_path_becomes_a_full_url(): void
    {
        $series = $this->series();
        $episode = $this->episode($series);

        $this->fakeEpisode('stillpath.jpg'); // no leading slash

        app(Tmdb::class)->enrich($episode);

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/stillpath.jpg',
            $episode->fresh()->cover_image_url,
            'A path without a leading slash must not produce a doubled or missing separator.',
        );
    }

    /**
     * An episode with no still is left alone rather than given a broken URL.
     * TMDB genuinely has none for plenty of episodes.
     */
    public function test_a_missing_still_leaves_the_row_empty(): void
    {
        $series = $this->series();
        $episode = $this->episode($series);

        $this->fakeEpisode(null);

        app(Tmdb::class)->enrich($episode);

        $this->assertNull($episode->fresh()->cover_image_url);
    }

    /**
     * Artwork that arrived beside the file, or one a person chose, outranks
     * this — the rule every other source follows.
     */
    public function test_existing_artwork_is_not_overwritten(): void
    {
        $series = $this->series();
        $episode = $this->episode($series, cover: 'covers/mine.jpg');

        $this->fakeEpisode();

        app(Tmdb::class)->enrich($episode);

        $this->assertSame('covers/mine.jpg', $episode->fresh()->cover_image_url);
    }

    /* ------------------------------------------------------ backfill --- */

    /**
     * Fixing the source only helps items enriched afterwards.
     *
     * Enrichment does not re-run on its own, so an already-enriched episode
     * keeps its empty cover for ever — the reported library is full of them.
     * The backfill is what goes back.
     */
    public function test_the_backfill_fetches_artwork_for_existing_episodes(): void
    {
        $series = $this->series(cover: 'covers/series.jpg');
        $episode = $this->episode($series);

        $this->fakeEpisode();

        $this->artisan('library:show-artwork')->assertSuccessful();

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/stillpath.jpg',
            $episode->fresh()->cover_image_url,
        );
    }

    /**
     * A dry run reports and writes nothing, so the first thing anybody does
     * on a large library is safe.
     */
    public function test_a_dry_run_writes_nothing(): void
    {
        $series = $this->series(cover: 'covers/series.jpg');
        $episode = $this->episode($series);

        $this->fakeEpisode();

        $this->artisan('library:show-artwork --dry-run')->assertSuccessful();

        $this->assertNull($episode->fresh()->cover_image_url);
    }

    /**
     * Without a key every fetch would decline silently and the run would
     * report "filled 0" with no reason. It says so instead.
     */
    public function test_it_refuses_without_a_tmdb_key(): void
    {
        app(SettingsService::class)->forget('tmdb_api_key');

        $this->artisan('library:show-artwork')
            ->expectsOutputToContain('No TMDB API key')
            ->assertFailed();
    }

    /* --------------------------------------------------------- series --- */

    public function test_a_series_gets_its_poster_from_tmdb(): void
    {
        $series = $this->series();

        Http::fake([
            '*/tv/90228*' => Http::response([
                'id' => 90228,
                'name' => 'The Midnight Gospel',
                'poster_path' => '/poster.jpg',
            ]),
            '*' => Http::response([], 404),
        ]);

        app(Tmdb::class)->enrich($series);

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/poster.jpg',
            $series->fresh()->cover_image_url,
        );
    }

    /**
     * The series poster matters twice over: an episode with no still of its
     * own falls back to it, so a series without one leaves the whole list
     * blank however well the fallback works.
     */
    public function test_an_episode_without_a_still_falls_back_to_the_series_poster(): void
    {
        $series = $this->series(cover: 'covers/series.jpg');
        $episode = $this->episode($series);

        $this->fakeEpisode(null);

        app(Tmdb::class)->enrich($episode);

        $fresh = $episode->fresh()->load('parent');

        $this->assertNull($fresh->cover_image_url, 'The episode itself still has none.');
        $this->assertStringContainsString(
            'covers/series.jpg',
            (string) $fresh->coverUrl(),
            'But it should render the series poster rather than a placeholder.',
        );
    }
}

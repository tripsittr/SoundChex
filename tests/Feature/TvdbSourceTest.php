<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\ShowMetadata;
use App\Models\User;
use App\Services\Metadata\Sources\Show\Tvdb;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TVDB: episode artwork, the unused `tvdb_id`, and the blanks TMDB leaves.
 *
 * TMDB runs first at priority 1 and answers most of what a series needs, so
 * nothing here is a second opinion — every write goes through a blank check.
 * The one thing TMDB is weakest at is per-episode imagery, and an episode with
 * no still is a placeholder in every list it appears in.
 *
 * `show_metadata.tvdb_id` has existed since the table was created and nothing
 * has ever written to it.
 */
class TvdbSourceTest extends TestCase
{
    use RefreshDatabase;

    private Tvdb $source;

    private User $user;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('tvdb_api_key', 'test-key');

        $this->source = app(Tvdb::class);
        $this->user = User::factory()->create();
    }

    /* ------------------------------------------------------- supports() --- */

    public function test_it_declines_without_a_key(): void
    {
        app(SettingsService::class)->set('tvdb_api_key', '');

        $this->assertFalse($this->source->supports($this->series('The Simpsons')));
    }

    public function test_it_declines_for_anything_that_is_not_a_show(): void
    {
        $film = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Heat',
            'file_path' => 'media/library/heat.mkv',
            'owned' => true,
        ]);

        $this->assertFalse($this->source->supports($film));
    }

    public function test_it_supports_a_series_with_a_key(): void
    {
        $this->assertTrue($this->source->supports($this->series('The Simpsons')));
    }

    /* ---------------------------------------------------------- series --- */

    public function test_it_stores_the_tvdb_id_and_fills_what_tmdb_left_blank(): void
    {
        $series = $this->series('The Simpsons');

        Http::fake([
            '*/login' => Http::response(['data' => ['token' => 'a-token']]),
            '*/search*' => Http::response(['data' => [['tvdb_id' => '71663', 'name' => 'The Simpsons']]]),
            '*/series/71663/extended*' => Http::response(['data' => [
                'originalNetwork' => ['name' => 'FOX'],
                'status' => ['name' => 'Continuing'],
                'firstAired' => '1989-12-17',
                'lastAired' => '2026-05-21',
                'contentRatings' => [
                    ['country' => 'gbr', 'name' => '12'],
                    ['country' => 'usa', 'name' => 'TV-PG'],
                ],
            ]]),
        ]);

        $this->source->enrich($series);

        $meta = $series->fresh()->showMetadata;

        $this->assertSame(71663, (int) $meta->tvdb_id);
        $this->assertSame('FOX', $meta->network);
        $this->assertSame(1989, (int) $meta->first_air_year);
        $this->assertSame(2026, (int) $meta->last_air_year);
    }

    /**
     * TVDB's status wording is mapped onto what the column already holds.
     *
     * TMDB wrote this field first, so its vocabulary is the one stored. A
     * second source using its own words would make the field mean two things.
     */
    public function test_it_maps_the_status_onto_tmdbs_vocabulary(): void
    {
        $series = $this->series('The Simpsons');

        $this->fakeSeries(71663, ['status' => ['name' => 'Continuing']]);

        $this->source->enrich($series);

        $this->assertSame('Returning Series', $series->fresh()->showMetadata->status);
    }

    /**
     * A US certification is preferred when several are listed.
     *
     * TVDB returns every country's rating and the column holds one, so it has
     * to pick; matching the existing TMDB-sourced data keeps it comparable.
     */
    public function test_it_prefers_the_us_content_rating(): void
    {
        $series = $this->series('The Simpsons');

        $this->fakeSeries(71663, ['contentRatings' => [
            ['country' => 'gbr', 'name' => '12'],
            ['country' => 'usa', 'name' => 'TV-PG'],
        ]]);

        $this->source->enrich($series);

        $this->assertSame('TV-PG', $series->fresh()->showMetadata->content_rating);
    }

    /** TMDB ran first; its answer stands. */
    public function test_it_does_not_overwrite_what_tmdb_wrote(): void
    {
        $series = $this->series('The Simpsons');
        $series->showMetadata->forceFill(['network' => 'Sky One', 'status' => 'Ended'])->saveQuietly();

        $this->fakeSeries(71663, [
            'originalNetwork' => ['name' => 'FOX'],
            'status' => ['name' => 'Continuing'],
        ]);

        $this->source->enrich($series->fresh());

        $meta = $series->fresh()->showMetadata;

        $this->assertSame('Sky One', $meta->network);
        $this->assertSame('Ended', $meta->status);
    }

    /** A known id is used directly; nothing is searched for. */
    public function test_a_known_id_skips_the_search(): void
    {
        $series = $this->series('The Simpsons');
        $series->showMetadata->forceFill(['tvdb_id' => 71663])->saveQuietly();

        $this->fakeSeries(71663, ['originalNetwork' => ['name' => 'FOX']]);

        $this->source->enrich($series->fresh());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/search'));
    }

    /* --------------------------------------------------------- episodes --- */

    public function test_it_fills_an_episode_still_when_there_is_none(): void
    {
        [$series, $episode] = $this->episode(6, 1);
        $series->showMetadata->forceFill(['tvdb_id' => 71663])->saveQuietly();

        $this->fakeEpisodes(71663, [[
            'seasonNumber' => 6,
            'number' => 1,
            'name' => 'Bart of Darkness',
            'aired' => '1994-09-04',
            'image' => '/banners/episodes/71663/12345.jpg',
        ]]);

        $this->source->enrich($episode->fresh());

        $this->assertSame(
            'https://artworks.thetvdb.com/banners/episodes/71663/12345.jpg',
            $episode->fresh()->cover_image_url
        );
    }

    /** An absolute url is stored as given, not prefixed twice. */
    public function test_an_absolute_image_url_is_left_alone(): void
    {
        [$series, $episode] = $this->episode(6, 1);
        $series->showMetadata->forceFill(['tvdb_id' => 71663])->saveQuietly();

        $this->fakeEpisodes(71663, [[
            'seasonNumber' => 6, 'number' => 1, 'image' => 'https://cdn.test/still.jpg',
        ]]);

        $this->source->enrich($episode->fresh());

        $this->assertSame('https://cdn.test/still.jpg', $episode->fresh()->cover_image_url);
    }

    /**
     * The episode is matched on its own numbers, not trusted from the filter.
     *
     * Season and episode go to TVDB as query parameters. Writing one episode's
     * name and still onto a different file is the one failure this source must
     * not introduce, so the response is checked rather than assumed.
     */
    public function test_an_episode_from_the_wrong_slot_is_refused(): void
    {
        [$series, $episode] = $this->episode(6, 1);
        $series->showMetadata->forceFill(['tvdb_id' => 71663])->saveQuietly();

        // TVDB answers with a different episode than the one asked for.
        $this->fakeEpisodes(71663, [[
            'seasonNumber' => 7, 'number' => 4, 'name' => 'Bart Sells His Soul',
            'image' => 'https://cdn.test/wrong.jpg',
        ]]);

        $this->source->enrich($episode->fresh());

        $this->assertNull($episode->fresh()->cover_image_url);
        $this->assertNull($episode->fresh()->showMetadata->episode_title);
    }

    /** An episode whose series has no id yet simply waits for its own run. */
    public function test_an_episode_waits_for_its_series_to_be_identified(): void
    {
        [, $episode] = $this->episode(6, 1);

        Http::fake(['*' => Http::response(['data' => []])]);

        $this->source->enrich($episode->fresh());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/episodes/'));
    }

    /** An existing cover is left alone. */
    public function test_an_existing_cover_is_not_replaced(): void
    {
        [$series, $episode] = $this->episode(6, 1);
        $series->showMetadata->forceFill(['tvdb_id' => 71663])->saveQuietly();
        $episode->forceFill(['cover_image_url' => 'https://cdn.test/tmdb.jpg'])->saveQuietly();

        $this->fakeEpisodes(71663, [[
            'seasonNumber' => 6, 'number' => 1, 'image' => 'https://cdn.test/tvdb.jpg',
        ]]);

        $this->source->enrich($episode->fresh());

        $this->assertSame('https://cdn.test/tmdb.jpg', $episode->fresh()->cover_image_url);
    }

    /* ------------------------------------------------------------ token --- */

    /** One login serves many requests. */
    public function test_the_token_is_reused(): void
    {
        $this->fakeSeries(71663, ['originalNetwork' => ['name' => 'FOX']]);

        $this->source->enrich($this->series('The Simpsons'));
        $this->source->enrich($this->series('Futurama'));

        Http::assertSentCount(5); // 1 login + 2 searches + 2 series
    }

    /** A rejected token is dropped, so the next call logs in again. */
    public function test_a_rejected_token_is_discarded(): void
    {
        Cache::put('tvdb.token.'.hash('xxh128', 'test-key'), 'stale', now()->addDay());

        Http::fake([
            '*/login' => Http::response(['data' => ['token' => 'fresh']]),
            '*/search*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $this->source->enrich($this->series('The Simpsons'));

        $this->assertNull(Cache::get('tvdb.token.'.hash('xxh128', 'test-key')));
    }

    /** A wrong key writes nothing and does not throw. */
    public function test_a_failed_login_is_survived(): void
    {
        Http::fake(['*/login' => Http::response(['message' => 'forbidden'], 401)]);

        $series = $this->series('The Simpsons');

        $this->source->enrich($series);

        $this->assertNull($series->fresh()->showMetadata->tvdb_id);
    }

    /** Changing the key does not reuse the token issued for the old one. */
    public function test_the_token_is_keyed_by_the_api_key(): void
    {
        Cache::put('tvdb.token.'.hash('xxh128', 'test-key'), 'old-token', now()->addDay());

        app(SettingsService::class)->set('tvdb_api_key', 'a-different-key');

        $this->fakeSeries(71663, ['originalNetwork' => ['name' => 'FOX']]);

        app(Tvdb::class)->enrich($this->series('The Simpsons'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/login'));
    }

    /* ----------------------------------------------------------- setup --- */

    /** @param array<string, mixed> $data */
    private function fakeSeries(int $id, array $data): void
    {
        Http::fake([
            '*/login' => Http::response(['data' => ['token' => 'a-token']]),
            '*/search*' => Http::response(['data' => [['tvdb_id' => (string) $id]]]),
            '*/series/*/extended*' => Http::response(['data' => $data]),
        ]);
    }

    /** @param list<array<string, mixed>> $episodes */
    private function fakeEpisodes(int $id, array $episodes): void
    {
        Http::fake([
            '*/login' => Http::response(['data' => ['token' => 'a-token']]),
            '*/episodes/default*' => Http::response(['data' => ['episodes' => $episodes]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    private function series(string $title): MediaItem
    {
        self::$n++;

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Show,
            'title' => $title,
            'file_path' => 'media/library/series-'.self::$n,
            'owned' => true,
        ]);

        ShowMetadata::create(['media_item_id' => $item->id]);

        return $item->fresh();
    }

    /** @return array{0: MediaItem, 1: MediaItem} */
    private function episode(int $season, int $number): array
    {
        $series = $this->series('The Simpsons');

        self::$n++;

        $episode = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Show,
            'title' => 'S'.$season.'E'.$number,
            'file_path' => 'media/library/episode-'.self::$n.'.mp4',
            'owned' => true,
        ]);

        $episode->forceFill(['parent_id' => $series->id])->saveQuietly();

        ShowMetadata::create([
            'media_item_id' => $episode->id,
            'season_number' => $season,
            'episode_number' => $number,
        ]);

        return [$series->fresh(), $episode->fresh()];
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\SourceCatalogue;
use App\Services\Metadata\Sources\Movie\Omdb;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The scores and awards nothing else carries (#504).
 *
 * `movie_metadata.imdb_rating` and `rt_score` existed from the day the table
 * was created and **nothing ever wrote them** — null on every row — because
 * this source was never built and `omdb_api_key` was one of the seven keys the
 * integrations audit found that no code reads. Somebody pasting a key got a
 * green card and no change in behaviour.
 */
class OmdbRatingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        app(SettingsService::class)->set('omdb_api_key', 'a-key', encrypt: true);
    }

    /** A film with the IMDb id OMDb is keyed by. */
    private function film(?string $imdbId = 'tt0111161'): MediaItem
    {
        $item = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/a-film.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $item->movieMetadata()->create(['imdb_id' => $imdbId]);

        return $item->fresh();
    }

    /** The real OMDb shape, as the live API returns it. */
    private function fakeOmdb(array $overrides = []): void
    {
        Http::fake(['*omdbapi.com*' => Http::response(array_merge([
            'Response' => 'True',
            'Title' => 'The Shawshank Redemption',
            'imdbRating' => '9.3',
            'Metascore' => '82',
            'Awards' => 'Nominated for 7 Oscars. 21 wins & 43 nominations total',
            'Ratings' => [
                ['Source' => 'Internet Movie Database', 'Value' => '9.3/10'],
                ['Source' => 'Rotten Tomatoes', 'Value' => '89%'],
                ['Source' => 'Metacritic', 'Value' => '82/100'],
            ],
        ], $overrides), 200)]);
    }

    public function test_it_fills_the_scores_that_were_always_null(): void
    {
        $this->fakeOmdb();

        $film = $this->film();

        app(Omdb::class)->enrich($film);

        $meta = $film->fresh()->movieMetadata;

        $this->assertSame(9.3, (float) $meta->imdb_rating);
        $this->assertSame(89, (int) $meta->rt_score);
        $this->assertSame(82, (int) $meta->metascore);
    }

    public function test_it_stores_the_awards_sentence_as_written(): void
    {
        // Not parsed into counts: "Nominated for 7 Oscars. 21 wins & 43
        // nominations total" is what a detail page shows, and parsing it would
        // invent structure the source does not have.
        $this->fakeOmdb();

        $film = $this->film();

        app(Omdb::class)->enrich($film);

        $this->assertSame(
            'Nominated for 7 Oscars. 21 wins & 43 nominations total',
            $film->fresh()->movieMetadata->awards,
        );
    }

    public function test_rotten_tomatoes_is_read_from_the_ratings_array(): void
    {
        // It appears only there, never as a top-level field, and the array is
        // absent entirely for an unrated title.
        $this->fakeOmdb(['Ratings' => [['Source' => 'Rotten Tomatoes', 'Value' => '42%']]]);

        $film = $this->film();

        app(Omdb::class)->enrich($film);

        $this->assertSame(42, (int) $film->fresh()->movieMetadata->rt_score);
    }

    public function test_a_title_with_no_ratings_array_is_not_a_failure(): void
    {
        $this->fakeOmdb(['Ratings' => [], 'imdbRating' => 'N/A', 'Metascore' => 'N/A', 'Awards' => 'N/A']);

        $film = $this->film();

        app(Omdb::class)->enrich($film);

        $meta = $film->fresh()->movieMetadata;

        // OMDb's literal "N/A" must not be stored as a value.
        $this->assertNull($meta->imdb_rating);
        $this->assertNull($meta->rt_score);
        $this->assertNull($meta->awards);
    }

    public function test_a_failure_body_arriving_as_http_200_is_not_treated_as_success(): void
    {
        // OMDb answers 200 with {"Response":"False","Error":"..."} for a bad id
        // AND for a bad key, so the status alone means nothing. Verified
        // against the live API.
        // The body carries real-looking values ALONGSIDE the failure flag, so
        // the test fails if the flag is ignored. A bare error body proves
        // nothing: there is nothing in it to write either way, and an earlier
        // version of this test passed against an implementation that ignored
        // `Response` entirely.
        Http::fake(['*omdbapi.com*' => Http::response([
            'Response' => 'False',
            'Error' => 'Error getting data.',
            'imdbRating' => '9.9',
            'Metascore' => '99',
            'Awards' => 'Should never be stored',
            'Ratings' => [['Source' => 'Rotten Tomatoes', 'Value' => '99%']],
        ], 200)]);

        $film = $this->film();

        app(Omdb::class)->enrich($film);

        $meta = $film->fresh()->movieMetadata;

        $this->assertNull($meta->imdb_rating, 'A failure body was read as success.');
        $this->assertNull($meta->rt_score);
        $this->assertNull($meta->awards);
    }

    public function test_it_never_overwrites_a_score_already_there(): void
    {
        // A value a person corrected, or one a later source wrote, outranks
        // this -- the same rule every other source follows.
        $this->fakeOmdb();

        $film = $this->film();
        $film->movieMetadata->forceFill(['imdb_rating' => 1.0])->save();

        app(Omdb::class)->enrich($film->fresh());

        $meta = $film->fresh()->movieMetadata;

        $this->assertSame(1.0, (float) $meta->imdb_rating, 'An existing score was overwritten.');
        // The empty ones still fill.
        $this->assertSame(89, (int) $meta->rt_score);
    }

    public function test_it_declines_without_an_imdb_id(): void
    {
        // OMDb can search by title, but TMDB has already identified the item
        // far more carefully, and a title search here would risk hanging one
        // film's ratings on another.
        $film = $this->film(null);

        $this->assertFalse(app(Omdb::class)->supports($film));
    }

    public function test_it_declines_without_a_key(): void
    {
        app(SettingsService::class)->forget('omdb_api_key');

        $this->assertFalse(app(Omdb::class)->supports($this->film()));
    }

    public function test_an_unreachable_service_changes_nothing(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $film = $this->film();

        app(Omdb::class)->enrich($film);

        $this->assertNull($film->fresh()->movieMetadata->imdb_rating);
    }

    public function test_the_key_is_no_longer_advertised_as_unimplemented(): void
    {
        // It was one of the seven the integrations page collected while nothing
        // read it. Leaving it on that list after implementing the source would
        // tell the user their key does nothing.
        $catalogue = app(SourceCatalogue::class);

        $this->assertContains('omdb_api_key', $catalogue->liveKeys());
        $this->assertFalse($catalogue->isUnimplemented('omdb_api_key'));
    }
}

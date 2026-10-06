<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cast and crew over the API (S-412).
 *
 * Deliberately not part of the library sync: 8,323 items in a real library
 * carry credits, and mirroring them all so a detail page can show a handful
 * would make every device download every actor of every film.
 */
class MediaDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function film(): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'movies/a-film.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    public function test_it_returns_the_overview(): void
    {
        $film = $this->film();
        $film->forceFill(['notes' => 'A strange doorway appears in a basement.'])->saveQuietly();

        $response = $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/details");

        $response->assertOk();
        $response->assertJsonPath('overview', 'A strange doorway appears in a basement.');
    }

    /**
     * An item nobody has described, and that enrichment has not reached, has
     * no overview. The key is still present so a client does not have to
     * distinguish "absent" from "empty" (S-412).
     */
    public function test_an_item_with_no_overview_returns_null(): void
    {
        $film = $this->film();

        $response = $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/details");

        $response->assertOk();
        $response->assertJsonPath('overview', null);
    }

    /**
     * The overview is deliberately absent from the catalogue row.
     *
     * A synopsis is a paragraph, and the library payload is mirrored in full
     * by every device — thousands of paragraphs to show one at a time. It
     * belongs on the endpoint the detail page already calls.
     */
    public function test_the_overview_is_not_in_the_library_payload(): void
    {
        $film = $this->film();
        $film->forceFill(['notes' => 'A strange doorway appears in a basement.'])->saveQuietly();

        $response = $this->actingAs($this->user)->getJson('/api/v1/library');

        $response->assertOk();
        $this->assertStringNotContainsString(
            'strange doorway',
            $response->getContent(),
            'the synopsis must not ride along in the catalogue sync',
        );
    }

    public function test_it_splits_cast_from_crew(): void
    {
        $film = $this->film();

        $actor = Person::create(['name' => 'An Actor']);
        $director = Person::create(['name' => 'A Director']);

        $film->people()->attach($actor->id, ['role' => 'actor', 'character' => 'Someone', 'sort_order' => 1]);
        $film->people()->attach($director->id, ['role' => 'director', 'sort_order' => 2]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/items/{$film->id}/details");

        $response->assertOk();
        $response->assertJsonPath('cast.0.name', 'An Actor');
        $response->assertJsonPath('cast.0.character', 'Someone');
        $response->assertJsonPath('crew.0.name', 'A Director');
        $response->assertJsonPath('crew.0.role', 'director');
    }

    public function test_it_keeps_billing_order(): void
    {
        // Cast order is meaningful — the lead is first, not whoever was
        // inserted first.
        $film = $this->film();

        $second = Person::create(['name' => 'Second Billed']);
        $first = Person::create(['name' => 'First Billed']);

        $film->people()->attach($second->id, ['role' => 'actor', 'sort_order' => 2]);
        $film->people()->attach($first->id, ['role' => 'actor', 'sort_order' => 1]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->assertJsonPath('cast.0.name', 'First Billed');
    }

    public function test_an_item_with_no_credits_returns_empty_lists(): void
    {
        $film = $this->film();

        $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->assertOk()
            ->assertJsonPath('cast', [])
            ->assertJsonPath('crew', []);
    }

    public function test_it_requires_authentication(): void
    {
        $film = $this->film();

        $this->getJson("/api/v1/items/{$film->id}/details")->assertUnauthorized();
    }

    public function test_the_movie_payload_carries_the_wider_metadata(): void
    {
        $film = $this->film();

        $film->movieMetadata()->create([
            'tagline' => 'A tagline',
            'studio' => 'A Studio',
            'imdb_rating' => 7.5,
            'release_year' => 2020,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/library');

        $item = collect($response->json('items'))->firstWhere('id', $film->id);

        $this->assertSame('A tagline', $item['meta']['tagline']);
        $this->assertSame('A Studio', $item['meta']['studio']);
    }

    /* ------------------------------------------------ genres & facts ---- */

    public function test_genres_are_returned_rather_than_a_list_of_nulls(): void
    {
        // `media_tags` has no `name` column -- it is `type` and `value` -- so
        // `pluck('name')` returned null for every row and the endpoint sent
        // `[null, null, null]` to every client while 11,704 genre tags sat in
        // the database. The film this was found on had "Horror", "Mystery" and
        // "Science Fiction" stored and was sending three nulls.
        $film = $this->film();

        foreach (['Horror', 'Mystery', 'Science Fiction'] as $genre) {
            $film->tags()->create(['type' => 'genre', 'value' => $genre, 'source' => 'api']);
        }

        $body = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->assertOk()
            ->json();

        $this->assertSame(['Horror', 'Mystery', 'Science Fiction'], $body['genres']);
        $this->assertNotContains(null, $body['tags'], 'A null reached the tag list.');
    }

    public function test_a_non_genre_tag_is_not_reported_as_a_genre(): void
    {
        // `tags` carries everything; `genres` carries only genres, because a
        // detail page shows them on their own line and every client would
        // otherwise filter the same way.
        $film = $this->film();

        $film->tags()->create(['type' => 'genre', 'value' => 'Horror', 'source' => 'api']);
        $film->tags()->create(['type' => 'keyword', 'value' => 'haunted house', 'source' => 'api']);

        $body = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->json();

        $this->assertSame(['Horror'], $body['genres']);
        $this->assertContains('haunted house', $body['tags']);
    }

    public function test_the_detail_block_carries_what_a_detail_page_shows(): void
    {
        $film = $this->film();

        $film->movieMetadata()->create([
            'director' => 'A Director',
            'studio' => 'A Studio',
            'release_year' => 2020,
            'runtime_minutes' => 111,
            'mpaa_rating' => 'R',
            'tagline' => 'A tagline',
            'imdb_id' => 'tt1234567',
        ]);

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->json('detail');

        $this->assertSame('movie', $detail['type']);
        $this->assertSame('A Director', $detail['director']);
        $this->assertSame(2020, $detail['year']);
        $this->assertSame(111, $detail['runtime_minutes']);
        $this->assertSame('R', $detail['content_rating']);
        $this->assertSame('tt1234567', $detail['imdb_id']);
    }

    public function test_a_key_that_does_not_apply_is_absent_rather_than_null(): void
    {
        // One shape for every type, so a client renders what is present and
        // skips what is not rather than testing each key for null.
        $film = $this->film();

        $film->movieMetadata()->create(['director' => 'A Director']);

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->json('detail');

        $this->assertArrayNotHasKey('artist', $detail, 'A music key reached a film.');
        $this->assertArrayNotHasKey('season_count', $detail, 'A show key reached a film.');
        $this->assertArrayHasKey('director', $detail);
    }

    public function test_the_ratings_are_carried_once_something_writes_them(): void
    {
        // imdb_rating and rt_score have existed all along with nothing filling
        // them -- OMDb is the source and is not implemented. The endpoint
        // carries them now, so they appear without any client changing.
        $film = $this->film();

        $film->movieMetadata()->create(['imdb_rating' => 7.5, 'rt_score' => 88]);

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->json('detail');

        $this->assertSame(7.5, (float) $detail['imdb_rating']);
        $this->assertSame(88, (int) $detail['rt_score']);
    }

    public function test_the_detail_block_carries_awards_and_metascore(): void
    {
        // The whole point of implementing OMDb (#504): these had nowhere to go
        // at all -- no awards column existed anywhere -- and the two score
        // columns that did exist were null on every row.
        $film = $this->film();

        $film->movieMetadata()->create([
            'imdb_rating' => 9.3,
            'rt_score' => 89,
            'metascore' => 82,
            'awards' => 'Nominated for 7 Oscars. 21 wins & 43 nominations total',
        ]);

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->json('detail');

        $this->assertSame(9.3, (float) $detail['imdb_rating']);
        $this->assertSame(89, (int) $detail['rt_score']);
        $this->assertSame(82, (int) $detail['metascore']);
        $this->assertStringContainsString('7 Oscars', $detail['awards']);
    }

    public function test_a_show_carries_the_same_scores_as_a_film(): void
    {
        // OMDb answers for a series by IMDb id exactly as it does for a film,
        // and a show with no scores beside a film that has them would read as a
        // broken page rather than a gap in the data.
        $show = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Show',
            'type' => MediaItemType::Show,
            'file_path' => 'shows/a-show.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $show->showMetadata()->create(['imdb_rating' => 8.7, 'rt_score' => 94, 'awards' => 'Won 3 Emmys.']);

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$show->id}/details")
            ->json('detail');

        $this->assertSame(8.7, (float) $detail['imdb_rating']);
        $this->assertSame(94, (int) $detail['rt_score']);
        $this->assertSame('Won 3 Emmys.', $detail['awards']);
    }

    public function test_the_vote_count_rides_with_the_rating(): void
    {
        // A score without a count is much weaker than it looks, which is why
        // IMDb never shows one alone. OMDb returns `imdbVotes` on every
        // lookup and nothing stored it (#511).
        $film = $this->film();

        $film->movieMetadata()->create(['imdb_rating' => 9.3, 'imdb_votes' => 3235958]);

        $detail = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->json('detail');

        $this->assertSame(9.3, (float) $detail['imdb_rating']);

        // An integer, not "3,235,958": formatting is the client's decision
        // against its own locale, not one baked into the payload.
        $this->assertSame(3235958, $detail['imdb_votes']);
    }
}

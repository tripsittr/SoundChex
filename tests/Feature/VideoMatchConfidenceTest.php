<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Movie\Tmdb;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `MatchConfidence::Exact` is the one value that permits moving a video file,
 * so what sets it decides whether a wrong match gets a wrong *filename and
 * folder* as well as a wrong row (#455).
 *
 * The bug these cover: `enrich()` called `promoteTitle()` — which overwrites
 * the item's title with TMDB's — *before* `recordConfidence()`, which decides
 * `Exact` by comparing TMDB's title to the item's. After promotion those are
 * the same string by construction, so every title-search match scored `Exact`,
 * including the wrong ones.
 */
class VideoMatchConfidenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // The source reads its key from SettingsService, not config.
        app(SettingsService::class)->set('tmdb_api_key', 'test-key');
    }

    public function test_a_title_search_that_returns_a_different_film_is_not_exact(): void
    {
        // The library has "Backrooms"; TMDB's best guess is a different film.
        // Before the fix this scored Exact, because the comparison ran after
        // the title had already been replaced with "The Backrooms Movie".
        $this->fakeTmdb(id: 999, title: 'The Backrooms Movie');

        $item = $this->movie('Backrooms');

        app(Tmdb::class)->enrich($item);

        $this->assertSame(
            MatchConfidence::Fuzzy,
            $item->fresh()->match_confidence,
            'A title search landing on a differently-named film is a guess, not an exact match.',
        );
    }

    public function test_a_fuzzy_video_match_is_not_allowed_to_move_the_file(): void
    {
        // The consequence, stated directly: this is why the above matters.
        $this->fakeTmdb(id: 999, title: 'The Backrooms Movie');

        $item = $this->movie('Backrooms');

        app(Tmdb::class)->enrich($item);

        $this->assertFalse(
            $item->fresh()->match_confidence->allowsFileMove(),
            'A guessed match must not rename and refile the user\'s film.',
        );
    }

    public function test_a_title_search_that_returns_the_same_title_is_exact(): void
    {
        // The genuine case still works: the parsed title and TMDB's agree, so
        // nothing was guessed and the file may be filed.
        $this->fakeTmdb(id: 27205, title: 'Inception');

        $item = $this->movie('Inception');

        app(Tmdb::class)->enrich($item);

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
    }

    public function test_casing_and_surrounding_space_do_not_make_a_match_fuzzy(): void
    {
        // "inception" vs "Inception" is the same film, not a guess. The old
        // comparison was case-insensitive and that part was right.
        $this->fakeTmdb(id: 27205, title: 'Inception');

        $item = $this->movie('  inception ');

        app(Tmdb::class)->enrich($item);

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
    }

    public function test_a_year_in_the_parsed_title_does_not_make_a_match_fuzzy(): void
    {
        // Filenames leave the year in the title ("Inception 2010"), which the
        // source already splits out before searching. Scoring must compare what
        // was searched, or every correctly-matched film with a year in its
        // filename would read Fuzzy and stop being filed.
        $this->fakeTmdb(id: 27205, title: 'Inception');

        $item = $this->movie('Inception 2010');

        app(Tmdb::class)->enrich($item);

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
    }

    public function test_a_match_resolved_by_id_is_exact_whatever_the_title_says(): void
    {
        // An id is the identity. TMDB's title for it is authoritative even when
        // the local title was wrong, which is the whole point of having the id.
        $this->fakeTmdb(id: 27205, title: 'Inception');

        $item = $this->movie('whatever the file was called');
        $item->movieMetadata()->update(['tmdb_id' => 27205]);

        app(Tmdb::class)->enrich($item->fresh());

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
    }

    /**
     * A TMDB that answers every search with one film and every detail lookup
     * with that film.
     */
    private function fakeTmdb(int $id, string $title): void
    {
        Http::fake([
            '*/search/movie*' => Http::response([
                'results' => [['id' => $id, 'title' => $title]],
            ]),
            '*/movie/'.$id.'*' => Http::response([
                'id' => $id,
                'title' => $title,
                'release_date' => '2010-07-16',
                'runtime' => 148,
            ]),
            '*' => Http::response([], 404),
        ]);
    }

    private function movie(string $title): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => 'media/unsorted/'.str($title)->slug().'.mkv',
            'owned' => true,
        ]);

        $item->movieMetadata()->create([]);

        return $item->fresh();
    }
}

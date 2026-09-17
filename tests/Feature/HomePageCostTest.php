<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the home page costs, and that trimming that cost did not trim the page.
 *
 * It ran 140 queries against a real library for two reasons, both of which
 * look harmless in isolation:
 *
 * 1. It asked `rowsForType()` — the *whole* browse page for a type: four fixed
 *    rails plus up to four genre rails — and kept `[0]`. Seven-eighths of the
 *    work was built, eager-loaded and discarded, once per media type.
 *
 * 2. Every one of those queries eager-loaded all four metadata tables. A music
 *    query asked `movie_metadata`, `show_metadata` and `book_metadata` for rows
 *    it knew could not exist: three round-trips per batch that always returned
 *    nothing.
 *
 * The counting tests are the regression guard. The rendering tests are the
 * more important half — a page that got cheaper by quietly losing a rail or a
 * subtitle is not a fix, and (2) in particular is exactly the kind of change
 * that shows up as a missing artist name rather than as a failure.
 */
class HomePageCostTest extends TestCase
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

    public function test_the_home_page_cost_does_not_scale_with_the_library(): void
    {
        $this->library();

        $small = $this->countQueries(fn () => $this->browse()->get('/app')->assertOk());

        // Ten times the rows, and several more genres — which used to mean
        // several more discarded genre rails per type.
        for ($i = 1; $i <= 40; $i++) {
            $this->item(MediaItemType::Music, "Filler {$i}", genre: 'Genre ' . ($i % 8));
        }

        $large = $this->countQueries(fn () => $this->browse()->get('/app')->assertOk());

        // Not equality: the profile singleton is already warm on the second
        // request in one process, so the larger run is legitimately one query
        // cheaper. What matters is that it does not grow — the discarded genre
        // rails scaled with how many distinct genres the library held.
        $this->assertLessThanOrEqual(
            $small,
            $large,
            "The home page ran {$small} queries on a small library and {$large} on a larger one; "
                . 'something is querying per row or per genre again.',
        );
    }

    public function test_the_home_page_runs_a_bounded_number_of_queries(): void
    {
        $this->library();

        $queries = $this->countQueries(fn () => $this->browse()->get('/app')->assertOk());

        // It ran 140 against the real library: four types x eight rails, each
        // carrying six eager-load batches. A generous ceiling, because the
        // point is that it is bounded and small, not that it is exactly N.
        $this->assertLessThan(
            40,
            $queries,
            "The home page ran {$queries} queries; the discarded rails are back.",
        );
    }

    public function test_a_query_does_not_load_metadata_for_other_types(): void
    {
        $this->library();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->browse()->get('/app')->assertOk();

        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // Each metadata table is still asked for — every type is on this page —
        // but no longer once per rail per type. Four types, one rail each, and
        // the hero: nothing like the 17 batches this used to run.
        foreach (['music_metadata', 'movie_metadata', 'show_metadata', 'book_metadata'] as $table) {
            $hits = $log->filter(fn (string $q) => str_contains($q, $table))->count();

            $this->assertLessThan(
                4,
                $hits,
                "{$table} was queried {$hits} times; metadata for unrelated types is being eager-loaded again.",
            );
        }
    }

    public function test_every_type_still_gets_its_rail_with_its_own_subtitle(): void
    {
        $this->library();

        $html = $this->browse()->get('/app')->assertOk()->getContent();

        // The rails themselves.
        foreach (['Music', 'Movies', 'Shows', 'Books'] as $heading) {
            $this->assertStringContainsString($heading, $html, "The {$heading} rail is missing.");
        }

        // The items on them.
        foreach (['A Song', 'A Film', 'An Episode', 'A Novel'] as $title) {
            $this->assertStringContainsString($title, $html, "{$title} is missing from the home page.");
        }

        // And their subtitles, which come from four different metadata tables
        // and are what a narrowed eager-load would silently drop.
        foreach (['An Artist', 'A Director', 'A Creator', 'An Author'] as $subtitle) {
            $this->assertStringContainsString(
                $subtitle,
                $html,
                "The subtitle '{$subtitle}' is missing; its metadata relation is not being loaded.",
            );
        }
    }

    public function test_the_hero_still_prefers_something_with_artwork(): void
    {
        // Newest first, and the newest has no artwork — so a hero chosen by
        // date alone picks the wrong one. The timestamps are set explicitly:
        // two rows created in the same second tie on `latest()`, and the tie
        // broke in favour of the right answer by luck, which made the first
        // version of this test pass with the fix reverted.
        $this->item(MediaItemType::Movie, 'Has Artwork', cover: 'covers/has.jpg')
            ->forceFill(['created_at' => now()->subDay()])->save();

        $this->item(MediaItemType::Movie, 'No Artwork')
            ->forceFill(['created_at' => now()])->save();

        $html = $this->browse()->get('/app')->assertOk()->getContent();

        // Scoped to the hero banner. Both films also appear in the rail below
        // it, so searching the whole page proves nothing about which one was
        // chosen — the first assertion written here passed with the fix
        // reverted for exactly that reason.
        $this->assertSame(1, preg_match('/<section class="relative isolate.*?<\/section>/s', $html, $hero));

        $this->assertStringContainsString('Has Artwork', $hero[0], 'The hero ignored the item with artwork.');
        $this->assertStringNotContainsString('No Artwork', $hero[0], 'The hero picked the item without artwork.');
    }

    public function test_the_hero_falls_back_when_nothing_has_artwork(): void
    {
        // The fallback used to be a second query. Folding it into one ordering
        // must not lose the case it existed for.
        $this->item(MediaItemType::Movie, 'Only Option');

        $html = $this->browse()->get('/app')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<section class="relative isolate.*?<\/section>/s', $html, $hero));
        $this->assertStringContainsString('Only Option', $hero[0], 'Nothing was featured at all.');
    }

    /** One of everything, so all four metadata relations are exercised. */
    private function library(): void
    {
        $this->item(MediaItemType::Music, 'A Song', genre: 'Rock');
        $this->item(MediaItemType::Movie, 'A Film', genre: 'Drama');
        $this->item(MediaItemType::Show, 'An Episode', genre: 'Comedy');
        $this->item(MediaItemType::Book, 'A Novel', genre: 'Fiction');
    }

    private function item(
        MediaItemType $type,
        string $title,
        ?string $genre = null,
        ?string $cover = null,
    ): MediaItem {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title,
            'file_path' => '/tmp/' . str($title)->slug(),
            'owned' => true,
            'cover_image_url' => $cover,
        ]);

        match ($type) {
            MediaItemType::Music => $item->musicMetadata()->create([
                'artist' => 'An Artist',
                'primary_artist' => 'An Artist',
                'album' => 'An Album',
            ]),
            MediaItemType::Movie => $item->movieMetadata()->create(['director' => 'A Director']),
            MediaItemType::Show => $item->showMetadata()->create(['creator' => 'A Creator']),
            MediaItemType::Book => $item->bookMetadata()->create(['author' => 'An Author']),
        };

        if ($genre !== null) {
            $item->tags()->create(['type' => 'genre', 'value' => $genre]);
        }

        return $item->fresh();
    }

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
}

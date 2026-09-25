<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\AlbumTitleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collapsing albums that differ only by capitalization (S-303): the most common
 * spelling in the library wins.
 */
class AlbumTitleNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_it_rewrites_variants_to_the_most_common_spelling(): void
    {
        // "Have a Nice Day" x3 beats "Have A Nice Day" x1 (same artist).
        $this->track('Have a Nice Day', 'Bon Jovi');
        $this->track('Have a Nice Day', 'Bon Jovi');
        $this->track('Have a Nice Day', 'Bon Jovi');
        $odd = $this->track('Have A Nice Day', 'Bon Jovi');

        $changed = app(AlbumTitleNormalizer::class)->normalizeLibrary();

        $this->assertSame(1, $changed);
        $this->assertSame('Have a Nice Day', $odd->musicMetadata->fresh()->album);
    }

    public function test_it_leaves_a_consistent_album_alone(): void
    {
        $this->track('OK Computer', 'Radiohead');
        $this->track('OK Computer', 'Radiohead');

        $this->assertSame(0, app(AlbumTitleNormalizer::class)->normalizeLibrary());
    }

    public function test_it_scopes_by_artist(): void
    {
        // Same album name, different artists — not the same album, not merged.
        $this->track('Greatest Hits', 'Artist One');
        $this->track('greatest hits', 'Artist Two');

        // Each artist has only one spelling, so nothing to normalize.
        $this->assertSame(0, app(AlbumTitleNormalizer::class)->normalizeLibrary());
    }

    public function test_a_new_album_keeps_its_own_casing(): void
    {
        // Nothing else in the library — the incoming spelling is canonical.
        $canonical = app(AlbumTitleNormalizer::class)
            ->canonicalForAlbum('New Artist', 'A Brand New Record');

        $this->assertSame('A Brand New Record', $canonical);
    }

    public function test_it_collapses_edition_and_punctuation_variants(): void
    {
        // The same album, split by deluxe/remaster tails and bracket style.
        $this->track('(What\'s the Story) Morning Glory?', 'Oasis');
        $this->track('(What\'s the Story) Morning Glory?', 'Oasis');
        $this->track('[What\'s The Story] Morning Glory', 'Oasis');
        $this->track('(What\'s The Story) Morning Glory? (Deluxe Remastered Edition)', 'Oasis');

        $changed = app(AlbumTitleNormalizer::class)->normalizeLibrary();

        // Two variants rewritten onto the most common plain spelling.
        $this->assertSame(2, $changed);
        $albums = \App\Models\MusicMetadata::pluck('album')->unique()->values()->all();
        $this->assertSame(['(What\'s the Story) Morning Glory?'], $albums);
    }

    public function test_it_does_not_merge_numbered_sequels(): void
    {
        // "(II)" and "(Part IV/V)" are different releases, not editions.
        $this->track('I No Longer Fear the Razor', '$uicideboy$');
        $this->track('I No Longer Fear the Razor (II)', '$uicideboy$');
        $this->track('Kill Yourself (Part IV)', '$uicideboy$');
        $this->track('Kill Yourself (Part V)', '$uicideboy$');

        $this->assertSame(0, app(AlbumTitleNormalizer::class)->normalizeLibrary());
    }

    public function test_a_deluxe_edition_collapses_onto_the_plain_album_at_enrichment(): void
    {
        $this->track('Urban Hymns', 'The Verve');
        $this->track('Urban Hymns', 'The Verve');

        // A newly-enriched deluxe pressing adopts the plain album already there.
        $canonical = app(AlbumTitleNormalizer::class)
            ->canonicalForAlbum('The Verve', 'Urban Hymns (Deluxe / Remastered 2016)');

        $this->assertSame('Urban Hymns', $canonical);
    }

    private function track(string $album, string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Track',
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist, 'album' => $album]);

        return $item;
    }
    /* ------------------------------------------ stylised letters (S-383) --- */

    public function test_a_dollar_written_for_an_s_keys_the_same_as_the_letter(): void
    {
        // "$UICIDEBOY$" means an S, but punctuation stripping deleted the
        // character outright — so the same album keyed two ways and showed
        // twice on the artist page.
        $normalizer = app(AlbumTitleNormalizer::class);

        $this->assertSame(
            $normalizer->canonicalKey('DirtierNastierSuicide'),
            $normalizer->canonicalKey('DIRTIERNASTIER$UICIDE'),
        );
    }

    public function test_albums_that_merely_share_a_stylised_family_stay_apart(): void
    {
        // Dirtier, Dirtiest and Dirty are three records, not one.
        $normalizer = app(AlbumTitleNormalizer::class);

        $keys = array_map(
            fn (string $album): string => $normalizer->canonicalKey($album),
            ['DIRTYNASTY$UICIDE', 'DIRTIERNASTIER$UICIDE', 'DIRTIESTNASTIEST$UICIDE'],
        );

        $this->assertCount(3, array_unique($keys));
    }

    /**
     * Digits are deliberately not folded.
     *
     * Folding them was tried and reverted: it turns "Blink-182" into
     * "blinki82" and "Sum 41" into "sum ai". A wrong merge hides music, while
     * a missed one only duplicates a tile — so the cautious failure is the
     * right one.
     */
    public function test_numbers_in_a_title_are_left_alone(): void
    {
        $normalizer = app(AlbumTitleNormalizer::class);

        $this->assertNotSame(
            $normalizer->canonicalKey('Album 3'),
            $normalizer->canonicalKey('Album III'),
        );
        $this->assertSame('blink182', $normalizer->canonicalKey('Blink-182'));
        $this->assertSame('sum 41', $normalizer->canonicalKey('Sum 41'));
    }

    public function test_a_title_that_is_only_punctuation_keeps_its_identity(): void
    {
        // Ed Sheeran's "=" and "+" and XXXTENTACION's "?" all strip to an
        // empty key, which made every one of them the same album. Found in
        // the real library while fixing the $/S collision.
        $normalizer = app(AlbumTitleNormalizer::class);

        $keys = array_map(
            fn (string $album): string => $normalizer->canonicalKey($album),
            ['=', '?', '+'],
        );

        $this->assertCount(3, array_unique($keys));
        $this->assertNotContains('', $keys);
    }

}

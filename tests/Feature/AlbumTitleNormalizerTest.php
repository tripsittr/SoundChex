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
}

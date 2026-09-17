<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Music\FileTagger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promoting a real title over the filename it was catalogued with.
 *
 * The scanner uses the filename as a placeholder and FileTagger replaces it
 * once the tags are read — but only when the title still equals the filename.
 * LibraryOrganizer renames the file before that runs, so "Gold" on disk became
 * "2136 Gold - Imagine Dragons.mp3" while the title stayed "Gold - Imagine
 * Dragons": the equality failed and the tag was never promoted. 4,221 tracks,
 * 72% of the library, printing their artist twice.
 *
 * The guard it replaces existed for a good reason, and these test that reason
 * at least as hard as the fix: a title someone typed must survive.
 */
class TitlePromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_the_tag_title_over_a_filename_one(): void
    {
        $item = $this->track('Gold - Imagine Dragons', 'Imagine Dragons', 'Gold - Imagine Dragons.mp3');

        $this->promote($item, 'Gold');

        $this->assertSame('Gold', $item->fresh()->title);
    }

    public function test_it_promotes_after_the_filer_has_added_a_track_number(): void
    {
        // The exact shape found in the library: the rename happens before
        // enrichment, so the filename carries a number the title does not.
        $item = $this->track('Gold - Imagine Dragons', 'Imagine Dragons', '2136 Gold - Imagine Dragons.mp3');

        $this->promote($item, 'Gold');

        $this->assertSame('Gold', $item->fresh()->title);
    }

    public function test_it_promotes_when_the_title_is_still_the_filename(): void
    {
        // An upload enrichment reached before anything renamed it.
        $item = $this->track('906 Stressed Out', 'Twenty One Pilots', '906 Stressed Out.mp3');

        $this->promote($item, 'Stressed Out');

        $this->assertSame('Stressed Out', $item->fresh()->title);
    }

    public function test_it_handles_an_em_dash_as_well_as_a_hyphen(): void
    {
        $item = $this->track('Gold — Imagine Dragons', 'Imagine Dragons', 'Gold — Imagine Dragons.mp3');

        $this->promote($item, 'Gold');

        $this->assertSame('Gold', $item->fresh()->title);
    }

    /* ------------------------------------------------- what must not move -- */

    public function test_a_title_someone_typed_is_left_alone(): void
    {
        // The whole reason the old guard existed. A hand-corrected title must
        // survive every future enrichment run.
        $item = $this->track('Gold (Live at Wembley)', 'Imagine Dragons', '2136 Gold - Imagine Dragons.mp3');

        $this->promote($item, 'Gold');

        $this->assertSame('Gold (Live at Wembley)', $item->fresh()->title);
    }

    public function test_a_title_that_merely_contains_a_hyphen_is_left_alone(): void
    {
        // "Sing - Sing - Sing" is a real title, not a filename convention.
        $item = $this->track('Sing - Sing - Sing', 'Benny Goodman', '12 Sing - Sing - Sing.mp3');

        $this->promote($item, 'Sing, Sing, Sing');

        $this->assertSame('Sing - Sing - Sing', $item->fresh()->title);
    }

    public function test_a_title_suffixed_with_a_different_artist_is_left_alone(): void
    {
        // Only the artist on this record makes it a filename. Anyone else's
        // name in the title is part of the title.
        $item = $this->track('Gold - Sia', 'Imagine Dragons', '2136 Gold - Sia.mp3');

        $this->promote($item, 'Gold');

        $this->assertSame('Gold - Sia', $item->fresh()->title);
    }

    public function test_a_blank_tag_title_changes_nothing(): void
    {
        $item = $this->track('Gold - Imagine Dragons', 'Imagine Dragons', '2136 Gold - Imagine Dragons.mp3');

        $this->promote($item, '');

        $this->assertSame('Gold - Imagine Dragons', $item->fresh()->title);
    }

    /* ----------------------------------------------------------- helpers -- */

    private function promote(MediaItem $item, string $tagTitle): void
    {
        $tagger = app(FileTagger::class);

        $method = new \ReflectionMethod($tagger, 'writeTitle');
        $method->invoke($tagger, $item, ['title' => $tagTitle]);
    }

    private function track(string $title, string $artist, string $filename): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'media/library/Music/' . $filename,
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => $artist]);

        return $item->fresh();
    }
}

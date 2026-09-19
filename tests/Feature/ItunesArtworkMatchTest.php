<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Music\ItunesSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * iTunes must not attach the wrong album's art.
 *
 * The old code took the first search result unconditionally, so a loose term
 * match dressed a track in another album's cover (a Stressed Out row wearing A
 * Sky Full of Stars). Artwork is only accepted now when a candidate's artist
 * matches the track's.
 */
class ItunesArtworkMatchTest extends TestCase
{
    use RefreshDatabase;

    private function track(string $title, ?string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist]);

        return $item->fresh();
    }

    public function test_it_rejects_a_result_whose_artist_does_not_match(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response([
            'results' => [
                ['artistName' => 'Coldplay', 'collectionName' => 'Ghost Stories',
                    'artworkUrl100' => 'https://example.test/sky-100x100.jpg'],
            ],
        ])]);

        $item = $this->track('Stressed Out', 'Twenty One Pilots');
        app(ItunesSearch::class)->enrich($item);

        // Coldplay art must not land on a Twenty One Pilots track.
        $this->assertNull($item->fresh()->cover_image_url);
    }

    public function test_it_accepts_the_matching_artist_even_if_not_first(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response([
            'results' => [
                ['artistName' => 'Coldplay', 'collectionName' => 'Ghost Stories',
                    'artworkUrl100' => 'https://example.test/sky-100x100.jpg'],
                ['artistName' => 'Twenty One Pilots', 'collectionName' => 'Blurryface',
                    'artworkUrl100' => 'https://example.test/blurryface-100x100.jpg'],
            ],
        ])]);

        $item = $this->track('Stressed Out', 'Twenty One Pilots');
        app(ItunesSearch::class)->enrich($item);

        $this->assertSame(
            'https://example.test/blurryface-600x600.jpg',
            $item->fresh()->cover_image_url,
        );
    }

    public function test_it_declines_when_the_track_has_no_known_artist(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response([
            'results' => [
                ['artistName' => 'Somebody', 'collectionName' => 'Some Album',
                    'artworkUrl100' => 'https://example.test/x-100x100.jpg'],
            ],
        ])]);

        // Nothing to validate against — better no art than possibly-wrong art.
        $item = $this->track('Untitled', null);
        app(ItunesSearch::class)->enrich($item);

        $this->assertNull($item->fresh()->cover_image_url);
    }

    public function test_it_normalises_the_and_punctuation_when_matching(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response([
            'results' => [
                ['artistName' => 'The Beatles', 'collectionName' => 'Abbey Road',
                    'artworkUrl100' => 'https://example.test/abbey-100x100.jpg'],
            ],
        ])]);

        $item = $this->track('Come Together', 'Beatles');
        app(ItunesSearch::class)->enrich($item);

        $this->assertSame(
            'https://example.test/abbey-600x600.jpg',
            $item->fresh()->cover_image_url,
        );
    }
}

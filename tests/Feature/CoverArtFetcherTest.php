<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\Metadata\CoverArtFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verified, and efficient (S-258).
 *
 * The fetcher must never attach the wrong album's cover, and must not make one
 * API call per track — covers belong to the album, so it fetches once per album
 * and shares the result.
 */
class CoverArtFetcherTest extends TestCase
{
    use RefreshDatabase;

    private function fetcher(): CoverArtFetcher
    {
        // No throttle in tests.
        return new CoverArtFetcher(throttleMs: 0);
    }

    private function fakeItunes(array $results): void
    {
        Http::fake([
            'itunes.apple.com/*' => Http::response(['results' => $results]),
            'example.test/*' => Http::response('IMAGE-BYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_it_stores_a_verified_cover_and_returns_its_path(): void
    {
        $this->fakeItunes([
            ['artistName' => 'Twenty One Pilots', 'collectionName' => 'Blurryface',
                'artworkUrl100' => 'https://example.test/blurryface-100x100.jpg'],
        ]);

        $path = $this->fetcher()->fetchForAlbum('Twenty One Pilots', 'Blurryface');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('artwork/covers/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_rejects_a_cover_whose_artist_does_not_match(): void
    {
        $this->fakeItunes([
            ['artistName' => 'Coldplay', 'collectionName' => 'Ghost Stories',
                'artworkUrl100' => 'https://example.test/sky-100x100.jpg'],
        ]);

        $this->assertNull($this->fetcher()->fetchForAlbum('Twenty One Pilots', 'Blurryface'));
    }

    public function test_it_declines_when_the_artist_is_unknown(): void
    {
        $this->fakeItunes([
            ['artistName' => 'Someone', 'artworkUrl100' => 'https://example.test/x-100x100.jpg'],
        ]);

        $this->assertNull($this->fetcher()->fetchForAlbum(null, 'Some Album'));
    }

    public function test_it_makes_one_api_call_per_album_not_per_track(): void
    {
        $this->fakeItunes([
            ['artistName' => 'Twenty One Pilots', 'collectionName' => 'Blurryface',
                'artworkUrl100' => 'https://example.test/blurryface-100x100.jpg'],
        ]);

        $fetcher = $this->fetcher();

        // Ten tracks from the same album → one lookup, one download, cached.
        for ($i = 0; $i < 10; $i++) {
            $fetcher->fetchForAlbum('Twenty One Pilots', 'Blurryface');
        }

        $this->assertSame(1, $fetcher->lookupCount());

        // One search + one image download = 2 outbound requests total.
        Http::assertSentCount(2);
    }

    public function test_it_upgrades_the_thumbnail_to_full_resolution(): void
    {
        Http::fake([
            'itunes.apple.com/*' => Http::response(['results' => [
                ['artistName' => 'A', 'artworkUrl100' => 'https://example.test/a-100x100.jpg'],
            ]]),
            // The 600x600 variant is what should actually be requested.
            'example.test/a-600x600.jpg' => Http::response('IMG', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $path = $this->fetcher()->fetchForAlbum('A', 'Album');

        $this->assertNotNull($path);
        Http::assertSent(fn ($request) => str_contains($request->url(), '600x600'));
    }
}

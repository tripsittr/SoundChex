<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Music\Deezer;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Deezer as an opt-in, artist-validated cover source (S-259).
 */
class DeezerCoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('deezer_enabled', true);
    }

    private function track(array $meta, ?string $title = 'Song', ?string $cover = null): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'cover_image_url' => $cover,
            'owned' => true,
        ]);
        $item->musicMetadata()->create($meta);

        return $item->fresh();
    }

    private function fakeDeezer(array $data): void
    {
        Http::fake(['api.deezer.com/*' => Http::response(['data' => $data])]);
    }

    public function test_it_is_off_unless_enabled(): void
    {
        app(SettingsService::class)->set('deezer_enabled', false);
        $item = $this->track(['artist' => 'A']);

        $this->assertFalse(app(Deezer::class)->supports($item));
    }

    public function test_it_sets_a_verified_album_cover(): void
    {
        $this->fakeDeezer([[
            'artist' => ['name' => 'Twenty One Pilots'],
            'album' => ['cover_xl' => 'https://cdn.deezer.test/bf-1000.jpg'],
        ]]);

        $item = $this->track(['artist' => 'Twenty One Pilots'], title: 'Stressed Out');
        app(Deezer::class)->enrich($item);

        $this->assertSame('https://cdn.deezer.test/bf-1000.jpg', $item->fresh()->cover_image_url);
    }

    public function test_it_rejects_a_result_whose_artist_does_not_match(): void
    {
        $this->fakeDeezer([[
            'artist' => ['name' => 'Coldplay'],
            'album' => ['cover_xl' => 'https://cdn.deezer.test/wrong.jpg'],
        ]]);

        $item = $this->track(['artist' => 'Twenty One Pilots'], title: 'Stressed Out');
        app(Deezer::class)->enrich($item);

        $this->assertNull($item->fresh()->cover_image_url);
    }

    public function test_it_does_not_overwrite_an_existing_cover(): void
    {
        $this->fakeDeezer([[
            'artist' => ['name' => 'A'],
            'album' => ['cover_xl' => 'https://cdn.deezer.test/new.jpg'],
        ]]);

        $item = $this->track(['artist' => 'A'], cover: 'artwork/existing.jpg');
        app(Deezer::class)->enrich($item);

        $this->assertSame('artwork/existing.jpg', $item->fresh()->cover_image_url);
        Http::assertNothingSent();
    }

    public function test_it_searches_by_the_primary_artist(): void
    {
        $this->fakeDeezer([[
            'artist' => ['name' => '$uicideboy$'],
            'album' => ['cover_big' => 'https://cdn.deezer.test/p.jpg'],
        ]]);

        // Full credit is the feature; the primary is the lead we should search.
        $item = $this->track(['artist' => '$uicideboy$, Pouya', 'primary_artist' => '$uicideboy$'], title: 'Track');
        app(Deezer::class)->enrich($item);

        $this->assertSame('https://cdn.deezer.test/p.jpg', $item->fresh()->cover_image_url);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '$uicideboy$')
            && ! str_contains(urldecode($request->url()), 'Pouya'));
    }
}

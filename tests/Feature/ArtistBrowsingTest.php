<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\AlbumBrowser;
use App\Services\MusicCredits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browsing by artist.
 *
 * The artist index matched the raw credit string, so one artist appeared once
 * per collaborator they had ever recorded with, and their own page showed only
 * the records they made alone.
 */
class ArtistBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private AlbumBrowser $browser;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->browser = app(AlbumBrowser::class);
        $this->user = User::factory()->create();
    }

    public function test_collaborations_do_not_appear_as_separate_artists(): void
    {
        $this->track('Solo Song', 'Avicii', 'Avicii');
        $this->track('Together', 'Avicii, Nicky Romero', 'Avicii');

        $names = collect($this->browser->artists()->items())->pluck('artist');

        // One entry, not one per collaborator.
        $this->assertSame(['Avicii'], $names->all());
    }

    public function test_an_artist_page_includes_their_collaborations(): void
    {
        $this->track('Solo Song', 'Avicii', 'Avicii', album: 'True');
        $this->track('Together', 'Avicii, Nicky Romero', 'Avicii', album: 'True');

        $albums = $this->browser->forArtist('Avicii');

        $this->assertSame(1, $albums->count());
        $this->assertSame(2, (int) $albums->first()->track_count);
    }

    public function test_a_guest_appearance_is_listed_under_appears_on(): void
    {
        $track = $this->track('Together', 'Avicii, Nicky Romero', 'Avicii');
        app(MusicCredits::class)->fromCreditString($track, 'Avicii, Nicky Romero');

        $appearances = $this->browser->appearsOn('Nicky Romero');

        $this->assertSame(['Together'], $appearances->pluck('title')->all());
    }

    public function test_an_artist_does_not_appear_on_their_own_record(): void
    {
        // Otherwise every track would be listed twice on its own artist's
        // page — once as an album and once as an appearance.
        $track = $this->track('Together', 'Avicii, Nicky Romero', 'Avicii');
        app(MusicCredits::class)->fromCreditString($track, 'Avicii, Nicky Romero');

        $this->assertSame(0, $this->browser->appearsOn('Avicii')->count());
    }

    public function test_a_track_awaiting_enrichment_still_lists(): void
    {
        // No primary yet — imported a minute ago. It must show under what its
        // file says rather than disappear from the index.
        $this->track('Unenriched', 'Some Artist', primary: null);

        $names = collect($this->browser->artists()->items())->pluck('artist');

        $this->assertContains('Some Artist', $names->all());
    }

    private function track(string $title, string $artist, ?string $primary, ?string $album = null): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => '/tmp/' . str($title)->slug() . '.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => $primary,
            'album' => $album,
        ]);

        return $item->fresh();
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Enums\MediaItemType;
use App\Models\Collection;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Porting a playlist from Spotify (S-312). The OAuth exchange and API calls are
 * faked; what's proved is the connector's mapping to normalised tracks and the
 * import flow, which are the parts SoundChex owns.
 */
class SpotifyImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $owner = Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);
        Sanctum::actingAs($this->user, ['profile:'.$owner->id]);
        app(CurrentProfile::class)->switchTo($owner->id);

        // The operator has configured the Spotify app.
        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'client-abc');
        $settings->set('spotify.client_secret', 'secret-xyz');
    }

    public function test_sources_report_configured_but_not_connected(): void
    {
        $this->getJson(route('api.playlists.sources'))
            ->assertOk()
            ->assertJsonPath('sources.0.key', 'spotify')
            ->assertJsonPath('sources.0.configured', true)
            ->assertJsonPath('sources.0.connected', false);
    }

    public function test_the_oauth_callback_connects_the_account(): void
    {
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            ]),
        ]);

        // Start the connection to mint a state, then complete it.
        $start = $this->postJson(route('api.playlists.sources.authorize', 'spotify'), [
            'redirect_uri' => 'https://app.example/callback',
        ])->assertOk()->json();

        $this->postJson(route('api.playlists.sources.callback', 'spotify'), [
            'code' => 'auth-code', 'state' => $start['state'], 'redirect_uri' => 'https://app.example/callback',
        ])->assertOk()->assertJsonPath('connected', true);

        $this->getJson(route('api.playlists.sources'))->assertJsonPath('sources.0.connected', true);
    }

    public function test_it_imports_a_spotify_playlist_matching_by_isrc(): void
    {
        $this->connect();

        // A library track whose title differs but whose ISRC will match.
        $track = $this->track('Some Local Title', 'Some Artist');
        $track->musicMetadata->update(['isrc' => 'GBUM71029604']);

        Http::fake([
            'api.spotify.com/v1/playlists/PL1?*' => Http::response(['name' => 'My Spotify Mix']),
            'api.spotify.com/v1/playlists/PL1/tracks*' => Http::response([
                'next' => null,
                'items' => [
                    ['track' => [
                        'name' => 'Different Spotify Title', 'duration_ms' => 200000,
                        'external_ids' => ['isrc' => 'GBUM71029604'],
                        'id' => 'spid1', 'artists' => [['name' => 'Some Artist']],
                        'album' => ['name' => 'An Album'],
                    ]],
                    ['track' => [
                        'name' => 'Not In Library', 'duration_ms' => 180000,
                        'external_ids' => ['isrc' => 'ZZZZZ0000000'],
                        'id' => 'spid2', 'artists' => [['name' => 'Nobody']],
                        'album' => ['name' => 'Nowhere'],
                    ]],
                ],
            ]),
        ]);

        $response = $this->postJson(route('api.playlists.sources.import', 'spotify'), [
            'playlist_id' => 'PL1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('matched', 1);

        $playlist = Collection::where('name', 'My Spotify Mix')->first();
        $this->assertNotNull($playlist);
        $this->assertSame(1, $playlist->mediaItems()->count());
    }

    public function test_importing_without_connecting_is_refused(): void
    {
        $this->postJson(route('api.playlists.sources.import', 'spotify'), ['playlist_id' => 'PL1'])
            ->assertStatus(409);
    }

    private function connect(): void
    {
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            ]),
        ]);

        Cache::put('playlist-oauth:teststate', $this->user->id, now()->addMinutes(5));
        app(\App\Services\Import\Sources\SpotifySource::class)->connect('code', 'https://app.example/callback');
    }

    private function track(string $title, string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'music/'.str($title)->slug().'.mp3',
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist, 'primary_artist' => $artist]);

        return $item->fresh();
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\PlaylistPorter;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use SoundChex\PlaylistPorter\Services\Sources\SpotifySource;
use Tests\TestCase;

/**
 * How Spotify's playlist responses are actually shaped (S-323).
 *
 * The connector read `/playlists/{id}/tracks`, which answers 403 Forbidden for
 * an app in Spotify's development mode — even for a playlist the connected
 * account owns. The working endpoint is `/items`, and it names each row's
 * payload `item` where the old one said `track`, and the playlist's length
 * `items.total` where the old one said `tracks.total`.
 *
 * Both renames fail silently — an import that finds zero tracks, a picker with
 * blank counts — so they are pinned here.
 *
 * The plugin's classes are loaded at runtime from the install directory, so the
 * connector is exercised directly; its HTTP surface is what these cover.
 */
class SpotifyResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(base_path()).'/Plugins/soundchex-playlist-porter';

        if (! is_dir($root)) {
            $this->markTestSkipped('Playlist Porter plugin source not available.');
        }

        foreach ([
            '/src/Services/PlaylistSourceException.php',
            '/src/Services/ImportedTrack.php',
            '/src/Services/Sources/PlaylistSource.php',
            '/src/Services/Sources/SpotifySource.php',
        ] as $file) {
            require_once $root.$file;
        }

        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', 'client-abc');
        $settings->set('spotify.client_secret', 'secret-xyz');

        $user = \App\Models\User::factory()->create();
        Auth::login($user);

        $settings->set('spotify.user.'.$user->id.'.access', 'access-1');
        $settings->set('spotify.user.'.$user->id.'.refresh', 'refresh-1');
        $settings->set('spotify.user.'.$user->id.'.expires', now()->addHour()->timestamp);
    }

    public function test_it_reads_tracks_from_the_items_endpoint(): void
    {
        Http::fake([
            'api.spotify.com/v1/playlists/PL1?*' => Http::response(['name' => 'My Mix']),
            'api.spotify.com/v1/playlists/PL1/items*' => Http::response([
                'next' => null,
                'items' => [
                    ['item' => [
                        'name' => 'A Song', 'duration_ms' => 200000,
                        'external_ids' => ['isrc' => 'GBUM71029604'],
                        'id' => 'spid1', 'artists' => [['name' => 'An Artist']],
                        'album' => ['name' => 'An Album'],
                    ]],
                ],
            ]),
        ]);

        $result = app(SpotifySource::class)->fetch('PL1');

        $this->assertSame('My Mix', $result['name']);
        $this->assertCount(1, $result['tracks']);
        $this->assertSame('A Song', $result['tracks'][0]->title);
        $this->assertSame('GBUM71029604', $result['tracks'][0]->isrc);
    }

    public function test_it_still_reads_a_row_using_the_older_track_key(): void
    {
        Http::fake([
            'api.spotify.com/v1/playlists/PL1?*' => Http::response(['name' => 'Legacy']),
            'api.spotify.com/v1/playlists/PL1/items*' => Http::response([
                'next' => null,
                'items' => [
                    ['track' => [
                        'name' => 'Old Shape', 'duration_ms' => 1000,
                        'external_ids' => ['isrc' => 'X'], 'id' => 'spid2',
                        'artists' => [['name' => 'Someone']], 'album' => ['name' => 'Thing'],
                    ]],
                ],
            ]),
        ]);

        $result = app(SpotifySource::class)->fetch('PL1');

        $this->assertCount(1, $result['tracks']);
        $this->assertSame('Old Shape', $result['tracks'][0]->title);
    }

    public function test_a_403_explains_development_mode_rather_than_saying_forbidden(): void
    {
        Http::fake([
            'api.spotify.com/v1/playlists/PL1?*' => Http::response(['name' => 'Theirs']),
            'api.spotify.com/v1/playlists/PL1/items*' => Http::response(
                ['error' => ['status' => 403, 'message' => 'Forbidden']], 403,
            ),
        ]);

        $this->expectException(\SoundChex\PlaylistPorter\Services\PlaylistSourceException::class);
        $this->expectExceptionMessageMatches('/development mode/');

        app(SpotifySource::class)->fetch('PL1');
    }

    public function test_the_picker_gets_track_counts_and_who_can_read_each_playlist(): void
    {
        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['id' => 'me-123']),
            'api.spotify.com/v1/me/playlists*' => Http::response([
                'next' => null,
                'items' => [
                    ['id' => 'A', 'name' => 'Mine', 'items' => ['total' => 12],
                        'owner' => ['id' => 'me-123']],
                    ['id' => 'B', 'name' => 'Theirs', 'items' => ['total' => 5],
                        'owner' => ['id' => 'someone-else']],
                ],
            ]),
        ]);

        $lists = app(SpotifySource::class)->playlists();

        $this->assertSame(12, $lists[0]['track_count'], 'count comes from items.total');
        $this->assertTrue($lists[0]['readable']);
        $this->assertFalse($lists[1]['readable'], "someone else's playlist is flagged unreadable");
    }

    public function test_it_follows_pagination_to_the_end(): void
    {
        $page = fn (string $name, ?string $next) => Http::response([
            'next' => $next,
            'items' => [['item' => [
                'name' => $name, 'duration_ms' => 1, 'external_ids' => ['isrc' => $name],
                'id' => $name, 'artists' => [['name' => 'A']], 'album' => ['name' => 'B'],
            ]]],
        ]);

        Http::fake([
            'api.spotify.com/v1/playlists/PL1?*' => Http::response(['name' => 'Long']),
            'api.spotify.com/v1/playlists/PL1/items?limit=100*' => $page('one', 'https://api.spotify.com/v1/playlists/PL1/items?offset=100'),
            'api.spotify.com/v1/playlists/PL1/items?offset=100' => $page('two', null),
        ]);

        $result = app(SpotifySource::class)->fetch('PL1');

        $this->assertCount(2, $result['tracks'], 'both pages are read');
    }
}

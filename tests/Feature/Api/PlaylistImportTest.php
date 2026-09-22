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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Porting a playlist from a file (S-310): parse it, match each track to a library
 * item, build a playlist, and hand back what did not match.
 */
class PlaylistImportTest extends TestCase
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
    }

    public function test_it_imports_an_m3u_and_matches_tracks_to_the_library(): void
    {
        $this->track('Bohemian Rhapsody', 'Queen', 'A Night at the Opera');
        $this->track('Under Pressure', 'Queen', 'Hot Space');

        $m3u = "#EXTM3U\n"
            ."#PLAYLIST:Road Trip\n"
            ."#EXTINF:355,Queen - Bohemian Rhapsody\n"
            ."/music/queen/bohemian.mp3\n"
            ."#EXTINF:246,Queen - Under Pressure\n"
            ."/music/queen/pressure.mp3\n"
            ."#EXTINF:200,Nobody - Missing Song\n"
            ."/music/missing.mp3\n";

        $response = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('roadtrip.m3u', $m3u),
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('name', 'Road Trip')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('matched', 2);

        // Two matched, one unmatched (the missing song).
        $this->assertCount(1, $response->json('unmatched'));
        $this->assertSame('Nobody', $response->json('unmatched.0.artist'));
        $this->assertSame('Missing Song', $response->json('unmatched.0.title'));

        // A real playlist was created with the two matched tracks, in order.
        $playlist = Collection::find($response->json('playlist_id'));
        $this->assertNotNull($playlist);
        $this->assertSame(2, $playlist->mediaItems()->count());
    }

    public function test_it_matches_by_isrc_from_a_csv(): void
    {
        $track = $this->track('Weird Title Mismatch', 'Some Artist', 'An Album');
        $track->musicMetadata->update(['isrc' => 'USRC17607839']);

        // The CSV names the track differently but carries the decisive ISRC.
        $csv = "Track Name,Artist Name(s),Album Name,ISRC,Duration (ms)\n"
            .'"Completely Different Name","Other Artist","Other Album","USRC17607839","210000"'."\n";

        $response = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('export.csv', $csv),
        ]);

        $response->assertCreated()
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('total', 1);
    }

    public function test_an_unrecognised_file_is_rejected(): void
    {
        $response = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('junk.csv', 'not,a,known,header\nrandom,row,here,too'),
        ]);

        // No recognisable columns → no tracks → 422.
        $response->assertStatus(422);
    }

    public function test_an_unmatched_track_can_be_resolved_to_a_chosen_item(): void
    {
        $this->track('Matched', 'Artist', 'Album');
        $chosen = $this->track('The Real One', 'Artist', 'Album');

        $m3u = "#EXTM3U\n#EXTINF:100,Artist - Matched\n/a.mp3\n#EXTINF:100,Ghost - Not Found\n/b.mp3\n";

        $import = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('p.m3u', $m3u),
        ])->assertCreated()->json();

        $this->assertCount(1, $import['unmatched']);

        // Resolve the unmatched entry to the chosen library item.
        $resolved = $this->postJson(
            route('api.playlists.imports.resolve', $import['id']),
            ['index' => 0, 'item_id' => $chosen->id],
        )->assertOk()->json();

        $this->assertSame(2, $resolved['matched']);
        $this->assertCount(0, $resolved['unmatched']);
        $this->assertSame(2, Collection::find($import['playlist_id'])->mediaItems()->count());
    }

    public function test_it_imports_an_xspf(): void
    {
        $this->track('Song One', 'Band A', 'Album X');

        $xspf = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <playlist version="1" xmlns="http://xspf.org/ns/0/">
              <title>My Mix</title>
              <trackList>
                <track><title>Song One</title><creator>Band A</creator><album>Album X</album><duration>210000</duration></track>
                <track><title>Song Two</title><creator>Band B</creator></track>
              </trackList>
            </playlist>
            XML;

        $response = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('mix.xspf', $xspf),
        ]);

        $response->assertCreated()
            ->assertJsonPath('format', 'xspf')
            ->assertJsonPath('name', 'My Mix')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('matched', 1);
    }

    public function test_a_large_playlist_is_queued(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        // 41 tracks — over the inline limit, so it goes to the queue.
        $lines = ["#EXTM3U"];
        for ($i = 1; $i <= 41; $i++) {
            $lines[] = "#EXTINF:100,Artist $i - Song $i";
            $lines[] = "/music/song$i.mp3";
        }

        $response = $this->postJson(route('api.playlists.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('big.m3u', implode("\n", $lines)."\n"),
        ]);

        $response->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('total', 41);
        \Illuminate\Support\Facades\Queue::assertPushed(\SoundChex\PlaylistPorter\Jobs\ImportPlaylistJob::class);
    }

    private function track(string $title, string $artist, ?string $album): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => 'music/'.str($title)->slug().'.mp3',
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist, 'primary_artist' => $artist, 'album' => $album]);

        return $item->fresh();
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Http\Controllers\HlsController;
use App\Models\MediaItem;
use App\Models\MediaProbe;
use App\Models\User;
use App\Services\Streaming\HlsSegmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Choosing which audio track to hear.
 *
 * A rip routinely carries the original language, a dub or two and a
 * commentary. The probe has recorded all of them since it was written —
 * codec, channels, **language**, the default flag — and none of it was ever
 * sent to a client. So every player played whichever track happened to be
 * first and offered no way to change it.
 *
 * The transcode made that permanent: `-map 0:a:0` was hardcoded, so even a
 * client that *could* choose had nothing to choose between — the stream
 * contained one track.
 */
class AudioTrackSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @param array<int, array<string, mixed>> $streams */
    private function film(array $streams): MediaItem
    {
        $item = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'A Film',
            'type' => MediaItemType::Movie,
            'file_path' => 'films/a.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        MediaProbe::create([
            'media_item_id' => $item->id,
            'probed_at' => now(),
            'video_codec' => 'h264',
            'audio_streams' => $streams,
        ]);

        return $item->fresh()->load('probe');
    }

    /* ----------------------------------------------------- the labels --- */

    public function test_a_track_is_named_by_language_and_layout(): void
    {
        $film = $this->film([
            ['codec' => 'aac', 'channels' => 6, 'language' => 'eng'],
            ['codec' => 'aac', 'channels' => 2, 'language' => 'jpn'],
        ]);

        $tracks = $film->probe->audioTracks();

        $this->assertSame('English 5.1', $tracks[0]['label']);
        $this->assertSame('Japanese Stereo', $tracks[1]['label']);
    }

    /**
     * "English 5.1" against "English Stereo" is the other reason this menu
     * exists — picking the surround mix over the downmix.
     */
    public function test_two_tracks_in_one_language_are_told_apart(): void
    {
        $film = $this->film([
            ['codec' => 'ac3', 'channels' => 6, 'language' => 'eng'],
            ['codec' => 'aac', 'channels' => 2, 'language' => 'eng'],
        ]);

        $tracks = $film->probe->audioTracks();

        $this->assertNotSame($tracks[0]['label'], $tracks[1]['label']);
    }

    /**
     * A nameless track still has to be choosable: an empty row is not an
     * option, and "Track 2" is.
     */
    public function test_a_track_with_no_language_falls_back_to_its_position(): void
    {
        $film = $this->film([
            ['codec' => 'aac', 'language' => 'eng', 'channels' => 2],
            ['codec' => 'aac'],
        ]);

        $this->assertSame('Track 2', $film->probe->audioTracks()[1]['label']);
    }

    public function test_an_undetermined_language_is_not_printed(): void
    {
        $film = $this->film([['codec' => 'aac', 'channels' => 2, 'language' => 'und']]);

        $this->assertSame('Stereo', $film->probe->audioTracks()[0]['label']);
    }

    /* -------------------------------------------------- over the wire --- */

    public function test_the_tracks_reach_the_client(): void
    {
        $film = $this->film([
            ['codec' => 'aac', 'channels' => 6, 'language' => 'eng', 'default' => true],
            ['codec' => 'aac', 'channels' => 2, 'language' => 'jpn'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/items/{$film->id}/details")
            ->assertOk();

        $this->assertCount(2, (array) $response->json('audio_tracks'));
        $this->assertSame('English 5.1', $response->json('audio_tracks.0.label'));
        $this->assertTrue($response->json('audio_tracks.0.default'));
    }

    /* --------------------------------------------------- the transcode -- */

    /**
     * The choice has to reach ffmpeg, or the menu is decoration.
     */
    public function test_the_transcode_maps_the_chosen_track(): void
    {
        $film = $this->film([['codec' => 'aac'], ['codec' => 'aac']]);

        $method = new ReflectionMethod(HlsSegmenter::class, 'command');

        $first = $method->invoke(app(HlsSegmenter::class), '/x/a.mkv', '/tmp/x', 1080, 0.0, 0);
        $second = $method->invoke(app(HlsSegmenter::class), '/x/a.mkv', '/tmp/x', 1080, 0.0, 1);

        $this->assertContains('0:a:0?', $first);
        $this->assertContains('0:a:1?', $second);
    }

    /**
     * Switching track must produce a *different* stream, not reuse the one
     * already encoded with the old audio.
     */
    public function test_each_track_gets_its_own_session(): void
    {
        $film = $this->film([['codec' => 'aac'], ['codec' => 'aac']]);

        $method = new ReflectionMethod(HlsSegmenter::class, 'sessionId');

        $first = $method->invoke(app(HlsSegmenter::class), $film, 1080, 0.0, 0);
        $second = $method->invoke(app(HlsSegmenter::class), $film, 1080, 0.0, 1);

        $this->assertNotSame($first, $second);
    }

    /**
     * A request for track 9 of a two-track file maps nothing and produces a
     * **silent** stream — which reads as a broken encode rather than a bad
     * parameter, so it is clamped.
     */
    public function test_a_track_beyond_the_end_is_clamped(): void
    {
        $film = $this->film([['codec' => 'aac'], ['codec' => 'aac']]);

        $this->actingAs($this->user);

        $controller = app(HlsController::class);
        $method = new ReflectionMethod($controller, 'audioTrackFor');

        $request = Request::create('/x', 'GET', ['audio' => 9]);

        $this->assertSame(1, $method->invoke($controller, $request, $film));
    }

    public function test_an_unprobed_file_falls_back_to_the_first_track(): void
    {
        $item = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'Unprobed',
            'type' => MediaItemType::Movie,
            'file_path' => 'films/b.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $controller = app(HlsController::class);
        $method = new ReflectionMethod($controller, 'audioTrackFor');

        $request = Request::create('/x', 'GET', ['audio' => 3]);

        $this->assertSame(0, $method->invoke($controller, $request, $item));
    }
}

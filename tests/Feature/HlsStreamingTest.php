<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\MediaTranscoder;
use App\Services\Streaming\HlsSegmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HLS endpoints (S-29).
 *
 * No encoding happens here: the segmenter is stubbed, because a test that
 * launches ffmpeg per case is a test nobody runs. What is checked is the
 * decision a client is given, the gate in front of it, and that the playlist
 * is rewritten so segments come back through the app rather than pointing at
 * paths on disk.
 */
class HlsStreamingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);

        $this->actingAs($this->user);

        config(['transcode.hls.mode' => 'auto', 'transcode.hls.max_remote_height' => 720]);

        $this->mock(MediaTranscoder::class, function ($mock): void {
            $mock->shouldReceive('probe')->andReturn([
                'container' => 'mp4', 'video' => 'h264', 'audio' => 'aac',
                'height' => 2160, 'duration' => 5400.0,
            ]);
        });
    }

    private function film(string $file = 'film.mp4'): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'file_path' => $this->fixture('movies/'.$file, 'bytes'),
            'owned' => true,
        ])->fresh();
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function asCaller(string $ip, string $url)
    {
        return $this->call('GET', $url, [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    public function test_a_local_caller_is_told_to_play_the_file(): void
    {
        $film = $this->film();

        $this->asCaller('192.168.1.50', route('media.hls.decide', $film))
            ->assertOk()
            ->assertJsonPath('transcode', false)
            ->assertJsonPath('url', route('media.stream', $film));
    }

    public function test_a_remote_caller_is_told_to_use_the_playlist(): void
    {
        $film = $this->film();

        $response = $this->asCaller('203.0.113.9', route('media.hls.decide', $film));

        $response->assertOk()->assertJsonPath('transcode', true);
        $this->assertStringContainsString('hls.m3u8', $response->json('url'));
    }

    public function test_the_decision_carries_its_reason(): void
    {
        // So "why is this transcoding?" has an answer in the payload rather
        // than needing the server's logs.
        $this->asCaller('203.0.113.9', route('media.hls.decide', $this->film()))
            ->assertJsonPath('max_height', 720)
            ->assertJsonStructure(['transcode', 'reason', 'max_height', 'url']);
    }

    public function test_the_content_gate_applies(): void
    {
        $film = $this->film();
        $film->movieMetadata()->create(['mpaa_rating' => 'R']);

        Profile::where('user_id', $this->user->id)->update(['max_rating' => 'PG']);
        app(\App\Services\CurrentProfile::class)
            ->switchTo(Profile::where('user_id', $this->user->id)->value('id'));

        $this->asCaller('192.168.1.50', route('media.hls.decide', $film))->assertNotFound();
    }

    public function test_the_playlist_points_segments_back_at_this_app(): void
    {
        // ffmpeg writes bare filenames; a player cannot fetch a path on the
        // server's disk.
        $film = $this->film();
        $session = str_repeat('a', 32);

        $directory = sys_get_temp_dir().'/hls-test-'.uniqid();
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/seg000.ts', 'bytes');
        file_put_contents(
            $directory.'/index.m3u8',
            "#EXTM3U\n#EXTINF:6.000000,\nseg000.ts\n#EXT-X-ENDLIST\n",
        );

        $this->mock(HlsSegmenter::class, function ($mock) use ($session, $directory): void {
            $mock->shouldReceive('start')->andReturn($session);
            $mock->shouldReceive('directoryFor')->andReturn($directory);
        });

        $response = $this->asCaller('203.0.113.9', route('media.hls.playlist', $film));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
        $this->assertStringContainsString(
            route('media.hls.segment', ['session' => $session, 'file' => 'seg000.ts']),
            $response->getContent(),
        );
        // The bare filename is gone, or the player would request both.
        $this->assertStringNotContainsString("\nseg000.ts\n", $response->getContent());
    }

    public function test_a_stream_that_cannot_start_is_a_service_error_not_a_hang(): void
    {
        $this->mock(HlsSegmenter::class, function ($mock): void {
            $mock->shouldReceive('start')->andReturn(null);
        });

        $this->asCaller('203.0.113.9', route('media.hls.playlist', $this->film()))
            ->assertStatus(503);
    }

    public function test_a_segment_outside_the_session_is_refused(): void
    {
        // The session and filename both reach the filesystem.
        $this->asCaller('192.168.1.50', '/app/hls/'.str_repeat('a', 32).'/seg000.ts')
            ->assertNotFound();
    }
}

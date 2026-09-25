<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\MediaTranscoder;
use App\Services\Streaming\StreamPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * When transcoding is warranted (S-29).
 *
 * Direct play is always better when it works — no CPU cost, no quality loss,
 * and seeking is a range request. So the thing worth testing is that the
 * server does *not* transcode unnecessarily, which is the failure mode that
 * costs a home server its CPU.
 *
 * `probe()` is stubbed throughout: it launches ffprobe, and a test suite that
 * shells out per case is a suite nobody runs.
 */
class StreamPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['transcode.hls.mode' => 'auto', 'transcode.hls.max_remote_height' => 720]);
    }

    private function video(string $file, bool $converted = false): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'A Film',
            'file_path' => $this->fixture('movies/'.$file, 'bytes'),
            'owned' => true,
        ]);

        if ($converted) {
            $item->forceFill(['converted_path' => $this->relativeFixture('converted/a-film.mp4')])->save();
        }

        return $item->fresh();
    }

    /** A fixture on the faked disk, as a path relative to it. */
    private function relativeFixture(string $path): string
    {
        $this->fixture($path, 'bytes');

        return $path;
    }

    /** Named `callerAt`, not `from`: TestCase already has a `from()`. */
    private function callerAt(string $ip): Request
    {
        $request = Request::create('/x');
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    /** Stubs the probe so no ffprobe process is launched. */
    private function withHeight(?int $height): void
    {
        $this->mock(MediaTranscoder::class, function ($mock) use ($height): void {
            $mock->shouldReceive('probe')->andReturn([
                'container' => 'mp4',
                'video' => 'h264',
                'audio' => 'aac',
                'height' => $height,
                'duration' => 5400.0,
            ]);
        });
    }

    /* ------------------------------------------------------- local play --- */

    public function test_a_client_on_the_lan_gets_the_file(): void
    {
        // The whole point of a home server: on the network the file lives on,
        // send the file.
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('192.168.1.50'));

        $this->assertFalse($decision->transcode);
    }

    public function test_a_tailnet_client_counts_as_local(): void
    {
        // Usually a direct encrypted path between two machines in the same
        // house; transcoding for a device one room away pays CPU for nothing.
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('100.64.1.2'));

        $this->assertFalse($decision->transcode);
    }

    public function test_the_tailnet_allowance_can_be_turned_off(): void
    {
        config(['transcode.hls.tailnet_is_local' => false]);
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('100.64.1.2'));

        $this->assertTrue($decision->transcode);
    }

    /* ------------------------------------------------------ remote play --- */

    public function test_a_remote_client_gets_a_transcode_when_the_file_is_too_tall(): void
    {
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('203.0.113.9'));

        $this->assertTrue($decision->transcode);
        $this->assertSame(720, $decision->maxHeight);
    }

    public function test_a_remote_client_gets_the_file_when_it_is_already_small_enough(): void
    {
        // Transcoding a 480p file to fit a 720p ceiling is pure loss.
        $this->withHeight(480);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('203.0.113.9'));

        $this->assertFalse($decision->transcode);
    }

    public function test_an_unknown_height_is_not_treated_as_too_tall(): void
    {
        // A failed probe must not make every remote play a transcode.
        $this->withHeight(null);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('203.0.113.9'));

        $this->assertFalse($decision->transcode);
    }

    public function test_the_remote_ceiling_can_be_disabled(): void
    {
        config(['transcode.hls.max_remote_height' => 0]);
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('203.0.113.9'));

        $this->assertFalse($decision->transcode);
    }

    /* ---------------------------------------------------------- codecs --- */

    public function test_an_unplayable_container_transcodes_even_on_the_lan(): void
    {
        // An MKV of HEVC plays in nothing; handing it over directly shows a
        // black rectangle.
        $this->withHeight(1080);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mkv'), $this->callerAt('192.168.1.50'));

        $this->assertTrue($decision->transcode);
    }

    public function test_a_converted_copy_is_served_directly(): void
    {
        // It exists precisely because the original was not playable, and it is
        // already H.264/AAC in MP4.
        $this->withHeight(1080);

        $decision = app(StreamPolicy::class)->decide(
            $this->video('film.mkv', converted: true),
            $this->callerAt('192.168.1.50'),
        );

        $this->assertFalse($decision->transcode);
    }

    /* -------------------------------------------------------- overrides --- */

    public function test_mode_never_pins_direct_play(): void
    {
        config(['transcode.hls.mode' => 'never']);
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mkv'), $this->callerAt('203.0.113.9'));

        $this->assertFalse($decision->transcode);
    }

    public function test_mode_always_pins_transcoding(): void
    {
        config(['transcode.hls.mode' => 'always']);
        $this->withHeight(480);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('192.168.1.50'));

        $this->assertTrue($decision->transcode);
    }

    public function test_the_decision_says_why(): void
    {
        // "Why is this transcoding?" is the question this feature generates,
        // and a boolean cannot answer it.
        $this->withHeight(2160);

        $decision = app(StreamPolicy::class)->decide($this->video('film.mp4'), $this->callerAt('203.0.113.9'));

        $this->assertStringContainsString('720p', $decision->reason);
    }

    public function test_a_forwarded_header_cannot_claim_the_lan(): void
    {
        // The header is set by whoever sent the request; trusting it would let
        // a remote client claim the LAN's bandwidth.
        $this->withHeight(2160);

        $request = $this->callerAt('203.0.113.9');
        $request->headers->set('X-Forwarded-For', '192.168.1.50');

        $this->assertTrue(app(StreamPolicy::class)->decide($this->video('film.mp4'), $request)->transcode);
    }
}

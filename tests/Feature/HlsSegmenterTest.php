<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\Streaming\HlsSegmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The ffmpeg command behind on-demand HLS, and the path guard on its output
 * (S-29).
 *
 * The command is inspected rather than run: encoding even a short clip takes
 * seconds and a CPU, and a suite that transcodes per case is a suite nobody
 * runs. What matters is the shape — one wrong flag produces segments of the
 * wrong length, which is invisible until someone tries to seek.
 */
class HlsSegmenterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function command(string $source, int $maxHeight = 720, float $from = 0.0): array
    {
        $method = new ReflectionMethod(HlsSegmenter::class, 'command');

        return $method->invoke(app(HlsSegmenter::class), $source, '/tmp/session', $maxHeight, $from);
    }

    private function valueAfter(array $command, string $flag): ?string
    {
        $index = array_search($flag, $command, true);

        return $index === false ? null : ($command[$index + 1] ?? null);
    }

    public function test_it_asks_for_the_configured_segment_length(): void
    {
        config(['transcode.hls.segment_seconds' => 6]);

        $this->assertSame('6', $this->valueAfter($this->command('/tmp/x.mp4'), '-hls_time'));
    }

    public function test_the_keyframe_interval_is_frames_not_seconds(): void
    {
        // ffmpeg can only cut at a keyframe, so -hls_time is a request rather
        // than an instruction: a source with keyframes every 12 seconds gives
        // 12-second segments however short the ask. -g is what makes it real,
        // and it is counted in frames — hence the frame rate being probed
        // rather than assumed (measured: assuming 30fps against a 15fps clip
        // produced one 12s segment instead of two 6s ones).
        config(['transcode.hls.segment_seconds' => 6]);

        $command = $this->command('/tmp/x.mp4');
        $interval = $this->valueAfter($command, '-g');

        $this->assertNotNull($interval);
        // 30fps fallback when ffprobe cannot read a non-existent file.
        $this->assertSame('180', $interval);
        $this->assertSame($interval, $this->valueAfter($command, '-keyint_min'));
    }

    public function test_scene_change_keyframes_are_disabled(): void
    {
        // Left on, a scene change inserts a keyframe and the segment lengths
        // drift away from what was asked for.
        $this->assertSame('0', $this->valueAfter($this->command('/tmp/x.mp4'), '-sc_threshold'));
    }

    public function test_seeking_happens_before_the_input(): void
    {
        // -ss after -i decodes everything up to that point and throws it away;
        // before -i it is near-instant. On a two-hour film that is the
        // difference between starting now and starting in a minute.
        $command = $this->command('/tmp/x.mp4', from: 600.0);

        $this->assertLessThan(
            array_search('-i', $command, true),
            array_search('-ss', $command, true),
        );
        $this->assertSame('600', $this->valueAfter($command, '-ss'));
    }

    public function test_the_scale_never_upscales(): void
    {
        // A 480p file served to a 720p ceiling must stay 480p: upscaling costs
        // CPU and bandwidth to add nothing.
        $this->assertStringContainsString(
            'min(720',
            (string) $this->valueAfter($this->command('/tmp/x.mp4', maxHeight: 720), '-vf'),
        );
    }

    public function test_the_playlist_is_a_vod_one(): void
    {
        // A live window would hide the part of the film already encoded, so
        // the player could not seek back into it.
        $this->assertSame('vod', $this->valueAfter($this->command('/tmp/x.mp4'), '-hls_playlist_type'));
    }

    public function test_only_one_audio_stream_is_taken(): void
    {
        // Many rips carry a dozen, and HLS players pick badly among them.
        $this->assertContains('0:a:0?', $this->command('/tmp/x.mp4'));
    }

    /* ------------------------------------------------------ path guard --- */

    public function test_it_serves_only_the_files_it_writes(): void
    {
        $segmenter = app(HlsSegmenter::class);

        foreach (['../../.env', '../secrets.txt', 'index.m3u8/../../x', 'seg.ts', 'anything.txt'] as $name) {
            $this->assertNull(
                $segmenter->fileIn('abc123', $name),
                "{$name} must not resolve to a path.",
            );
        }
    }

    /* ------------------------------------------------------------ sweep --- */

    /**
     * Builds a session directory whose newest file is this old, in minutes.
     *
     * Named `sessionDir`, not `session`: TestCase already has a `session()`.
     */
    private function sessionDir(string $name, int $ageMinutes): string
    {
        $directory = app(HlsSegmenter::class)->directoryFor($name);

        @mkdir($directory, 0777, true);
        file_put_contents($directory.'/index.m3u8', '#EXTM3U');
        file_put_contents($directory.'/seg000.ts', 'bytes');

        $when = time() - ($ageMinutes * 60);
        touch($directory.'/index.m3u8', $when);
        touch($directory.'/seg000.ts', $when);
        touch($directory, $when);

        return $directory;
    }

    public function test_it_sweeps_a_session_nothing_has_touched(): void
    {
        // A film is gigabytes of segments; without this they accumulate until
        // the disk fills.
        $old = $this->sessionDir(str_repeat('a', 32), ageMinutes: 300);

        $this->assertSame(1, app(HlsSegmenter::class)->sweep(120));
        $this->assertDirectoryDoesNotExist($old);
    }

    public function test_it_leaves_a_session_that_is_still_being_written(): void
    {
        // Age is measured from the newest segment, not the directory's own
        // timestamp — which does not move as segments are added, so a long
        // film would otherwise be swept while still playing.
        $directory = app(HlsSegmenter::class)->directoryFor(str_repeat('b', 32));

        @mkdir($directory, 0777, true);
        file_put_contents($directory.'/index.m3u8', '#EXTM3U');
        // The directory itself looks old; a segment written moments ago does
        // not.
        touch($directory, time() - 60 * 60 * 5);
        file_put_contents($directory.'/seg999.ts', 'just written');

        $this->assertSame(0, app(HlsSegmenter::class)->sweep(120));
        $this->assertDirectoryExists($directory);
    }

    public function test_sweeping_nothing_is_not_an_error(): void
    {
        $this->assertSame(0, app(HlsSegmenter::class)->sweep(120));
    }

    public function test_a_missing_segment_is_null_rather_than_a_path(): void
    {
        // The route turns null into a 404; returning a path to a file that is
        // not there would be a 500 from deep inside a file response.
        $this->assertNull(app(HlsSegmenter::class)->fileIn('abc123', 'seg000.ts'));
    }
}

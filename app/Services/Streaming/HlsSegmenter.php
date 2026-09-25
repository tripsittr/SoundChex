<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Streaming;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Turns a file into an HLS stream, on demand (S-29).
 *
 * Segments are produced *while the client watches*, not ahead of time. A film
 * is gigabytes and a viewer usually watches one; pre-segmenting the library
 * would spend hours of CPU and disk on things nobody opens. So ffmpeg is
 * started at the requested point and writes segments into a session directory
 * the player then fetches.
 *
 * ## Why the keyframe interval is not optional
 *
 * `-hls_time 6` asks for six-second segments, but ffmpeg can only cut at a
 * keyframe. Left alone, a source with keyframes every 12 seconds produces
 * 12-second segments regardless — measured here: one 12s segment instead of
 * two 6s ones. Seeking then lands up to a segment away from the tap. Forcing
 * `-g` to fps × segment length is what makes the requested length real.
 */
class HlsSegmenter
{
    /** Where a session's segments live, relative to the local disk. */
    private const SESSION_ROOT = 'hls';

    /**
     * Starts producing a stream, returning its session id.
     *
     * Returns null when ffmpeg could not be started at all, which the caller
     * turns into a direct-play fallback rather than a broken player.
     */
    public function start(MediaItem $item, int $maxHeight, float $from = 0.0): ?string
    {
        $source = $item->playbackPath();

        if ($source === null) {
            return null;
        }

        $session = $this->sessionId($item, $maxHeight, $from);
        $directory = $this->directoryFor($session);

        // Already running or finished for this exact request: reuse it. A
        // player that reloads the playlist must not start a second encode of
        // the same thing.
        if (is_file($directory.'/index.m3u8')) {
            return $session;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            Log::warning('HLS: could not create a session directory', ['session' => $session]);

            return null;
        }

        $command = $this->command($source, $directory, $maxHeight, $from);

        // Started detached: this runs for as long as the film does, and the
        // request that asked for it must return as soon as the first segment
        // is ready rather than waiting for the encode.
        Process::timeout(config('transcode.timeout_seconds', 21600))
            ->start($command);

        Log::info('HLS: started a stream', [
            'item' => $item->id,
            'session' => $session,
            'max_height' => $maxHeight,
            'from' => $from,
        ]);

        return $session;
    }

    /**
     * Deletes sessions nothing has touched recently, returning how many went.
     *
     * Segments are written for one viewing and never read again once it ends,
     * so without this they accumulate until the disk fills — a film is
     * gigabytes of them. Age is measured from the *newest* file in a session,
     * not the directory's own timestamp, which does not move as segments are
     * added: a long film would otherwise be swept while still playing.
     */
    public function sweep(int $olderThanMinutes = 120): int
    {
        $root = storage_path('app/private/'.self::SESSION_ROOT);

        if (! is_dir($root)) {
            return 0;
        }

        $cutoff = time() - ($olderThanMinutes * 60);
        $removed = 0;

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if ($this->lastTouched($directory) > $cutoff) {
                continue;
            }

            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            if (@rmdir($directory)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            Log::info('HLS: swept finished sessions', ['sessions' => $removed]);
        }

        return $removed;
    }

    /** The newest modification time in a session directory. */
    private function lastTouched(string $directory): int
    {
        $newest = (int) @filemtime($directory);

        foreach (glob($directory.'/*') ?: [] as $file) {
            $newest = max($newest, (int) @filemtime($file));
        }

        return $newest;
    }

    /** The absolute path to a session's directory. */
    public function directoryFor(string $session): string
    {
        return storage_path('app/private/'.self::SESSION_ROOT.'/'.$session);
    }

    /** A file inside a session, or null when the name is not one we wrote. */
    public function fileIn(string $session, string $name): ?string
    {
        // Only the shapes ffmpeg produces. Anything else is someone walking
        // the filesystem through a path this route hands straight to disk.
        if (preg_match('/^(index\.m3u8|seg\d{3,}\.ts)$/', $name) !== 1) {
            return null;
        }

        $path = $this->directoryFor($session).'/'.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * A stable id for one (item, quality, start) combination.
     *
     * Stable so a reload reuses the running encode; specific so seeking
     * backwards past what has been produced starts a new one rather than
     * waiting for a segment that will never come.
     */
    private function sessionId(MediaItem $item, int $maxHeight, float $from): string
    {
        return substr(hash('sha256', implode(':', [
            $item->id,
            $item->updated_at?->timestamp ?? 0,
            $maxHeight,
            (int) round($from),
        ])), 0, 32);
    }

    /**
     * The source's frame rate, or 30 when it cannot be read.
     *
     * `r_frame_rate` comes back as a rational ("30000/1001"), which is why
     * this divides rather than casting.
     */
    private function frameRate(string $source): float
    {
        $result = Process::timeout(15)->run([
            config('transcode.ffprobe', 'ffprobe'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=r_frame_rate',
            '-of', 'default=nw=1:nk=1',
            $source,
        ]);

        if (! $result->successful()) {
            return 30.0;
        }

        $parts = explode('/', trim($result->output()));
        $numerator = (float) ($parts[0] ?? 0);
        $denominator = (float) ($parts[1] ?? 1);

        if ($numerator <= 0 || $denominator <= 0) {
            return 30.0;
        }

        $fps = $numerator / $denominator;

        // A nonsense rate would produce a nonsense keyframe interval; 240 is
        // far above anything real and 1 far below.
        return ($fps >= 1 && $fps <= 240) ? $fps : 30.0;
    }

    /** @return array<int, string> */
    private function command(string $source, string $directory, int $maxHeight, float $from): array
    {
        $seconds = max(2, (int) config('transcode.hls.segment_seconds', 6));

        // Keyframes at segment boundaries, in *frames* — so the frame rate has
        // to be real rather than assumed. Assuming 30fps against a 15fps
        // source asks for keyframes every 12 seconds and produces 12-second
        // segments however short `-hls_time` is; measured exactly that before
        // this was probed.
        $keyframeInterval = (string) (int) round($seconds * $this->frameRate($source));

        return [
            config('transcode.ffmpeg', 'ffmpeg'),
            '-hide_banner',
            '-loglevel', 'error',
            // Before -i: seeking the input is near-instant, where seeking the
            // output decodes everything up to that point and throws it away.
            '-ss', (string) $from,
            '-i', $source,
            // One video and one audio stream: many rips carry a dozen audio
            // tracks, and HLS players pick badly among them.
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-g', $keyframeInterval,
            '-keyint_min', $keyframeInterval,
            // Without this, a scene change inserts a keyframe and the segment
            // lengths drift away from what was asked for.
            '-sc_threshold', '0',
            // -2 keeps the width even, which H.264 requires; min() never
            // upscales a file that is already smaller than the ceiling.
            '-vf', "scale=-2:min({$maxHeight}\\,ih)",
            '-c:a', 'aac',
            '-b:a', (string) config('transcode.audio.bitrate', '192k'),
            '-ac', (string) config('transcode.audio.channels', 2),
            '-f', 'hls',
            '-hls_time', (string) $seconds,
            // VOD rather than a live window: the player gets the whole
            // timeline and can seek within what exists.
            '-hls_playlist_type', 'vod',
            '-hls_segment_filename', $directory.'/seg%03d.ts',
            $directory.'/index.m3u8',
        ];
    }
}

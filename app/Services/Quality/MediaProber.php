<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Quality;

use App\Models\MediaItem;
use App\Models\MediaProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Asks ffprobe what a file actually contains (#467).
 *
 * The gap this fills: **no video technical data was stored anywhere.**
 * Resolution, HDR, codecs, channels, languages and bitrate were all absent,
 * which is why keep-best for video compared *file sizes* — a 10% larger file
 * won whatever it held, so a bloated 1080p rip beat a tight 4K remux.
 *
 * One probe per file, replaced wholesale when re-run: a probe is a measurement
 * of the bytes as they are now, and after a remux the old numbers describe a
 * file that no longer exists.
 */
class MediaProber
{
    /**
     * Whether ffprobe can be reached at all.
     *
     * Checked rather than assumed, because the bundled runtime ships it and a
     * development machine may not — and a missing binary must read as "not
     * probed" rather than as a library of broken files.
     */
    public function isAvailable(): bool
    {
        try {
            return Process::timeout(10)
                ->run([config('transcode.ffprobe', 'ffprobe'), '-version'])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Probes a file and stores the result.
     *
     * Returns null when the file cannot be probed — missing, unreadable, or
     * not media. The caller decides what that means; this does not guess.
     */
    public function probe(MediaItem $item): ?MediaProbe
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $data = $this->run($path);

        if ($data === null) {
            return null;
        }

        $streams = $data['streams'] ?? [];
        $format = $data['format'] ?? [];

        $video = $this->primaryVideoStream($streams);

        // updateOrCreate, not create: a file re-probed after a remux gets its
        // measurement replaced rather than a second row that disagrees with
        // the first.
        return MediaProbe::updateOrCreate(
            ['media_item_id' => $item->id],
            [
                'probed_at' => now(),
                'container' => $this->containerOf($format),
                'duration_ms' => $this->durationMs($format, $streams),
                'bitrate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
                'size_bytes' => $this->sizeOf($format, $path),
                'video_codec' => $video['codec_name'] ?? null,
                'width' => isset($video['width']) ? (int) $video['width'] : null,
                'height' => isset($video['height']) ? (int) $video['height'] : null,
                'fps' => $this->frameRate($video),
                'hdr' => $this->hdrFormat($video),
                'bit_depth' => $this->bitDepth($video, $streams),
                'audio_streams' => $this->audioStreams($streams),
                'subtitle_streams' => $this->subtitleStreams($streams),
                'raw' => $data,
            ],
        );
    }

    /**
     * The file's size, from ffprobe where it said and the filesystem otherwise.
     *
     * Its own method because the inline form was an ambiguous ternary that PHP
     * refuses to parse -- caught by `php -l`, which is why that runs before
     * anything else.
     *
     * @param  array<string, mixed>  $format
     */
    private function sizeOf(array $format, string $path): ?int
    {
        if (isset($format['size']) && is_numeric($format['size'])) {
            return (int) $format['size'];
        }

        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    /**
     * ffprobe's JSON for a file, or null.
     *
     * @return array<string, mixed>|null
     */
    private function run(string $path): ?array
    {
        try {
            $result = Process::timeout(120)->run([
                config('transcode.ffprobe', 'ffprobe'),
                '-v', 'error',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $path,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not run ffprobe', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            // Not an error worth shouting about: a text file in a media folder
            // reaches here, and so does a genuinely corrupt video. The quality
            // checks decide which.
            return null;
        }

        $data = json_decode($result->output(), true);

        return is_array($data) ? $data : null;
    }

    /**
     * The real video stream, if the file has one.
     *
     * Cover art is a video stream by `codec_type`, and a track with an
     * embedded sleeve is not a film — `attached_pic` says which, and missing
     * that distinction is how an MP3 with artwork would report as 300×300
     * video. `ContainerProbe` already learned this.
     *
     * @param  array<int, array<string, mixed>>  $streams
     * @return array<string, mixed>
     */
    private function primaryVideoStream(array $streams): array
    {
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }

            if ((int) ($stream['disposition']['attached_pic'] ?? 0) === 1) {
                continue;
            }

            return $stream;
        }

        return [];
    }

    /**
     * Duration in milliseconds, from the format or failing that a stream.
     *
     * A container can omit its own duration while its streams carry one —
     * common in a stream copy — so falling back is the difference between
     * knowing a file's length and calling it unknown.
     *
     * @param  array<string, mixed>  $format
     * @param  array<int, array<string, mixed>>  $streams
     */
    private function durationMs(array $format, array $streams): ?int
    {
        $seconds = $format['duration'] ?? null;

        if (! is_numeric($seconds)) {
            foreach ($streams as $stream) {
                if (is_numeric($stream['duration'] ?? null)) {
                    $seconds = $stream['duration'];

                    break;
                }
            }
        }

        return is_numeric($seconds) ? (int) round((float) $seconds * 1000) : null;
    }

    /**
     * Frames per second, from the rational ffprobe reports.
     *
     * `avg_frame_rate` rather than `r_frame_rate`: the latter is the container's
     * declared rate and reads 1000 on some rips, where the average is what the
     * file actually plays at.
     *
     * @param  array<string, mixed>  $video
     */
    private function frameRate(array $video): ?float
    {
        $rate = $video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null;

        if (! is_string($rate) || ! str_contains($rate, '/')) {
            return null;
        }

        [$numerator, $denominator] = array_pad(explode('/', $rate, 2), 2, '0');

        if (! is_numeric($numerator) || ! is_numeric($denominator) || (float) $denominator === 0.0) {
            return null;
        }

        return round((float) $numerator / (float) $denominator, 3);
    }

    /**
     * Which HDR format, if any.
     *
     * Read from the transfer characteristics and side data rather than guessed
     * from the bit depth: a 10-bit SDR file is common, so depth alone would
     * call half a library HDR.
     *
     * @param  array<string, mixed>  $video
     */
    private function hdrFormat(array $video): ?string
    {
        if ($video === []) {
            return null;
        }

        // Dolby Vision announces itself in side data, and takes precedence
        // because a DV file usually carries an HDR10 base layer too.
        foreach ($video['side_data_list'] ?? [] as $side) {
            $type = strtolower((string) ($side['side_data_type'] ?? ''));

            if (str_contains($type, 'dolby vision')) {
                return 'dv';
            }

            if (str_contains($type, 'hdr dynamic metadata')) {
                return 'hdr10plus';
            }
        }

        return match ($video['color_transfer'] ?? null) {
            'smpte2084' => 'hdr10',
            'arib-std-b67' => 'hlg',
            default => 'none',
        };
    }

    /**
     * Bits per sample — video depth for a film, audio depth for music.
     *
     * One column for both because the question is the same ("how much
     * precision does this hold?") and no file is meaningfully both.
     *
     * @param  array<string, mixed>  $video
     * @param  array<int, array<string, mixed>>  $streams
     */
    private function bitDepth(array $video, array $streams): ?int
    {
        if (isset($video['bits_per_raw_sample']) && is_numeric($video['bits_per_raw_sample'])) {
            return (int) $video['bits_per_raw_sample'];
        }

        if ($video !== []) {
            // 10-bit profiles name themselves rather than reporting a depth.
            $profile = strtolower((string) ($video['profile'] ?? ''));

            if (str_contains($profile, '10')) {
                return 10;
            }
        }

        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) !== 'audio') {
                continue;
            }

            foreach (['bits_per_raw_sample', 'bits_per_sample'] as $key) {
                if (is_numeric($stream[$key] ?? null) && (int) $stream[$key] > 0) {
                    return (int) $stream[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $streams
     * @return array<int, array<string, mixed>>
     */
    private function audioStreams(array $streams): array
    {
        $audio = [];

        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) !== 'audio') {
                continue;
            }

            $audio[] = [
                'codec' => $stream['codec_name'] ?? null,
                'channels' => isset($stream['channels']) ? (int) $stream['channels'] : null,
                'layout' => $stream['channel_layout'] ?? null,
                // Normalised, because ffprobe reports "und" for unknown and a
                // literal "und" in a language list is noise.
                'language' => $this->language($stream),
                'bitrate' => isset($stream['bit_rate']) ? (int) $stream['bit_rate'] : null,
                'sample_rate' => isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null,
                'bit_depth' => isset($stream['bits_per_raw_sample']) ? (int) $stream['bits_per_raw_sample'] : null,
                'default' => (int) ($stream['disposition']['default'] ?? 0) === 1,
            ];
        }

        return $audio;
    }

    /**
     * @param  array<int, array<string, mixed>>  $streams
     * @return array<int, array<string, mixed>>
     */
    private function subtitleStreams(array $streams): array
    {
        $subtitles = [];

        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) !== 'subtitle') {
                continue;
            }

            $subtitles[] = [
                'codec' => $stream['codec_name'] ?? null,
                'language' => $this->language($stream),
                'forced' => (int) ($stream['disposition']['forced'] ?? 0) === 1,
                'default' => (int) ($stream['disposition']['default'] ?? 0) === 1,
            ];
        }

        return $subtitles;
    }

    /** @param array<string, mixed> $stream */
    private function language(array $stream): ?string
    {
        $language = $stream['tags']['language'] ?? null;

        if (! is_string($language)) {
            return null;
        }

        $language = strtolower(trim($language));

        // "und" is ffprobe for "nobody tagged this", which is the same answer
        // as absent and should not appear in a list of languages.
        return ($language === '' || $language === 'und') ? null : $language;
    }

    /** @param array<string, mixed> $format */
    private function containerOf(array $format): ?string
    {
        $name = $format['format_name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        // ffprobe reports a comma-separated family ("mov,mp4,m4a,3gp,3g2,mj2").
        // The first is the most specific and the one a person would name.
        return explode(',', $name)[0];
    }
}

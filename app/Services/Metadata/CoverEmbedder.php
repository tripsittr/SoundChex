<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Writes an item's cover art into the audio file's own tags (S-274).
 *
 * The catalogue knows the right cover, but the file itself may carry the wrong
 * embedded art (a compilation cover) or none — so it looks wrong in any other
 * player and in a fresh re-scan. This bakes the catalogue's cover into the file.
 *
 * Done with ffmpeg (already a dependency), stream-copying the audio so nothing is
 * re-encoded and no quality is lost, and written to a temp file that is only
 * moved into place on success — the original is never left half-written. Only
 * embeds a cover we actually hold locally (a verified fetch or extracted art),
 * never a remote URL, and only for a track we are confident about.
 */
class CoverEmbedder
{
    /** Formats whose containers ffmpeg can carry embedded cover art in. */
    private const EMBEDDABLE_EXTENSIONS = ['mp3', 'm4a', 'mp4', 'flac', 'aiff', 'aif'];

    public function isAvailable(): bool
    {
        return Process::run([config('transcode.ffmpeg', 'ffmpeg'), '-version'])->successful();
    }

    /**
     * Embeds the item's cover into its audio file.
     *
     * @return bool True when the file was rewritten with the cover; false when
     *              there was nothing to do or it was not safe to proceed.
     */
    public function embed(MediaItem $item): bool
    {
        $audioPath = $item->absoluteFilePath();
        $coverPath = $this->localCoverPath($item);

        if ($audioPath === null || ! is_file($audioPath) || $coverPath === null) {
            return false;
        }

        $extension = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EMBEDDABLE_EXTENSIONS, true)) {
            return false;
        }

        $temp = $this->tempPath($audioPath, $extension);

        $result = Process::timeout(120)->run($this->command($audioPath, $coverPath, $temp, $extension));

        if (! $result->successful() || ! is_file($temp) || filesize($temp) === 0) {
            @unlink($temp);

            return false;
        }

        // Move into place only after ffmpeg wrote a complete file, so a failure
        // or a kill mid-run never leaves the user's track truncated.
        if (! @rename($temp, $audioPath)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * The ffmpeg argument list.
     *
     * `-map 0:a` keeps the audio, `-map 1` adds the image as an attached picture,
     * `-c copy` avoids re-encoding. The disposition/metadata make players treat
     * the image as the front cover. MP3 needs `-id3v2_version 3`; MP4/M4A wants
     * the cover as an mjpeg attached_pic.
     *
     * @return array<int, string>
     */
    private function command(string $audio, string $cover, string $temp, string $extension): array
    {
        $ffmpeg = config('transcode.ffmpeg', 'ffmpeg');

        $args = [
            $ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $audio,
            '-i', $cover,
            '-map', '0:a', '-map', '1:v',
            '-c', 'copy',
            '-disposition:v', 'attached_pic',
            '-metadata:s:v', 'title=Album cover',
            '-metadata:s:v', 'comment=Cover (front)',
        ];

        if ($extension === 'mp3') {
            $args[] = '-id3v2_version';
            $args[] = '3';
        }

        $args[] = $temp;

        return $args;
    }

    /** A sibling temp file so the rename into place is atomic (same filesystem). */
    private function tempPath(string $audioPath, string $extension): string
    {
        return dirname($audioPath).'/.soundchex-cover-'.bin2hex(random_bytes(6)).'.'.$extension;
    }

    /**
     * The absolute path of the item's cover on disk, or null when it has none we
     * can read (no cover, or a remote URL).
     */
    private function localCoverPath(MediaItem $item): ?string
    {
        $cover = $item->cover_image_url;

        if (blank($cover) || str_starts_with($cover, 'http')) {
            return null;
        }

        $path = Storage::disk('public')->path(ltrim($cover, '/'));

        return is_file($path) ? $path : null;
    }
}

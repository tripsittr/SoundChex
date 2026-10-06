<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Streaming;

use App\Models\MediaItem;

/**
 * The whole timeline, written before any of it has been encoded.
 *
 * ffmpeg writes its playlist as it goes, so a playlist read moments after the
 * encode starts lists the handful of segments that exist — and that is all the
 * player believes the film to be. Seeking past them stalls, and seeking
 * *backwards* re-buffers too, because the player is being handed a different,
 * longer playlist each time it asks and has to reconcile them.
 *
 * A VOD playlist is entirely derivable from two numbers: how long the film is,
 * and how long a segment is. Both are known before a frame is encoded — the
 * probe measured the duration — so the player can be given the real timeline
 * at once and seek anywhere in it.
 *
 * Which leaves one problem, handled by `HlsSegmenter`: a segment the encoder
 * has not reached yet. The playlist promising it is what makes the seek
 * possible; producing it on request is what makes the seek fast.
 */
class HlsPlaylist
{
    public function __construct(private HlsSegmenter $segmenter) {}

    /**
     * The complete playlist for an item, or null when its length is unknown.
     *
     * Null rather than a guess: a playlist whose duration is wrong is worse
     * than none, because the player trusts it and seeks into nothing.
     */
    public function forItem(MediaItem $item, string $session, callable $segmentUrl): ?string
    {
        $seconds = $this->durationSeconds($item);

        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $segmentLength = max(2, (int) config('transcode.hls.segment_seconds', 6));
        $count = (int) ceil($seconds / $segmentLength);

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:'.$segmentLength,
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
        ];

        for ($index = 0; $index < $count; $index++) {
            // The last segment is short unless the film divides evenly, and
            // saying so matters: a player that is told every segment is six
            // seconds will seek past the end of a film whose final segment is
            // two.
            $remaining = $seconds - ($index * $segmentLength);
            $length = min((float) $segmentLength, $remaining);

            $lines[] = sprintf('#EXTINF:%.6F,', $length);
            $lines[] = $segmentUrl(sprintf('seg%03d.ts', $index));
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines)."\n";
    }

    /**
     * How long the item runs, in seconds.
     *
     * From the probe, which measured the file rather than repeating what a
     * metadata source claimed — the number that matches what actually plays,
     * and the only one safe to build a timeline from.
     */
    private function durationSeconds(MediaItem $item): ?float
    {
        $ms = $item->probe?->duration_ms;

        return $ms > 0 ? $ms / 1000 : null;
    }
}

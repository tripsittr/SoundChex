<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Streaming;

use App\Models\MediaItem;
use App\Services\MediaTranscoder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether to hand a client the file or an HLS stream (S-29).
 *
 * Direct play is always better when it works: no CPU cost, no quality loss,
 * and seeking is a range request rather than a segment fetch. So this is not
 * an "HLS on/off" switch — it decides *when transcoding is warranted*, which
 * is how Plex and Jellyfin frame the same question.
 *
 * Two reasons it is warranted:
 *
 * 1. **The client cannot decode the file.** An MKV of HEVC plays in nothing;
 *    handing it over directly shows a black rectangle.
 * 2. **The link is too slow for the file.** A 12 Mbps remux over a hotel
 *    connection buffers forever. On the LAN it is fine, which is why the
 *    caller's address matters.
 *
 * The settings are a ceiling and an override, not a mode: `max_remote_height`
 * caps what goes out over the internet, and `mode` forces direct or transcode
 * for debugging — a question this feature *will* generate is "why is this
 * transcoding?", and being able to pin it is how that gets answered.
 */
class StreamPolicy
{
    /** Loopback and the private ranges — a client on the same network. */
    private const LOCAL_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * A tailnet is not the LAN, but it is not the open internet either.
     *
     * Tailscale's 100.64.0.0/10 is usually a direct encrypted path between two
     * machines — often the same house. Treated as local, because transcoding
     * a remux for a device one room away would be paying CPU for nothing.
     */
    private const TAILNET_RANGE = '100.64.0.0/10';

    public function __construct(private MediaTranscoder $transcoder) {}

    /** How this item should be served to this caller. */
    public function decide(MediaItem $item, Request $request): PlaybackDecision
    {
        $mode = (string) config('transcode.hls.mode', 'auto');

        if ($mode === 'never') {
            return PlaybackDecision::direct('forced by configuration');
        }

        if ($mode === 'always') {
            return PlaybackDecision::transcode(
                'forced by configuration',
                $this->ceilingFor($request),
            );
        }

        // A converted copy exists precisely because the original was not
        // playable — it is already H.264/AAC in MP4, so direct play is right
        // even for a client that could not manage the original.
        if ($item->hasConvertedCopy() && $this->isLocal($request)) {
            return PlaybackDecision::direct('a converted copy is already web-playable');
        }

        if (! $item->isPlayableVideo()) {
            return PlaybackDecision::transcode(
                'the container or codec is not web-playable',
                $this->ceilingFor($request),
            );
        }

        if ($this->isLocal($request)) {
            // The whole point of a home server: on the network the file lives
            // on, send the file.
            return PlaybackDecision::direct('playing on the local network');
        }

        $ceiling = $this->ceilingFor($request);
        $height = $this->heightOf($item);

        if ($ceiling !== null && $height !== null && $height > $ceiling) {
            return PlaybackDecision::transcode(
                "taller than the {$ceiling}p remote ceiling",
                $ceiling,
            );
        }

        return PlaybackDecision::direct('playable as-is within the remote ceiling');
    }

    /**
     * Whether the caller is on a network where the file itself is cheap.
     *
     * Read from the connection rather than a forwarded header: a header is set
     * by whoever sent the request, so trusting it would let a remote client
     * claim the LAN's bandwidth.
     */
    public function isLocal(Request $request): bool
    {
        $ip = $request->ip();

        if ($ip === null) {
            return false;
        }

        if (IpUtils::checkIp($ip, self::LOCAL_RANGES)) {
            return true;
        }

        return config('transcode.hls.tailnet_is_local', true)
            && IpUtils::checkIp($ip, self::TAILNET_RANGE);
    }

    /** The height ceiling for this caller, or null for no cap. */
    private function ceilingFor(Request $request): ?int
    {
        if ($this->isLocal($request)) {
            return null;
        }

        $ceiling = (int) config('transcode.hls.max_remote_height', 720);

        return $ceiling > 0 ? $ceiling : null;
    }

    /**
     * The item's height, probed only when it might change the answer.
     *
     * ffprobe is a process launch per call, so it is deliberately the last
     * thing consulted: every cheaper reason to transcode has already been
     * checked by the time this runs.
     */
    private function heightOf(MediaItem $item): ?int
    {
        return $this->transcoder->probe($item)['height'] ?? null;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Converting media into formats browsers can actually play.
 *
 * Browsers decode a narrow set of containers and codecs. An MKV holding HEVC
 * is common and plays in nothing — so rather than showing a black rectangle,
 * the app converts a web-playable copy alongside the original.
 *
 * The original is never modified or deleted. Conversion is lossy, and the
 * source file is the user's own copy.
 */

/*
 * Prefer a bundled binary sitting beside the PHP binary — the same convention
 * TransferReceiver uses for cacert.pem. When SoundChex runs under its bundled
 * server runtime, `dirname(PHP_BINARY)/ffmpeg` is the full GPL ffmpeg shipped in
 * the bundle (S-151 Step 8), so transcoding works out of the box with no config.
 * An explicit FFMPEG_PATH/FFPROBE_PATH still wins; otherwise fall back to PATH.
 */
$bundledBinary = static function (string $name): string {
    $beside = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

    return is_file($beside) ? $beside : $name;
};

return [
    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    */

    'ffmpeg' => env('FFMPEG_PATH') ?: $bundledBinary('ffmpeg'),
    'ffprobe' => env('FFPROBE_PATH') ?: $bundledBinary('ffprobe'),

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    |
    | Converted copies live beside the library rather than inside it, so the
    | organizer's Artist/Album tree stays a tree of originals.
    |
    */

    'output_root' => env('TRANSCODE_OUTPUT', 'media/converted'),

    /*
    |--------------------------------------------------------------------------
    | Video Encoding
    |--------------------------------------------------------------------------
    |
    | H.264 in MP4 with AAC audio — the one combination every browser plays.
    |
    | VideoToolbox is Apple's hardware encoder: roughly an order of magnitude
    | faster than libx264 on Apple silicon, at slightly larger file sizes for
    | the same quality. `auto` picks it when available and falls back to
    | libx264 elsewhere.
    |
    */

    'video' => [
        'encoder' => env('TRANSCODE_VIDEO_ENCODER', 'auto'),

        // Constant Rate Factor for libx264: lower is better quality and
        // larger. 21 is visually near-transparent for most sources.
        'crf' => (int) env('TRANSCODE_CRF', 21),

        // VideoToolbox works to a bitrate rather than a CRF.
        'hardware_bitrate' => env('TRANSCODE_HW_BITRATE', '6M'),

        // Downscale anything taller than this. 1080p is the practical ceiling
        // for browser playback on a home server.
        'max_height' => (int) env('TRANSCODE_MAX_HEIGHT', 1080),

        'preset' => env('TRANSCODE_PRESET', 'medium'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Encoding
    |--------------------------------------------------------------------------
    |
    | Surround tracks are downmixed to stereo: browsers rarely handle more, and
    | a 5.1 AAC track often plays with inaudible dialogue.
    |
    */

    'audio' => [
        'codec' => 'aac',
        'bitrate' => env('TRANSCODE_AUDIO_BITRATE', '192k'),
        'channels' => (int) env('TRANSCODE_AUDIO_CHANNELS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Music Normalisation
    |--------------------------------------------------------------------------
    |
    | Lossless formats play in most browsers but are large. Converting is
    | optional and off by default, since it discards quality the user chose.
    |
    */

    'music' => [
        'codec' => 'libmp3lame',
        'bitrate' => env('TRANSCODE_MUSIC_BITRATE', '320k'),
        'extension' => 'mp3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | A feature film can take a long time even on hardware. The queue worker's
    | own timeout must exceed this or the job is killed mid-encode.
    |
    */

    'timeout_seconds' => (int) env('TRANSCODE_TIMEOUT', 21600),

    /*
    |--------------------------------------------------------------------------
    | Adaptive streaming (HLS)
    |--------------------------------------------------------------------------
    |
    | Direct play is always better when it works: no CPU cost, no quality loss,
    | and seeking is a range request rather than a segment fetch. So these
    | settings decide *when transcoding is warranted*, not whether HLS exists.
    |
    | `mode` is the debugging override — `auto` decides per request, while
    | `never` and `always` pin it. "Why is this transcoding?" is the question
    | this feature generates, and being able to force either answer is how it
    | gets answered.
    |
    */

    'hls' => [
        'mode' => env('TRANSCODE_HLS_MODE', 'auto'),

        // The ceiling for playback from outside the house. A 1080p remux over
        // a hotel connection buffers forever; 720p is watchable on anything
        // that is not a television. 0 disables the cap.
        'max_remote_height' => (int) env('TRANSCODE_MAX_REMOTE_HEIGHT', 720),

        // Bitrate ceiling for a remote stream, matched to the height above.
        'remote_bitrate' => env('TRANSCODE_REMOTE_BITRATE', '2500k'),

        // A tailnet is not the LAN, but it is usually a direct encrypted path
        // between two machines in the same house — transcoding a remux for a
        // device one room away pays CPU for nothing.
        'tailnet_is_local' => (bool) env('TRANSCODE_TAILNET_IS_LOCAL', true),

        // Segment length. Short segments start faster and adapt sooner;
        // longer ones compress better and cost fewer requests. Six seconds is
        // what Apple's own guidance recommends.
        'segment_seconds' => (int) env('TRANSCODE_SEGMENT_SECONDS', 6),
    ],
];

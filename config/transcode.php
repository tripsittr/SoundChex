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

return [
    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    */

    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),

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
];

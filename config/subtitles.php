<?php

/**
 * Caption and subtitle handling for films and episodes.
 *
 * Tracks come from three places, in descending order of trust:
 *
 *   embedded   muxed into the video file — always correct for that release
 *   sidecar    a .srt next to the video, usually shipped with it
 *   online     OpenSubtitles, matched by file hash
 *
 * Everything is converted to WebVTT on import, because it's the only subtitle
 * format browsers play natively.
 */

return [
    'ffmpeg_path' => env('SUBTITLE_FFMPEG_PATH', env('TRANSCODE_FFMPEG_PATH', 'ffmpeg')),
    'ffprobe_path' => env('SUBTITLE_FFPROBE_PATH', env('TRANSCODE_FFPROBE_PATH', 'ffprobe')),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Where converted WebVTT files live, relative to the private disk. Excluded
    | from the library scanner — a .vtt is not a media file to catalogue.
    |
    */

    'path' => env('SUBTITLE_PATH', 'media/subtitles'),

    /*
    |--------------------------------------------------------------------------
    | Sidecar Extensions
    |--------------------------------------------------------------------------
    |
    | Subtitle files found beside a video. SUB/IDX are bitmap formats that
    | can't be converted to text without OCR, so they're deliberately absent.
    |
    */

    'sidecar_extensions' => ['srt', 'vtt', 'ass', 'ssa'],

    /*
    |--------------------------------------------------------------------------
    | Automatic Import
    |--------------------------------------------------------------------------
    |
    | Pull embedded and sidecar tracks in when a video is catalogued. Both are
    | local operations on files the user already has.
    |
    */

    'auto_import' => (bool) env('SUBTITLE_AUTO_IMPORT', true),

    /*
    |--------------------------------------------------------------------------
    | OpenSubtitles
    |--------------------------------------------------------------------------
    |
    | Searching needs a free API key from opensubtitles.com (Account → API
    | consumers). Entered under Settings → Metadata Sources, never in .env.
    |
    | Downloads are rate-limited per account, so this is only ever triggered by
    | hand — never automatically on a library scan.
    |
    */

    'opensubtitles_base' => 'https://api.opensubtitles.com/api/v1',

    'user_agent' => env('SUBTITLE_USER_AGENT', 'SoundChex v1.0'),

    /*
    |--------------------------------------------------------------------------
    | Preferred Languages
    |--------------------------------------------------------------------------
    |
    | Used to pick a default track and to order the picker. First match wins.
    |
    */

    'preferred_languages' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUBTITLE_LANGUAGES', 'en')),
    ))),
];

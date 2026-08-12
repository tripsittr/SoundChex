<?php

/**
 * Library organization and folder watching.
 *
 * Watched folders are scanned on a schedule; anything new is catalogued, and
 * once enrichment has resolved real artist/album tags the file is moved into
 * the organized library tree.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Watched Folders
    |--------------------------------------------------------------------------
    |
    | Absolute paths scanned by `library:scan`. Drop files into any of these
    | and they're picked up automatically. Set LIBRARY_WATCH_FOLDERS in .env
    | as a comma-separated list to override.
    |
    */

    'watch_folders' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LIBRARY_WATCH_FOLDERS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Scan The Storage Disk
    |--------------------------------------------------------------------------
    |
    | Also sweep the whole private storage disk for stray audio — uploads,
    | files dropped in by hand, anything left behind. The organized library
    | and framework scratch directories are excluded (see below), so this
    | picks up only what still needs filing.
    |
    */

    'scan_storage' => (bool) env('LIBRARY_SCAN_STORAGE', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded Directories
    |--------------------------------------------------------------------------
    |
    | Paths relative to the storage disk that the scanner skips. The organized
    | library is excluded because its contents are already catalogued —
    | scanning it would re-import every track on every pass.
    |
    */

    'scan_exclude' => [
        'media/library',
        // Converted copies belong to an item that's already catalogued.
        // Scanning them would catalogue each conversion as a second, separate
        // movie whose title is the generated filename.
        'media/converted',
        // Extracted caption tracks belong to a video that's already
        // catalogued.
        'media/subtitles',
        // Illustrations pulled out of a book that is already catalogued.
        'media/book-assets',
        'livewire-tmp',
        'framework',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recognised Extensions
    |--------------------------------------------------------------------------
    |
    | Maps a file extension to the media type it becomes. The inbox takes
    | anything, so this is how a dropped file is classified before enrichment
    | runs. Books cover the common e-book formats; video is split by nothing —
    | a movie and an episode look identical on disk, so both import as movies
    | and can be re-typed if wrong.
    |
    */

    'type_extensions' => [
        'music' => [
            'mp3', 'flac', 'm4a', 'aac', 'wav', 'aiff', 'aif',
            'ogg', 'oga', 'opus', 'wma', 'alac', 'ape', 'wv',
        ],
        'movie' => [
            'mp4', 'mkv', 'avi', 'mov', 'm4v', 'webm', 'wmv', 'mpg', 'mpeg',
        ],
        'book' => [
            'epub', 'mobi', 'azw3', 'pdf', 'cbz', 'cbr',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox
    |--------------------------------------------------------------------------
    |
    | Every upload lands here, whatever its type. The scanner picks files up,
    | enrichment identifies them, and the organizer moves them into the sorted
    | tree below — leaving this folder empty again.
    |
    */

    'inbox' => env('LIBRARY_INBOX', 'media/unsorted'),

    /*
    |--------------------------------------------------------------------------
    | Organized Library Root
    |--------------------------------------------------------------------------
    |
    | Where sorted files end up. Each type gets its own subtree:
    |
    |   library/Music/Artist/Album/## Track.ext
    |   library/Movies/Title (Year)/Title (Year).ext
    |   library/TV/Show/Season 01/Show - S01E02.ext
    |   library/Books/Author/Title.ext
    |
    */

    'library_root' => env('LIBRARY_ROOT', 'media/library'),

    /*
    |--------------------------------------------------------------------------
    | Per-type Subfolders
    |--------------------------------------------------------------------------
    |
    | Keyed by MediaItemType value. Keeping types in separate trees means a
    | film and an album that share a name can't collide.
    |
    */

    'type_folders' => [
        'music' => 'Music',
        'movie' => 'Movies',
        'show'  => 'TV',
        'book'  => 'Books',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-organize
    |--------------------------------------------------------------------------
    |
    | Move files into the tree automatically once enrichment finishes. Turn
    | this off to keep files where they are and sort manually with
    | `library:organize`.
    |
    */

    'auto_organize' => (bool) env('LIBRARY_AUTO_ORGANIZE', true),

    /*
    |--------------------------------------------------------------------------
    | Scan Interval
    |--------------------------------------------------------------------------
    |
    | Minutes between scheduled scans of the watched folders.
    |
    */

    'scan_interval_minutes' => (int) env('LIBRARY_SCAN_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Settle Time
    |--------------------------------------------------------------------------
    |
    | Seconds a file must have been unmodified before it's considered complete.
    | A file still being copied would otherwise be catalogued half-written, and
    | getID3 would read whatever partial tags it happened to find.
    |
    */

    'settle_seconds' => (int) env('LIBRARY_SETTLE_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection
    |--------------------------------------------------------------------------
    |
    | Files are hashed at scan time so an identical copy can be recognised
    | without comparing bytes on every pass. Detection only ever flags rows —
    | whether anything is deleted is decided by 'duplicate_action' below.
    |
    | These are defaults. The admin panel writes overrides into the settings
    | table, which take precedence (see App\Services\LibrarySettings).
    |
    */

    'detect_duplicates' => (bool) env('LIBRARY_DETECT_DUPLICATES', true),

    /*
    | What to do when a byte-identical copy is found:
    |
    |   review  flag it and wait for a decision in the admin panel (default)
    |   auto    delete the redundant file immediately, keep the filed one
    |   report  flag it and never act, even from the review screen
    |
    | 'review' is the default deliberately: this is the only feature that
    | deletes a user's files, so it does nothing until told to.
    */

    'duplicate_action' => env('LIBRARY_DUPLICATE_ACTION', 'review'),

    /*
    | Skip hashing files above this size, in megabytes. Hashing is fast but not
    | free, and a 40 GB remux costs real time on a home machine. Zero disables
    | the limit. Un-hashed files are simply never flagged as duplicates.
    */

    'hash_max_megabytes' => (int) env('LIBRARY_HASH_MAX_MB', 8192),

    /*
    |--------------------------------------------------------------------------
    | Audio Extensions
    |--------------------------------------------------------------------------
    */

    'audio_extensions' => [
        'mp3', 'flac', 'm4a', 'aac', 'wav', 'aiff', 'aif',
        'ogg', 'oga', 'opus', 'wma', 'alac', 'ape', 'wv',
    ],
];

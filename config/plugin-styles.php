<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/*
 * Compiling a plugin's stylesheet (S-350).
 *
 * A plugin's Blade cannot use the app's Tailwind: the app's CSS is compiled at
 * build time, and a plugin installed from the catalogue lives outside the repo
 * on the user's machine, so its markup never existed when that bundle was
 * built. Rather than make every plugin author hand-write CSS, the bundled
 * Tailwind CLI compiles the plugin's own stylesheet when the plugin is enabled.
 *
 * The binary sits beside the PHP binary in the bundled runtime, the same
 * convention `config/transcode.php` uses for ffmpeg.
 */

$bundledBinary = static function (string $name): string {
    $beside = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

    return is_file($beside) ? $beside : $name;
};

return [
    /*
    |--------------------------------------------------------------------------
    | The Tailwind CLI
    |--------------------------------------------------------------------------
    |
    | An explicit path wins; otherwise the bundled binary; otherwise whatever
    | `tailwindcss` resolves to on PATH, which is how a development machine
    | without the bundle still works.
    |
    */

    'binary' => env('TAILWIND_PATH') ?: $bundledBinary('tailwindcss'),

    /*
    |--------------------------------------------------------------------------
    | Whether to compile at all
    |--------------------------------------------------------------------------
    |
    | Off leaves a plugin to its own shipped CSS, which is what plugins did
    | before this existed and what they fall back to when no CLI is available.
    |
    */

    'enabled' => env('PLUGIN_STYLES_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | How long a compile may take
    |--------------------------------------------------------------------------
    |
    | Measured at 46-49ms for a real plugin; ten seconds is a generous ceiling
    | that still fails rather than hanging an enable.
    |
    */

    'timeout' => (int) env('PLUGIN_STYLES_TIMEOUT', 10),
];

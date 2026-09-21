<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Support;

/**
 * Where a server release keeps the data an operator owns, apart from the app.
 *
 * Plugins are the first of these: files an operator drops in and expects to keep
 * across upgrades. Keeping them under the install (`storage/`, the source tree)
 * is wrong for a downloaded release — an upgrade that replaces the install would
 * take them with it, and the path shown in the admin would be whatever machine
 * happened to build the release.
 *
 * So the default is an OS-conventional per-user data directory, outside the
 * install, chosen the way native apps choose one:
 *
 *   - Linux/BSD : $XDG_DATA_HOME/soundchex, else ~/.local/share/soundchex
 *   - macOS     : ~/Library/Application Support/SoundChex
 *   - Windows   : %LOCALAPPDATA%\SoundChex, else %APPDATA%\SoundChex
 *
 * An operator who wants it somewhere specific — a mounted volume, a NAS share —
 * sets `SOUNDCHEX_DATA_DIR` (the whole data root) or `SOUNDCHEX_PLUGINS_PATH`
 * (just the plugins directory) and that wins. Nothing here reads the filesystem
 * or creates directories; it only resolves the path. Creation happens where the
 * directory is used, so a headless box never depends on a writable HOME it may
 * not have — a bad HOME simply falls back to the install's storage directory.
 */
class DataPaths
{
    /**
     * The plugins directory for this install.
     *
     * `SOUNDCHEX_PLUGINS_PATH` overrides everything; otherwise it is `plugins`
     * under the data root.
     */
    public static function plugins(): string
    {
        $explicit = env('SOUNDCHEX_PLUGINS_PATH');

        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/\\');
        }

        return self::root().DIRECTORY_SEPARATOR.'plugins';
    }

    /**
     * The data root — an OS-conventional per-user application data directory,
     * or `SOUNDCHEX_DATA_DIR` when set. Falls back to the install's storage
     * directory when no home can be resolved (a locked-down service account),
     * so resolution never throws.
     */
    public static function root(): string
    {
        $explicit = env('SOUNDCHEX_DATA_DIR');

        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/\\');
        }

        $base = self::platformBase();

        if ($base === null) {
            // No usable home — keep working rather than fail. storage/app is
            // writable by definition (the app already writes there), so a
            // headless box with no HOME still has a plugins directory.
            return storage_path('app');
        }

        return $base.DIRECTORY_SEPARATOR.self::appFolder();
    }

    /**
     * The platform's base directory for per-user application data, or null when
     * none can be resolved.
     */
    private static function platformBase(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::firstEnvPath(['LOCALAPPDATA', 'APPDATA']);
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $home = self::firstEnvPath(['HOME']);

            return $home === null ? null : $home.'/Library/Application Support';
        }

        // Linux / BSD and anything else that follows the XDG convention.
        $xdg = self::firstEnvPath(['XDG_DATA_HOME']);

        if ($xdg !== null) {
            return $xdg;
        }

        $home = self::firstEnvPath(['HOME']);

        return $home === null ? null : $home.'/.local/share';
    }

    /**
     * The application folder name under the platform base. Capitalised on
     * macOS/Windows to match their conventions, lower-case elsewhere.
     */
    private static function appFolder(): string
    {
        return PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin'
            ? 'SoundChex'
            : 'soundchex';
    }

    /**
     * The first of these environment variables that holds a non-empty absolute
     * path, trimmed of a trailing separator; null if none do.
     *
     * @param  array<int, string>  $names
     */
    private static function firstEnvPath(array $names): ?string
    {
        foreach ($names as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                return rtrim($value, '/\\');
            }
        }

        return null;
    }
}

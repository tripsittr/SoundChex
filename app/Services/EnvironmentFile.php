<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Support\Facades\Artisan;

/**
 * Reads and updates individual keys in the project's `.env` file.
 *
 * A handful of settings — `APP_URL`, `APP_ENV` — are read by the framework at
 * boot, not from the settings table, so they genuinely have to live in `.env`.
 * Editing that by hand in a terminal is the wrong experience for a self-hosted
 * app someone installed, and `APP_URL` has a chicken-and-egg (you cannot set the
 * address from a page reached *via* that address) — but the admin panel is
 * reachable on localhost regardless, so it can set it.
 *
 * This preserves the rest of the file: it rewrites only the line for a key,
 * quoting a value that needs it, and appends the key if it is absent. After a
 * write the config cache is cleared so the change takes effect without a manual
 * `php artisan config:clear`.
 */
class EnvironmentFile
{
    private function path(): string
    {
        return base_path('.env');
    }

    /** The current value of a key, or null when unset. */
    public function get(string $key): ?string
    {
        if (! is_readable($this->path())) {
            return null;
        }

        foreach (file($this->path(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/', $line, $m)) {
                return trim($m[1], "\"'");
            }
        }

        return null;
    }

    /**
     * Sets several keys at once, then clears the config cache.
     *
     * @param array<string, string|null> $values Key => value; a null removes the
     *                                            key's override (leaves the line out).
     */
    public function set(array $values): void
    {
        if (! is_writable($this->path())) {
            throw new \RuntimeException('The .env file is not writable.');
        }

        $contents = file_get_contents($this->path());

        foreach ($values as $key => $value) {
            $line = $value === null ? '' : $key . '=' . $this->quote($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = preg_replace($pattern, $line, $contents);
            } elseif ($value !== null) {
                $contents = rtrim($contents, "\n") . "\n" . $line . "\n";
            }
        }

        file_put_contents($this->path(), $contents);

        // So the change is live without a manual config:clear — the whole point
        // of setting this from the UI rather than the terminal.
        Artisan::call('config:clear');
    }

    /** Quotes a value that contains a space, #, or is empty. */
    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }
}

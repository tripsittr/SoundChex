<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Opens a directory in the host machine's file manager.
 *
 * Plugin management is a home-server-only surface: it acts on the machine the
 * server runs on, so "open the folder" means open it *there*, in whatever
 * desktop the operator is sitting at. The caller is responsible for having
 * already confirmed the request came from that machine (loopback) — this class
 * only runs the reveal.
 *
 * Best-effort by design. A headless server has no file manager to open, so a
 * failure is reported to the caller (which then just shows the path) rather than
 * thrown. The path is passed as a single process argument, never through a
 * shell, so a directory name can hold no command.
 */
class RevealInFileManager
{
    /**
     * Attempts to open the directory. Returns true if the reveal command
     * launched, false if there was nothing to launch or it failed.
     */
    public static function open(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        $command = self::commandFor($directory);

        if ($command === null) {
            return false;
        }

        try {
            $process = new Process($command);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable $e) {
            Log::info('Could not open a folder in the file manager', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The per-platform reveal command, or null on a platform we do not know how
     * to open a folder on.
     *
     * @return array<int, string>|null
     */
    private static function commandFor(string $directory): ?array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $directory],
            'Windows' => ['explorer', $directory],
            'Linux', 'BSD' => ['xdg-open', $directory],
            default => null,
        };
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a media file by moving it somewhere recoverable (#489).
 *
 * Nothing in the library is unlinked any more. Every delete the app performs on
 * a user's media — a resolved duplicate, a copy the organizer adopts — moves
 * the file into a dated folder under `library.trash_root` and leaves it there
 * until the scheduled purge removes it, `library.trash_days` later.
 *
 * The reason is the bug this phase exists for: a path-comparison mistake in the
 * organizer deleted the only copy of a file (#489), and there was nothing to
 * restore from. Verifying before destroying is already the rule (AGENTS.md rule
 * 2), but a verification can be wrong — and a delete that is really a move
 * turns "the file is gone" into "the file is in the trash", which is a support
 * question rather than a loss.
 *
 * The trash lives on the same disk as the library deliberately: a move within
 * one filesystem is atomic and instant whatever the file's size, where a copy
 * to another volume could half-finish on a full disk and is slow for a 40 GB
 * remux.
 */
class MediaTrash
{
    /**
     * Moves a file into the trash.
     *
     * Returns the path it was moved to, relative to the local disk, or null
     * when it could not be moved — in which case the file is left exactly where
     * it was. A caller that cannot trash a file must not proceed as though it
     * had been deleted.
     */
    public function discard(string $absolutePath, ?string $reason = null): ?string
    {
        if (! is_file($absolutePath)) {
            // Already gone. Nothing to do, and not an error — a caller
            // re-running after a partial failure lands here.
            return null;
        }

        $relative = $this->destinationFor($absolutePath);
        $destination = Storage::path($relative);
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            Log::error('Could not create a trash folder, so the file was left in place', [
                'path' => $absolutePath,
                'trash' => $directory,
            ]);

            return null;
        }

        if (! @rename($absolutePath, $destination)) {
            // Windows refuses to move a read-only file, the same way it refuses
            // to unlink one — the case the old deleteFile() had to handle.
            if (! (@chmod($absolutePath, 0666) && @rename($absolutePath, $destination))) {
                Log::error('Could not move a file to the trash', [
                    'path' => $absolutePath,
                    'destination' => $destination,
                    'exists' => is_file($absolutePath),
                    'writable' => is_writable($absolutePath),
                    'hint' => 'A file held open by another process cannot be moved on Windows.',
                ]);

                return null;
            }
        }

        Log::info('Moved a media file to the trash', [
            'from' => $absolutePath,
            'to' => $relative,
            'reason' => $reason,
        ]);

        return $relative;
    }

    /**
     * Restores a trashed file to a given absolute path.
     *
     * Refuses rather than overwrites: if something already occupies the target,
     * the caller has to decide, because that something may be the copy that was
     * kept when this one was trashed.
     */
    public function restore(string $relativeTrashPath, string $absoluteTarget): bool
    {
        $source = Storage::path($relativeTrashPath);

        if (! is_file($source)) {
            return false;
        }

        if (file_exists($absoluteTarget)) {
            Log::warning('Refused to restore over an existing file', [
                'from' => $relativeTrashPath,
                'to' => $absoluteTarget,
            ]);

            return false;
        }

        $directory = dirname($absoluteTarget);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return false;
        }

        return @rename($source, $absoluteTarget);
    }

    /**
     * Removes trashed files older than the retention window.
     *
     * Returns how many files were removed. A retention of zero disables the
     * purge entirely, which is the "keep until emptied by hand" setting.
     */
    public function purge(?int $days = null): int
    {
        $days = $days ?? $this->retentionDays();

        if ($days <= 0) {
            return 0;
        }

        $root = Storage::path($this->root());

        if (! is_dir($root)) {
            return 0;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach ((array) glob($root.'/*', GLOB_ONLYDIR) as $dated) {
            // The folder name is the date it was trashed, so a whole day's
            // worth ages out together and the decision is one stat per folder
            // rather than one per file.
            $day = basename((string) $dated);

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }

            if (strtotime($day.' 23:59:59') > $cutoff) {
                continue;
            }

            $removed += $this->removeTree((string) $dated);
        }

        return $removed;
    }

    /** The trash root, relative to the local disk. */
    public function root(): string
    {
        return trim((string) config('library.trash_root', 'media/.trash'), '/');
    }

    public function retentionDays(): int
    {
        return (int) config('library.trash_days', 30);
    }

    /**
     * Where a file should land in the trash.
     *
     * Keeps the file's own name under a dated folder, and keeps enough of its
     * original location to tell two same-named files apart — "Chicago.mp3" from
     * two albums would otherwise collide in the trash and the second would be
     * suffixed into something unrecognisable.
     */
    private function destinationFor(string $absolutePath): string
    {
        $relative = $this->withinDisk($absolutePath) ?? basename($absolutePath);

        $candidate = $this->root().'/'.now()->format('Y-m-d').'/'.$relative;

        return $this->unique($candidate);
    }

    /**
     * The path relative to the local disk, when the file is on it.
     *
     * A file outside the disk (an external drive added as a watch folder) has
     * no meaningful relative path, so the caller falls back to its basename.
     */
    private function withinDisk(string $absolutePath): ?string
    {
        $diskRoot = rtrim(Storage::path(''), '/\\');
        $real = realpath($absolutePath) ?: $absolutePath;

        if (! str_starts_with($real, $diskRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return ltrim(str_replace('\\', '/', substr($real, strlen($diskRoot))), '/');
    }

    /** Appends a counter until the trash path is free. */
    private function unique(string $relative): string
    {
        if (! file_exists(Storage::path($relative))) {
            return $relative;
        }

        $directory = dirname($relative);
        $extension = pathinfo($relative, PATHINFO_EXTENSION);
        $base = pathinfo($relative, PATHINFO_FILENAME);
        $suffix = $extension === '' ? '' : '.'.$extension;

        for ($i = 2; $i < 1000; $i++) {
            $candidate = "{$directory}/{$base} ({$i}){$suffix}";

            if (! file_exists(Storage::path($candidate))) {
                return $candidate;
            }
        }

        // A thousand same-named files trashed on one day. Rather than return an
        // occupied path -- the bug uniquePath() had -- make it unmistakable.
        return "{$directory}/{$base}.".bin2hex(random_bytes(4)).$suffix;
    }

    /** Recursively removes a directory, returning the number of files deleted. */
    private function removeTree(string $directory): int
    {
        $removed = 0;

        foreach ((array) glob($directory.'/*') as $path) {
            $path = (string) $path;

            if (is_dir($path)) {
                $removed += $this->removeTree($path);

                continue;
            }

            if (@unlink($path) || (@chmod($path, 0666) && @unlink($path))) {
                $removed++;
            }
        }

        @rmdir($directory);

        return $removed;
    }
}

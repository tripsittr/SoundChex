<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A compressed snapshot of the catalogue.
 *
 * The library is a catalogue pointing at files, so losing it does not lose the
 * media — but it does lose play history, watchlists, ratings, playlists and
 * profiles, none of which can be rebuilt by rescanning. That is worth a few
 * hundred kilobytes a day.
 *
 * Written with VACUUM INTO rather than a file copy. SQLite is being written to
 * while this runs, and copying the file mid-transaction produces a snapshot
 * that may not open — VACUUM INTO takes a consistent one and compacts it on the
 * way out. Then gzipped, which is worth about six to one on this data.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--keep=14 : How many backups to retain}
        {--path= : Where to write. Defaults to storage/backups.}';

    protected $description = 'Write a compressed, consistent snapshot of the database';

    public function handle(): int
    {
        $database = config('database.connections.' . config('database.default') . '.database');

        if (! is_string($database) || ! is_file($database)) {
            $this->error('Only a file-based SQLite database can be backed up this way.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: storage_path('backups');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d_His');
        $plain = $directory . "/soundchex-{$stamp}.sqlite";

        // Consistent even under concurrent writes, and compacted: a copy taken
        // mid-transaction can be unopenable, which is the one thing a backup
        // must never be.
        DB::statement('VACUUM INTO ?', [$plain]);

        $compressed = $plain . '.gz';
        $source = fopen($plain, 'rb');
        $target = gzopen($compressed, 'wb9');

        // If only one handle opened, close it and take the uncompressed
        // snapshot with it. `prune()` reaps `*.sqlite.gz` and nothing else, so
        // a stranded `.sqlite` is permanent — and it is a full-size copy of the
        // database. On a daily schedule that accumulates until the disk fills,
        // which is the very thing `prune()` exists to prevent (S-359).
        if ($source === false || $target === false) {
            if ($source !== false) {
                fclose($source);
            }

            if ($target !== false) {
                gzclose($target);
                @unlink($compressed);
            }

            @unlink($plain);

            $this->error('Could not compress the snapshot.');

            return self::FAILURE;
        }

        while (! feof($source)) {
            $chunk = (string) fread($source, 262_144);

            // A short write means the disk filled part-way through. Leaving a
            // truncated `.gz` behind would be worse than no backup at all: it
            // looks like one, and `prune()` would count it as a good copy and
            // delete an older, valid one to make room for it.
            if ($chunk !== '' && gzwrite($target, $chunk) === false) {
                fclose($source);
                gzclose($target);
                @unlink($compressed);
                @unlink($plain);

                $this->error('Could not write the compressed snapshot — disk full?');

                return self::FAILURE;
            }
        }

        fclose($source);
        gzclose($target);
        unlink($plain);

        $this->info(sprintf(
            'Backed up to %s (%s).',
            basename($compressed),
            $this->humanBytes(filesize($compressed) ?: 0),
        ));

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * Keeps the most recent few and deletes the rest.
     *
     * Without this a daily backup fills the disk over a year — and a backup
     * that fills the disk takes the server down, which is worse than the risk
     * it was guarding against.
     */
    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $backups = glob($directory . '/soundchex-*.sqlite.gz') ?: [];

        // Newest first. The names sort chronologically, so this needs no stat.
        rsort($backups);

        foreach (array_slice($backups, $keep) as $old) {
            @unlink($old);
        }

        $this->sweepStrandedSnapshots($directory);
    }

    /**
     * Removes uncompressed snapshots a failed run left behind.
     *
     * Only ones this command names (`soundchex-<stamp>.sqlite`) and only when
     * no run is in flight — a `.sqlite` with no matching `.gz` is either an
     * orphan from a failure, or the working file of a backup happening right
     * now. Age decides: anything still being written was created seconds ago.
     *
     * Deliberately not touching hand-named snapshots like
     * `pre-migration-….sqlite`; those are somebody's deliberate safety net.
     */
    private function sweepStrandedSnapshots(string $directory): void
    {
        foreach (glob($directory . '/soundchex-*.sqlite') ?: [] as $stray) {
            if (is_file($stray . '.gz')) {
                continue; // Mid-run, between the write and the unlink.
            }

            $age = time() - (filemtime($stray) ?: time());

            if ($age < 3600) {
                continue; // Possibly a run in flight; leave it for next time.
            }

            @unlink($stray);
        }
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}

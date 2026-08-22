<?php

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

        if ($source === false || $target === false) {
            $this->error('Could not compress the snapshot.');

            return self::FAILURE;
        }

        while (! feof($source)) {
            gzwrite($target, (string) fread($source, 262_144));
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
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}

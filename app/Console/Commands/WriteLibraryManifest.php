<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use Illuminate\Console\Command;

/**
 * Writes down every media file, so a reprocess can be checked afterwards
 * (#489, step 1).
 *
 * Reprocessing 8,335 catalogued items is the riskiest operation in the whole
 * plan, and the thing that makes it survivable is being able to answer "is
 * every file still accounted for?" with a comparison rather than an opinion.
 *
 * Path, size and hash for every file. Written **outside `storage/`** by
 * default, because a manifest inside the tree it describes is no use after
 * something goes wrong with that tree.
 *
 * Deliberately not a database table. The point is to survive the database:
 * a reprocess that corrupts rows must leave the manifest readable, and a flat
 * file on another disk is the only form that guarantees it.
 */
class WriteLibraryManifest extends Command
{
    protected $signature = 'library:manifest
        {--out= : Where to write it (default: the home directory, dated)}
        {--limit=0 : Stop after this many items (0 = all)}
        {--no-hash : Skip hashing, recording path and size only}';

    protected $description = 'Record the path, size and hash of every media file';

    public function handle(DuplicateDetector $detector): int
    {
        $path = $this->destination();

        $items = MediaItem::withoutGlobalScopes()
            ->whereNotNull('file_path')
            ->orderBy('id');

        $limit = max(0, (int) $this->option('limit'));
        $total = $limit > 0 ? min($limit, $items->count()) : $items->count();

        if ($total === 0) {
            $this->info('No catalogued files to record.');

            return self::SUCCESS;
        }

        $skipHash = (bool) $this->option('no-hash');

        $this->info("Recording {$total} file(s) to {$path}".($skipHash ? ' (without hashes).' : '.'));

        if (! $skipHash) {
            // Said up front: hashing a music library is minutes, and a video
            // library can be an hour. Nobody should wonder whether it hung.
            $this->comment('Hashing every file. Use --no-hash for a path-and-size-only manifest.');
        }

        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not write to {$path}.");

            return self::FAILURE;
        }

        // A header, so the file explains itself in a year's time.
        fwrite($handle, "# SoundChex library manifest\n");
        fwrite($handle, '# written '.now()->toIso8601String()."\n");
        fwrite($handle, '# algorithm '.DuplicateDetector::HASH."\n");
        fwrite($handle, "# id\tsize\thash\tpath\n");

        $recorded = $missing = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // lazyById() re-chunks by id and DISCARDS any limit() on the query,
        // so --limit was silently ignored and a run asked for 8 records wrote
        // 8,330. Counted here instead, which also keeps the cursor's memory
        // behaviour -- the reason lazyById is used at all over a library this
        // size.
        $seen = 0;

        foreach ($items->lazyById(200) as $item) {
            if ($seen >= $total) {
                break;
            }

            $seen++;

            $absolute = $item->absoluteFilePath();

            if ($absolute === null || ! is_file($absolute)) {
                // Recorded as missing rather than omitted. A file absent
                // *before* the reprocess must not look like one the reprocess
                // lost -- that distinction is the whole value of this.
                fwrite($handle, implode("\t", [$item->id, '', 'MISSING', (string) $item->file_path])."\n");
                $missing++;
                $bar->advance();

                continue;
            }

            $size = @filesize($absolute);
            $hash = $skipHash ? 'SKIPPED' : ($detector->hash($absolute) ?? 'UNHASHABLE');

            fwrite($handle, implode("\t", [
                $item->id,
                $size === false ? '' : (string) $size,
                $hash,
                (string) $item->file_path,
            ])."\n");

            $recorded++;
            $bar->advance();
        }

        $bar->finish();
        fclose($handle);
        $this->newLine(2);

        $this->info("Recorded {$recorded} file(s).".
            ($missing > 0 ? " {$missing} had no file on this machine, noted as MISSING." : ''));

        $this->comment('Keep this somewhere other than the library disk. It is what proves nothing was lost.');

        return self::SUCCESS;
    }

    /**
     * Where to write it.
     *
     * Outside `storage/` by default: a manifest inside the tree it describes
     * is no use once something has gone wrong with that tree.
     */
    private function destination(): string
    {
        $given = $this->option('out');

        if (filled($given)) {
            return (string) $given;
        }

        $home = getenv('HOME') ?: sys_get_temp_dir();

        return rtrim($home, '/').'/soundchex-manifest-'.now()->format('Ymd-His').'.tsv';
    }
}

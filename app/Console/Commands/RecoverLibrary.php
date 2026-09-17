<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\LibraryScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuilds catalogue rows for media that is already filed.
 *
 * The scanner will not look inside its own output — filed media is catalogued
 * by definition, and re-scanning it would file it again. That is right in
 * normal operation and exactly wrong when a catalogue is lost with the files
 * intact: the media sits on disk and no scan will ever see it.
 *
 * This never moves a file. The organiser is not invoked, so a library that is
 * already correctly filed keeps its structure.
 */
class RecoverLibrary extends Command
{
    protected $signature = 'library:recover
        {--path=* : Folders to recover from. Defaults to the filed library.}
        {--dry-run : List what would be recovered without writing anything}
        {--enrich : Queue metadata lookups and cover extraction for what is recovered}';

    protected $description = 'Rebuild catalogue rows for media already filed on disk';

    public function handle(LibraryScanner $scanner): int
    {
        $folders = $this->option('path') ?: [Storage::path('media/library')];

        $this->line('Recovering from:');

        foreach ($folders as $folder) {
            $this->line('  ' . $folder);
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing will be written.');
        }

        $result = $scanner->recover(
            $folders,
            (bool) $this->option('dry-run'),
            (bool) $this->option('enrich'),
        );

        foreach ($result['titles'] as $title) {
            $this->line('  + ' . $title);
        }

        if ($result['recovered'] > 10) {
            $this->line(sprintf('  … and %d more', $result['recovered'] - 10));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d item%s. %d skipped (already catalogued or unreadable).',
            $this->option('dry-run') ? 'Would recover' : 'Recovered',
            $result['recovered'],
            $result['recovered'] === 1 ? '' : 's',
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}

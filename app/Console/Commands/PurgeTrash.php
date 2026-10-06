<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\MediaTrash;
use Illuminate\Console\Command;

/**
 * Empties media the app deleted, once it is past the retention window.
 *
 * Deletes in the library are moves to `library.trash_root` rather than
 * unlinks (#464), so something has to eventually remove them or the trash grows
 * without bound. This is that something, scheduled daily.
 *
 * Retention is `library.trash_days`, 30 by default. Zero disables the purge
 * entirely, which is the "keep until emptied by hand" setting — this command
 * then does nothing rather than ignoring the preference.
 */
class PurgeTrash extends Command
{
    protected $signature = 'library:purge-trash
        {--days= : Override the retention window for this run}
        {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Remove trashed media files past the retention window';

    public function handle(MediaTrash $trash): int
    {
        $days = $this->option('days') !== null
            ? max(0, (int) $this->option('days'))
            : $trash->retentionDays();

        if ($days <= 0) {
            $this->info('Trash retention is disabled, so nothing was purged.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            // Counted without removing, so the scheduled job's effect can be
            // checked on a real library before it runs for the first time.
            $this->info("Would remove trashed files older than {$days} days from {$trash->root()}.");

            return self::SUCCESS;
        }

        $removed = $trash->purge($days);

        $this->info($removed === 0
            ? 'Nothing in the trash is old enough to purge.'
            : "Purged {$removed} trashed file(s) older than {$days} days.");

        return self::SUCCESS;
    }
}

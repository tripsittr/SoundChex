<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bumps items whose metadata changed without the parent noticing (S-386).
 *
 * The device's delta sync asks for items whose `media_items.updated_at` has
 * moved, but almost everything a person edits lives in a child table — album,
 * artist, rating. Those models now touch their parent, so this is only needed
 * once, for the rows already written before that existed.
 *
 * Until it runs, those items are invisible to every device: a corrected album
 * stays wrong on the phone with no way to ask for it again short of
 * reinstalling.
 *
 * Shows what it would change first; `--force` applies it.
 */
class TouchStaleMetadata extends Command
{
    protected $signature = 'library:touch-stale-metadata
        {--force : Apply the change (otherwise a dry run)}';

    protected $description = 'Bump items whose metadata is newer than the item row, so devices resync them';

    /** The child tables whose writes used to go unnoticed. */
    private const METADATA_TABLES = [
        'music_metadata',
        'movie_metadata',
        'show_metadata',
        'book_metadata',
    ];

    public function handle(): int
    {
        $ids = collect();

        foreach (self::METADATA_TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $found = DB::table('media_items')
                ->join($table, $table.'.media_item_id', '=', 'media_items.id')
                ->whereColumn($table.'.updated_at', '>', 'media_items.updated_at')
                ->pluck('media_items.id');

            $this->line("  {$table}: {$found->count()} item(s) behind");

            $ids = $ids->merge($found);
        }

        $ids = $ids->unique()->values();

        if ($ids->isEmpty()) {
            $this->info('Every item is at least as new as its metadata. Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $this->option('force')) {
            $this->warn("Dry run: {$ids->count()} item(s) would be touched.");
            $this->line('Run again with --force to apply.');

            return self::SUCCESS;
        }

        // Chunked: a `whereIn` of several thousand ids is a query SQLite
        // refuses outright.
        $touched = 0;

        foreach ($ids->chunk(500) as $chunk) {
            $touched += DB::table('media_items')
                ->whereIn('id', $chunk)
                ->update(['updated_at' => now()]);
        }

        $this->info("Touched {$touched} item(s). Devices will pick them up on the next sync.");

        return self::SUCCESS;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\LibraryOrganizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Files catalogued music into the Artist/Album/## Track tree.
 *
 * Previews by default — this relocates the user's actual files, so moving
 * anything takes an explicit `--move`.
 */
class OrganizeLibrary extends Command
{
    protected $signature = 'library:organize
        {--move : Actually move files (without this, changes are only previewed)}
        {--limit= : Only process this many items}';

    protected $description = 'Sort catalogued music into Artist/Album/Track folders';

    public function handle(LibraryOrganizer $organizer): int
    {
        $dryRun = ! $this->option('move');

        $query = MediaItem::unresolved()
            ->where('type', MediaItemType::Music)
            ->whereNotNull('file_path')
            ->with('musicMetadata');

        if (filled($this->option('limit'))) {
            $query->limit((int) $this->option('limit'));
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            $this->info('Nothing to organize.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;

        // Kept apart from $skipped on purpose. An item without an artist yet is
        // waiting; an item that passed every check and still didn't move is
        // broken. Counting both as "skipped" hid a failure behind a reassuring
        // message.
        $failed = [];

        foreach ($items as $item) {
            if (! $organizer->canOrganize($item)) {
                $skipped++;

                continue;
            }

            // Asked before organizing, not after: an item already at its own
            // target is a no-op, and organize() reports that with the same
            // null it uses for a real failure.
            if ($organizer->isAlreadyFiled($item)) {
                $skipped++;

                continue;
            }

            $target = $organizer->organize($item, dryRun: $dryRun);

            if ($target === null) {
                $failed[] = $item;

                continue;
            }

            $this->line(($dryRun ? '  would move → ' : '  moved → ').$target);
            $moved++;
        }

        $this->newLine();

        if ($dryRun) {
            $this->info($moved.' '.str('file')->plural($moved).' would be moved');
            $this->comment('Re-run with --move to apply.');
        } else {
            $this->info($moved.' '.str('file')->plural($moved).' moved');
        }

        if ($skipped > 0) {
            $this->comment($skipped.' waiting — no artist/album yet, or already filed');
        }

        $this->reportFailures($failed, $organizer);

        // A non-zero exit matters when this runs on a schedule — otherwise a
        // run where nothing could be filed looks identical to a clean one.
        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Names every item that passed its checks and still didn't move.
     *
     * @param  array<int, MediaItem>  $failed
     */
    private function reportFailures(array $failed, LibraryOrganizer $organizer): void
    {
        if ($failed === []) {
            return;
        }

        $this->newLine();
        $this->error(count($failed).' could not be filed:');

        foreach ($failed as $item) {
            $this->line('  • '.$item->title);
            $this->line('      from: '.($item->absoluteFilePath() ?? $item->file_path));
            $this->line('      to:   '.($organizer->targetPath($item) ?? '(no target)'));
            $this->line('      why:  '.$this->diagnose($item, $organizer));
        }
    }

    /**
     * A plain-language reason the move failed.
     *
     * The organizer returns null for several distinct problems, so this
     * re-checks the likely causes rather than leaving the user to guess.
     */
    private function diagnose(MediaItem $item, LibraryOrganizer $organizer): string
    {
        $source = $item->absoluteFilePath();

        if ($source === null) {
            return 'source file is missing or unreadable';
        }

        $target = $organizer->targetPath($item);

        if ($target === null) {
            return 'could not build a destination path from this metadata';
        }

        $directory = dirname(Storage::path($target));

        if (! is_dir($directory) && ! is_writable(dirname($directory))) {
            return 'cannot create '.$directory.' (permission denied)';
        }

        if (is_dir($directory) && ! is_writable($directory)) {
            return $directory.' is not writable';
        }

        if (! is_readable($source)) {
            return 'source file is not readable';
        }

        return 'the move failed — check disk space and permissions';
    }
}

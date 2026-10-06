<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MusicMetadata;
use Illuminate\Console\Command;

/**
 * Clears track numbers that were never track numbers (#507).
 *
 * Measured on a real library: **7,654 of 8,233** rows carried a value above
 * 100, each matching the index prefix on its own filename — `906 Stressed
 * Out.mp3` stored as track 906. Only 579 were plausible.
 *
 * The reader now rejects these as they arrive; this is for the rows already
 * written. Nulled rather than corrected, because the right value is not
 * recoverable from a wrong one — a real tag read or a metadata source can fill
 * it later, and an empty field is honest where an invented one is not.
 */
class ClearImplausibleTrackNumbers extends Command
{
    protected $signature = 'library:clear-bad-track-numbers
        {--above=100 : Treat anything greater than this as not a track number}
        {--match-filename : Also clear a value that equals the filename\'s leading index}
        {--dry-run : Count them without changing anything}';

    protected $description = 'Null track numbers that are filename indexes rather than positions';

    /**
     * Below this, a number matching the filename is taken as real.
     *
     * `02 Hang Me Up to Dry.mp3` as track 2 is album numbering, not an index,
     * and 162 of this library's 431 filename matches were that shape.
     */
    private const PLAUSIBLE_TRACK = 30;

    public function handle(): int
    {
        $above = max(1, (int) $this->option('above'));

        $query = MusicMetadata::where('track_number', '>', $above);
        $count = (clone $query)->count();
        $kept = MusicMetadata::whereBetween('track_number', [1, $above])->count();

        if ($count === 0 && ! $this->option('match-filename')) {
            $this->info('No implausible track numbers. Nothing to do.');

            return self::SUCCESS;
        }

        // The filename pass can still have work when the ceiling pass does
        // not: "75 Dearly Departed.mp3" stored as track 75 is below any sane
        // limit and still an index.
        if ($count === 0) {
            $this->info('Nothing above the ceiling; checking filenames.');

            $cleared = $this->option('dry-run')
                ? $this->countFilenameMatches()
                : $this->clearFilenameMatches();

            if ($this->option('dry-run')) {
                $this->comment("{$cleared} row(s) match their filename's leading index. Nothing was written.");
            }

            return self::SUCCESS;
        }

        $this->warn("{$count} row(s) carry a track number above {$above}; {$kept} are plausible and stay.");

        // A handful by name, so the operator can see these are indexes rather
        // than take it on trust -- the filename is the evidence.
        $this->newLine();
        $this->table(
            ['item', 'stored track', 'file'],
            (clone $query)->limit(5)->get()->map(function (MusicMetadata $meta): array {
                $item = $meta->mediaItem;

                return [
                    $meta->media_item_id,
                    $meta->track_number,
                    $item?->file_path ? basename((string) $item->file_path) : '—',
                ];
            })->all(),
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Nothing was written. Re-run without --dry-run to clear them.');

            return self::SUCCESS;
        }

        // One statement rather than a loop: nothing here needs a model event,
        // and 7,654 saves would be 7,654 queries.
        $cleared = $query->update(['track_number' => null]);

        $cleared += $this->clearFilenameMatches();

        $this->newLine();
        $this->info("Cleared {$cleared} track number(s). A re-read of the tags, or a metadata source, can fill them properly.");

        return self::SUCCESS;
    }

    /**
     * How many would the filename pass clear, without clearing them.
     *
     * Shares `matchesFilenameIndex()` with the real pass rather than repeating
     * the rule, so a dry run cannot report a different number from what the
     * run does.
     */
    private function countFilenameMatches(): int
    {
        $count = 0;

        MusicMetadata::whereNotNull('track_number')
            ->with('mediaItem')
            ->lazyById(500)
            ->each(function (MusicMetadata $meta) use (&$count): void {
                if ($this->matchesFilenameIndex($meta)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Whether the stored number is just the filename's leading index.
     *
     * **A match alone is not enough.** A file named `02 Hang Me Up to Dry.mp3`
     * stored as track 2 is ordinary album numbering and correct — on this
     * library **162 of 431** filename matches were exactly that. Clearing them
     * would destroy right answers to tidy up wrong ones.
     *
     * So a match only counts above `PLAUSIBLE_TRACK`: a leading 02 is a track,
     * a leading 68 on a file whose album has a dozen songs is a position in
     * somebody's export. The line is a judgement, which is why this whole pass
     * is opt-in and the ceiling pass -- which rests on a fact -- is not.
     */
    private function matchesFilenameIndex(MusicMetadata $meta): bool
    {
        $track = (int) $meta->track_number;

        if ($track <= self::PLAUSIBLE_TRACK) {
            return false;
        }

        $name = basename((string) $meta->mediaItem?->file_path);

        if (preg_match('/^(\d{1,4})\D/', $name, $match) !== 1) {
            return false;
        }

        return (int) $match[1] === $track;
    }

    /**
     * Clears a track number that is just the filename's leading index.
     *
     * A ceiling alone cannot catch these: `75 Dearly Departed.mp3` stored as
     * track 75 is below any sane limit and still not a track number. The
     * filename is the evidence, and a value equal to its leading index is
     * almost certainly that index rather than a coincidence.
     *
     * Only with `--match-filename`, because it is a *heuristic* where the
     * ceiling is a fact: track 7 of an album genuinely named `07 Song.mp3` is
     * correct, and clearing it would lose a right answer. Opt-in keeps the
     * safe pass safe.
     */
    private function clearFilenameMatches(): int
    {
        if (! $this->option('match-filename')) {
            return 0;
        }

        $cleared = 0;

        MusicMetadata::whereNotNull('track_number')
            ->with('mediaItem')
            ->lazyById(500)
            ->each(function (MusicMetadata $meta) use (&$cleared): void {
                if (! $this->matchesFilenameIndex($meta)) {
                    return;
                }

                $meta->forceFill(['track_number' => null])->saveQuietly();
                $cleared++;
            });

        if ($cleared > 0) {
            $this->info("Cleared {$cleared} more that matched the filename's leading index.");
        }

        return $cleared;
    }
}

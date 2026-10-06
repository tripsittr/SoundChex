<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\FileMoveState;
use App\Models\FileMove;
use App\Services\FileMoveJournal;
use Illuminate\Console\Command;

/**
 * Puts files back where they were (#465).
 *
 * The other half of the journal's purpose. Filing 8,000 items is only a
 * reasonable thing to do if it can be undone, and before this there was no
 * record of what had moved — the organizer's log said a file had been filed,
 * not where it had come from.
 *
 * Moves are reversed newest first, so a file moved twice ends up at its
 * original path rather than its intermediate one.
 */
class UndoFileMoves extends Command
{
    protected $signature = 'library:undo-moves
        {--batch= : Undo one batch of moves}
        {--item= : Undo the moves of one media item}
        {--since= : Undo everything moved since a time ("2 hours ago", "2026-10-06")}
        {--limit=500 : Stop after this many}
        {--dry-run : List what would be undone without touching anything}';

    protected $description = 'Reverse journalled file moves';

    public function handle(FileMoveJournal $journal): int
    {
        $query = FileMove::where('state', FileMoveState::Done);

        $scoped = false;

        if ($batch = $this->option('batch')) {
            $query->where('batch_id', $batch);
            $scoped = true;
        }

        if ($item = $this->option('item')) {
            $query->where('media_item_id', (int) $item);
            $scoped = true;
        }

        if ($since = $this->option('since')) {
            try {
                $query->where('completed_at', '>=', new \DateTimeImmutable($since));
                $scoped = true;
            } catch (\Throwable) {
                $this->error("Could not understand --since={$since}.");

                return self::FAILURE;
            }
        }

        // Refusing an unscoped undo on purpose: "reverse every move ever
        // recorded" is almost never what someone means, and it is not a
        // mistake that should be one keystroke away.
        if (! $scoped) {
            $this->error('Give --batch, --item or --since. Undoing every move ever made needs to be asked for explicitly.');

            return self::FAILURE;
        }

        // Newest first, so a file moved twice lands back at its original path.
        $moves = $query->orderByDesc('id')->limit((int) $this->option('limit'))->get();

        if ($moves->isEmpty()) {
            $this->info('No completed moves match.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'kind', 'from', 'to', 'reversible'],
                $moves->map(fn (FileMove $m): array => [
                    $m->id,
                    $m->kind->value,
                    $this->shorten((string) $m->from_path),
                    $this->shorten((string) $m->to_path),
                    $m->isReversible() ? 'yes' : 'no — the old path is taken',
                ])->all(),
            );

            return self::SUCCESS;
        }

        $undone = $blocked = 0;

        foreach ($moves as $move) {
            if ($journal->undo($move)) {
                $undone++;

                continue;
            }

            // Named rather than counted silently: the usual reason is that
            // something else now occupies the original path, and restoring over
            // it would destroy that file.
            $this->warn("Could not undo move {$move->id}: ".$this->shorten((string) $move->from_path));
            $blocked++;
        }

        $this->info("Undid {$undone} move(s).".($blocked > 0 ? " {$blocked} could not be reversed." : ''));

        return self::SUCCESS;
    }

    /** The tail of a path, which is the part that identifies it in a table. */
    private function shorten(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));

        return implode('/', array_slice($parts, -3));
    }
}

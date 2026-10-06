<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\FileMoveState;
use App\Models\FileMove;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use Illuminate\Console\Command;

/**
 * Checks every file in a manifest is still accounted for (#470, step 5).
 *
 * The plan's acceptance test for a reprocess: *every file in the manifest
 * exists at its old or new path with the same hash, or is in trash with a
 * journal row.* This is that sentence as a command.
 *
 * The three outcomes it distinguishes matter:
 *
 *  - **moved** — the file is at a new path with the same bytes. Expected.
 *  - **trashed** — gone from the library but recoverable, with a journal row
 *    proving it was deliberate.
 *  - **lost** — not where it was, not where the row says, not in the trash,
 *    with nothing recording a move. The only outcome that is a problem, and
 *    the whole reason to run this.
 */
class VerifyLibraryManifest extends Command
{
    protected $signature = 'library:verify-manifest
        {manifest : The .tsv written by library:manifest}
        {--no-hash : Compare path and size only, skipping the content hash}';

    protected $description = 'Check every file a manifest recorded is still accounted for';

    public function handle(DuplicateDetector $detector): int
    {
        $path = (string) $this->argument('manifest');

        if (! is_file($path)) {
            $this->error("No manifest at {$path}.");

            return self::FAILURE;
        }

        $rows = $this->read($path);

        if ($rows === []) {
            $this->error('That manifest has no records in it.');

            return self::FAILURE;
        }

        $this->info('Checking '.count($rows).' recorded file(s).');

        $intact = $moved = $trashed = $wasMissing = 0;
        $lost = [];
        $changed = [];

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $row) {
            $outcome = $this->checkOne($row, $detector);

            match ($outcome['state']) {
                'intact' => $intact++,
                'moved' => $moved++,
                'trashed' => $trashed++,
                'was_missing' => $wasMissing++,
                'changed' => $changed[] = $outcome,
                default => $lost[] = $outcome,
            };

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['outcome', 'files'], [
            ['Still where it was', $intact],
            ['Moved, same bytes', $moved],
            ['In the trash, recoverable', $trashed],
            ['Was already missing before', $wasMissing],
            ['Contents changed', count($changed)],
            ['LOST', count($lost)],
        ]);

        foreach ([['changed', $changed], ['LOST', $lost]] as [$label, $problems]) {
            if ($problems === []) {
                continue;
            }

            $this->newLine();
            $this->warn(count($problems).' '.$label.':');

            foreach (array_slice($problems, 0, 20) as $problem) {
                $this->line('  #'.$problem['id'].'  '.$problem['path']);
            }

            if (count($problems) > 20) {
                $this->line('  ... and '.(count($problems) - 20).' more.');
            }
        }

        if ($lost !== [] || $changed !== []) {
            // A non-zero exit, so this can gate a batch in a script: the plan
            // says to verify after each batch of 500, and a check nobody acts
            // on is not a check.
            $this->newLine();
            $this->error('Not every file is accounted for. Do not continue until this is understood.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Every recorded file is accounted for.');

        return self::SUCCESS;
    }

    /**
     * What happened to one recorded file.
     *
     * @param  array{id: int, size: ?int, hash: string, path: string}  $row
     * @return array{state: string, id: int, path: string}
     */
    private function checkOne(array $row, DuplicateDetector $detector): array
    {
        $result = ['id' => $row['id'], 'path' => $row['path']];

        // Already absent when the manifest was written. Not this run's doing,
        // and conflating the two would make a pre-existing problem look like
        // one the reprocess caused.
        if ($row['hash'] === 'MISSING') {
            return $result + ['state' => 'was_missing'];
        }

        $item = MediaItem::withoutGlobalScopes()->find($row['id']);

        // Where it was, and where the catalogue says it is now.
        $candidates = array_filter([
            $this->absolute($row['path']),
            $item?->absoluteFilePath(),
        ]);

        foreach ($candidates as $index => $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            if (! $this->matches($candidate, $row, $detector)) {
                // Present but different. Worth distinguishing from lost: the
                // bytes changed, which a re-tag or a cover embed legitimately
                // does, and silently calling that "fine" would hide a real
                // corruption.
                return $result + ['state' => 'changed'];
            }

            return $result + ['state' => $index === 0 ? 'intact' : 'moved'];
        }

        // Not at either path. Deliberate only if something recorded moving it.
        if ($this->wasTrashed($row['id'])) {
            return $result + ['state' => 'trashed'];
        }

        return $result + ['state' => 'lost'];
    }

    /** Whether the journal records this item's file going to the trash. */
    private function wasTrashed(int $itemId): bool
    {
        return FileMove::where('media_item_id', $itemId)
            ->where('kind', \App\Enums\FileMoveKind::Trash)
            ->whereIn('state', [FileMoveState::Done, FileMoveState::Started])
            ->exists();
    }

    /**
     * @param  array{size: ?int, hash: string}  $row
     */
    private function matches(string $candidate, array $row, DuplicateDetector $detector): bool
    {
        if ($row['size'] !== null) {
            $size = @filesize($candidate);

            if ($size !== false && $size !== $row['size']) {
                return false;
            }
        }

        if ($this->option('no-hash') || $row['hash'] === 'SKIPPED' || $row['hash'] === 'UNHASHABLE') {
            return true;
        }

        return $detector->hash($candidate) === $row['hash'];
    }

    /**
     * Reads the manifest.
     *
     * @return array<int, array{id: int, size: ?int, hash: string, path: string}>
     */
    private function read(string $path): array
    {
        $rows = [];

        foreach ((array) file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (! is_string($line) || $line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) < 4) {
                continue;
            }

            [$id, $size, $hash] = $parts;

            $rows[] = [
                'id' => (int) $id,
                'size' => $size === '' ? null : (int) $size,
                'hash' => $hash,
                // Re-joined, because a path can legitimately contain a tab.
                'path' => implode("\t", array_slice($parts, 3)),
            ];
        }

        return $rows;
    }

    private function absolute(string $stored): string
    {
        return str_starts_with($stored, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $stored) === 1
            ? $stored
            : \Storage::path($stored);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Repairs catalogue rows whose stored file path no longer points at a file.
 *
 * The common case is a duplicate row still pointing at the pre-filed source
 * path after another row moved or adopted the same underlying file. This
 * command relinks those rows to a surviving path and reports what is genuinely
 * gone.
 */
class ReconcileMediaPaths extends Command
{
    protected $signature = 'library:reconcile-paths
        {--apply : Write corrections. Without this, only reports are produced.}
        {--report= : CSV path for rows that could not be repaired. Defaults to storage/app/reports.}';

    protected $description = 'Repair media rows whose file_path points at a missing file';

    /** @var array<string, array{id:int, path:string}> */
    private array $liveByDirAndTitle = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->buildLiveIndex();

        $rows = MediaItem::query()
            ->whereNotNull('file_path')
            ->with(['duplicateOf:id,file_path'])
            ->orderBy('id')
            ->get(['id', 'title', 'file_path', 'duplicate_of_id']);

        $scanned = 0;
        $alreadyValid = 0;
        $relinked = 0;
        $unresolved = [];

        foreach ($rows as $item) {
            $scanned++;

            $absolute = $this->absoluteFromStored($item->file_path);

            if ($absolute !== null && is_file($absolute)) {
                $alreadyValid++;
                continue;
            }

            $repair = $this->repairPathFor($item);

            if ($repair === null) {
                $unresolved[] = [
                    'id' => (string) $item->id,
                    'title' => (string) $item->title,
                    'stored_path' => (string) $item->file_path,
                    'reason' => 'no surviving match',
                ];
                continue;
            }

            if ($apply) {
                $item->file_path = $repair['path'];

                // The stored hash described the file at the *old* path, so it
                // is now a description of something else. Leaving it made
                // byte-identical detection blind — two rows on one file held
                // two different hashes and neither matched the file, so the
                // pair was never paired and kept coming back to review (S-346).
                $item->content_hash = null;

                $item->saveQuietly();
            }

            $relinked++;
        }

        $reportPath = $this->writeReport($unresolved);

        $this->newLine();
        $this->info(sprintf(
            '%s %d stale path(s). %d unresolved (genuinely gone or ambiguous).',
            $apply ? 'Repaired' : 'Would repair',
            $relinked,
            count($unresolved),
        ));

        $this->line('Scanned: ' . $scanned);
        $this->line('Already valid: ' . $alreadyValid);
        $this->line('Unresolved report: ' . $reportPath);

        if (! $apply) {
            $this->comment('Dry run only. Re-run with --apply to write corrections.');
        }

        return self::SUCCESS;
    }

    /**
     * Builds one lookup of rows that already point at an existing file.
     */
    private function buildLiveIndex(): void
    {
        $this->liveByDirAndTitle = [];

        $rows = MediaItem::query()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get(['id', 'title', 'file_path']);

        foreach ($rows as $row) {
            $absolute = $this->absoluteFromStored($row->file_path);

            if ($absolute === null || ! is_file($absolute)) {
                continue;
            }

            $key = $this->dirAndTitleKey((string) $row->file_path, (string) $row->title);

            if (! isset($this->liveByDirAndTitle[$key])) {
                $this->liveByDirAndTitle[$key] = [
                    'id' => (int) $row->id,
                    'path' => (string) $row->file_path,
                ];
            }
        }
    }

    /**
     * @return array{path:string,id:int}|null
     */
    private function repairPathFor(MediaItem $item): ?array
    {
        $stored = (string) $item->file_path;

        // Strongest signal: an explicit duplicate relation whose original still exists.
        $original = $item->duplicateOf;

        if ($original !== null && filled($original->file_path)) {
            $originalAbsolute = $this->absoluteFromStored((string) $original->file_path);

            if ($originalAbsolute !== null && is_file($originalAbsolute)) {
                return ['path' => (string) $original->file_path, 'id' => (int) $original->id];
            }
        }

        // Next: another live row with the same title in the same directory.
        $live = $this->liveByDirAndTitle[$this->dirAndTitleKey($stored, (string) $item->title)] ?? null;

        if ($live !== null && $live['id'] !== (int) $item->id) {
            return ['path' => $live['path'], 'id' => $live['id']];
        }

        // Last: one unambiguous file in the same directory that normalizes to the same stem.
        return $this->bestFileMatch($stored);
    }

    /**
     * @return array{path:string,id:int}|null
     */
    private function bestFileMatch(string $stored): ?array
    {
        $absolute = $this->absoluteFromStored($stored);

        if ($absolute === null) {
            return null;
        }

        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            return null;
        }

        $files = array_values(array_filter(
            scandir($directory) ?: [],
            fn (string $name): bool => $name !== '.' && $name !== '..' && is_file($directory . DIRECTORY_SEPARATOR . $name),
        ));

        if ($files === []) {
            return null;
        }

        $wantedExtension = mb_strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        $wantedStem = $this->normalizeStem(pathinfo($stored, PATHINFO_FILENAME));

        $matches = [];

        foreach ($files as $name) {
            $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($wantedExtension !== '' && $ext !== $wantedExtension) {
                continue;
            }

            if ($this->normalizeStem(pathinfo($name, PATHINFO_FILENAME)) === $wantedStem) {
                $matches[] = $name;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $relativeDirectory = $this->toStoredDirectory($stored);

        if ($relativeDirectory === null) {
            return null;
        }

        return [
            'path' => $relativeDirectory . '/' . $matches[0],
            'id' => 0,
        ];
    }

    private function dirAndTitleKey(string $stored, string $title): string
    {
        $directory = $this->toStoredDirectory($stored) ?? $this->normalizePath(dirname($stored));

        return $directory . '|' . mb_strtolower(trim($title));
    }

    private function normalizeStem(string $stem): string
    {
        $value = mb_strtolower(trim($stem));
        $value = preg_replace('/^\d+\s+/', '', $value) ?? $value;

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function absoluteFromStored(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $path = (string) $stored;

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1) {
            return $path;
        }

        return Storage::path($path);
    }

    private function toStoredDirectory(string $stored): ?string
    {
        $path = $this->normalizePath($stored);

        if ($path === '') {
            return null;
        }

        // dirname() preserves whichever form the stored path was in, so an
        // absolute path stays absolute and a relative one stays relative.
        return $this->normalizePath(dirname($path));
    }

    /**
     * @param  array<int, array{id:string,title:string,stored_path:string,reason:string}>  $rows
     */
    private function writeReport(array $rows): string
    {
        $requested = $this->option('report');

        if (is_string($requested) && trim($requested) !== '') {
            $path = $this->expandPath($requested);
        } else {
            $directory = Storage::path('reports');
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            $path = $directory . '/missing-media-' . now()->format('Ymd-His') . '.csv';
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            return $path;
        }

        fputcsv($handle, ['id', 'title', 'stored_path', 'reason']);

        foreach ($rows as $row) {
            fputcsv($handle, [$row['id'], $row['title'], $row['stored_path'], $row['reason']]);
        }

        fclose($handle);

        return $path;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/') . substr($path, 1);
        }

        return $path;
    }
}

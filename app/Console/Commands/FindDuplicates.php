<?php

namespace App\Console\Commands;

use App\Enums\DuplicateStatus;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use Illuminate\Console\Command;

/**
 * Hashes catalogued files and flags byte-identical copies.
 *
 * Flagging only — nothing is deleted here regardless of the configured action.
 * Merging happens from the review screen, or from `--merge` after the user has
 * seen what was found.
 */
class FindDuplicates extends Command
{
    protected $signature = 'library:duplicates
        {--merge : Merge every pending duplicate instead of only listing them}
        {--rehash : Recompute hashes that are already stored}';

    protected $description = 'Find byte-identical duplicate files in the library';

    public function handle(DuplicateDetector $detector): int
    {
        if ($this->option('rehash')) {
            MediaItem::query()->whereNotNull('content_hash')
                ->update(['content_hash' => null]);
        }

        $items = MediaItem::query()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get();

        $this->info('Hashing ' . $items->count() . ' files…');

        $bar = $this->output->createProgressBar($items->count());

        $flagged = 0;
        $unhashable = 0;

        foreach ($items as $item) {
            if ($detector->ensureHashed($item) === null) {
                $unhashable++;
                $bar->advance();

                continue;
            }

            if ($detector->check($item) !== null) {
                $flagged++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($unhashable > 0) {
            $this->comment($unhashable . ' could not be hashed (missing, unreadable, or over the size limit)');
        }

        $pending = MediaItem::where('duplicate_status', DuplicateStatus::Pending)->count();

        if ($pending === 0) {
            $this->info('No duplicates pending review.');

            return self::SUCCESS;
        }

        $this->warn($pending . ' ' . str('duplicate')->plural($pending) . ' pending review');
        $this->listPending();

        if (! $this->option('merge')) {
            $this->newLine();
            $this->comment('Review them under Library → Duplicates, or re-run with --merge.');

            return self::SUCCESS;
        }

        return $this->mergeAll($detector);
    }

    private function listPending(): void
    {
        $rows = MediaItem::query()
            ->where('duplicate_status', DuplicateStatus::Pending)
            ->with('duplicateOf')
            ->take(20)
            ->get()
            ->map(fn (MediaItem $item): array => [
                $item->id,
                str($item->title)->limit(38),
                str($item->duplicateOf?->title ?? '—')->limit(38),
                $this->humanSize($item),
            ]);

        $this->table(['ID', 'Duplicate', 'Original', 'Size'], $rows);
    }

    private function mergeAll(DuplicateDetector $detector): int
    {
        $pending = MediaItem::where('duplicate_status', DuplicateStatus::Pending)->get();

        $merged = 0;
        $refused = 0;

        foreach ($pending as $item) {
            $detector->merge($item) ? $merged++ : $refused++;
        }

        $this->newLine();
        $this->info($merged . ' merged');

        if ($refused > 0) {
            // merge() refuses when the bytes no longer match or the original is
            // gone — both mean the copy was not safe to delete.
            $this->warn($refused . ' left alone (contents diverged, or the original is missing)');
        }

        return self::SUCCESS;
    }

    private function humanSize(MediaItem $item): string
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return '—';
        }

        return number_format(filesize($path) / 1048576, 1) . ' MB';
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Titles;
use Illuminate\Console\Command;

/**
 * Restores titles that a byte-mask `trim()` cut in half.
 *
 * `trim($title, " -–—_")` put the bytes of two dashes into the mask, and `E2`
 * and `80` begin every character in the General Punctuation block. So a
 * leading curly quote, ellipsis or bullet lost its first two bytes and left
 * the third stranded — a row of invalid UTF-8 in the catalogue.
 *
 * The corruption is not reversible. `<0x99>Cause I'm a Man` cannot become
 * `'Cause I'm a Man` again by inspection: the bytes that said which character
 * it was are gone. So the title is re-derived from the filename, which was
 * never trimmed, rather than patched.
 *
 * Reports by default; `--apply` writes.
 */
class RepairCorruptTitles extends Command
{
    protected $signature = 'library:repair-corrupt-titles
        {--apply : Write the corrected titles. Without this, nothing changes.}';

    protected $description = 'Restore titles left as invalid UTF-8 by the byte-mask trim';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Every text column, not just `title`. The bug was in a shared helper,
        // so assuming it only reached one column is how the second one gets
        // missed — and it costs one pass to be sure.
        $broken = MediaItem::unresolved()
            ->get()
            ->map(fn (MediaItem $item): array => [
                'item' => $item,
                'columns' => $this->corruptColumns($item),
            ])
            ->filter(fn (array $row): bool => $row['columns'] !== []);

        if ($broken->isEmpty()) {
            $this->info('No corrupt text found.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $apply) {
            $this->comment("Dry run over {$broken->count()} rows. Nothing will be written — pass --apply to do it.");
            $this->newLine();
        }

        $repaired = 0;
        $stuck = 0;

        foreach ($broken as $row) {
            /** @var MediaItem $item */
            $item = $row['item'];

            // Only `title` can be re-derived — the filename is the one thing
            // the trim never touched. Anything else corrupt is reported and
            // left alone rather than guessed at.
            if ($row['columns'] !== ['title']) {
                $this->warn("#{$item->id} corrupt in ".implode(', ', $row['columns']).' — not repairable from a filename');
                $stuck++;

                continue;
            }

            $derived = $this->titleFromPath($item);

            if ($derived === null || $derived === '') {
                $this->warn("#{$item->id} has no filename to re-derive from");
                $stuck++;

                continue;
            }

            $this->line("#{$item->id}  ".$this->readable($item->title).'  ->  '.$derived);

            if ($apply) {
                $item->forceFill(['title' => $derived])->save();
            }

            $repaired++;
        }

        $this->newLine();
        $this->info($apply
            ? "Repaired {$repaired} titles.".($stuck > 0 ? " {$stuck} left alone." : '')
            : "{$repaired} would be repaired.".($stuck > 0 ? " {$stuck} would be left alone." : ''));

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function corruptColumns(MediaItem $item): array
    {
        $columns = ['title', 'artist', 'album', 'primary_artist', 'notes'];

        return array_values(array_filter($columns, function (string $column) use ($item): bool {
            $value = $item->getAttribute($column);

            return is_string($value) && $value !== '' && ! mb_check_encoding($value, 'UTF-8');
        }));
    }

    /**
     * The title the scanner would produce from the filename today.
     *
     * The filename kept its bytes — only the derived title was trimmed — so it
     * is the one surviving record of what the character was.
     */
    private function titleFromPath(MediaItem $item): ?string
    {
        if ($item->file_path === null) {
            return null;
        }

        $name = pathinfo($item->file_path, PATHINFO_FILENAME);

        if (! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        // The same leading track number the scanner strips: "906 Stressed Out".
        $name = preg_replace('/^\d{1,4}\s*[-–—.]?\s+/u', '', $name) ?? $name;

        return Titles::trim($name);
    }

    /** Corrupt bytes cannot be printed, so show what they are instead. */
    private function readable(string $value): string
    {
        return '0x'.bin2hex(mb_substr($value, 0, 1, '8bit')).substr($value, 1, 28);
    }
}

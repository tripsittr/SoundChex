<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\Annotation;
use Illuminate\Console\Command;

/**
 * Collapses duplicate highlight rectangles.
 *
 * `getClientRects()` returns one rectangle per text span, so a passage split
 * across several spans on one line produced several overlapping rectangles.
 * Each was painted as its own translucent button, which made the highlight
 * darker with every layer and left a stack of buttons the delete handler had
 * to fight through.
 *
 * The reader merges them at capture now; this fixes the rows written before
 * that, which would otherwise stay stacked and hard to remove forever.
 */
class MergeAnnotationRects extends Command
{
    protected $signature = 'annotations:merge-rects {--dry-run : Report without writing}';

    protected $description = 'Merge overlapping rectangles in existing highlights';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $annotations = Annotation::query()
            ->whereNotNull('location')
            ->get();

        $changed = 0;
        $removed = 0;

        foreach ($annotations as $annotation) {
            $location = $annotation->location;
            $rects = $location['rects'] ?? null;

            // EPUB highlights are located by CFI and have no rectangles.
            if (! is_array($rects) || count($rects) < 2) {
                continue;
            }

            $merged = $this->merge($rects);

            if (count($merged) === count($rects)) {
                continue;
            }

            $this->line(sprintf(
                '  #%d  %d → %d rects%s',
                $annotation->id,
                count($rects),
                count($merged),
                $annotation->excerpt ? '  "' . str($annotation->excerpt)->limit(40) . '"' : '',
            ));

            $removed += count($rects) - count($merged);
            $changed++;

            if ($dryRun) {
                continue;
            }

            $location['rects'] = $merged;

            $annotation->forceFill(['location' => $location])->saveQuietly();
        }

        $this->newLine();

        if ($changed === 0) {
            $this->info('Nothing to merge — no highlight has overlapping rectangles.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d %s %s, %d duplicate %s removed',
            $changed,
            str('highlight')->plural($changed),
            $dryRun ? 'would be fixed' : 'fixed',
            $removed,
            str('rectangle')->plural($removed),
        ));

        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Merges rectangles that sit on the same line of text.
     *
     * Mirrors the reader's own logic, so a repaired highlight is identical to
     * one captured today.
     *
     * @param array<int, array<string, float>> $rects
     * @return array<int, array<string, float>>
     */
    private function merge(array $rects): array
    {
        $merged = [];

        foreach ($rects as $rect) {
            $target = null;

            foreach ($merged as $index => $existing) {
                if ($this->sameLine($existing, $rect)) {
                    $target = $index;

                    break;
                }
            }

            if ($target === null) {
                $merged[] = $rect;

                continue;
            }

            $existing = $merged[$target];

            $left = min($existing['x'], $rect['x']);
            $top = min($existing['y'], $rect['y']);
            $right = max($existing['x'] + $existing['w'], $rect['x'] + $rect['w']);
            $bottom = max($existing['y'] + $existing['h'], $rect['y'] + $rect['h']);

            $merged[$target] = [
                'x' => round($left, 5),
                'y' => round($top, 5),
                'w' => round($right - $left, 5),
                'h' => round($bottom - $top, 5),
            ];
        }

        return array_values($merged);
    }

    /**
     * Vertical overlap decides it: rects on one line share most of their
     * height, while consecutive lines barely touch.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    private function sameLine(array $a, array $b): bool
    {
        $top = max($a['y'], $b['y']);
        $bottom = min($a['y'] + $a['h'], $b['y'] + $b['h']);
        $shared = $bottom - $top;

        if ($shared <= 0) {
            return false;
        }

        $shorter = min($a['h'], $b['h']);

        return $shorter > 0 && ($shared / $shorter) > 0.5;
    }
}

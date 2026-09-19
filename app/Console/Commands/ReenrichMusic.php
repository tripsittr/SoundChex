<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Services\Metadata\MetadataPipeline;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs the metadata pipeline again over music already in the library.
 *
 * Enrichment normally happens once, at import. But the whole music library was
 * imported with `match_confidence = none` — nothing ever matched a provider, so
 * every title is a filename (S-95). This re-runs the pipeline over existing
 * rows so the titles that MusicBrainz and iTunes can identify get identified,
 * without a re-import.
 *
 * `--sample=N` runs against N rows and writes nothing it cannot snapshot first
 * — the safe way to see match quality before committing to the whole library.
 * Every run snapshots to MetadataHistory, so a provider's verdict is
 * recoverable; a manual field (`source = 'manual'`) is never overwritten.
 */
class ReenrichMusic extends Command
{
    protected $signature = 'music:reenrich
        {--sample=0 : Only this many rows, newest first (0 = all)}
        {--only-unmatched : Skip rows that already matched a provider}
        {--queue : Dispatch enrichment to the background queue instead of running inline}
        {--stagger=1200 : Milliseconds between queued jobs, to respect MusicBrainz rate limits}';

    protected $description = 'Re-run metadata enrichment over existing music';

    public function handle(MetadataPipeline $pipeline): int
    {
        $query = MediaItem::query()
            ->where('type', MediaItemType::Music)
            ->orderByDesc('id');

        if ($this->option('only-unmatched')) {
            $query->where('match_confidence', 'none');
        }

        $sample = (int) $this->option('sample');

        if ($sample > 0) {
            $query->limit($sample);
        }

        if ($this->option('queue')) {
            return $this->dispatchQueued($query);
        }

        $items = $query->get();
        $total = $items->count();

        if ($total === 0) {
            $this->info('Nothing to enrich.');

            return self::SUCCESS;
        }

        $this->info("Enriching {$total} track".($total === 1 ? '' : 's').'…');

        $bar = $this->output->createProgressBar($total);
        $matched = 0;
        $failed = 0;

        foreach ($items as $item) {
            $before = $item->title;

            try {
                $pipeline->run($item);
                $item->refresh();

                if ($item->match_confidence !== 'none') {
                    $matched++;
                }

                // On a sample, show what changed so quality is visible before a
                // full run touches every title in the library.
                if ($sample > 0 && $item->title !== $before) {
                    $this->newLine();
                    $this->line("  <fg=gray>{$before}</> → <info>{$item->title}</info>"
                        .' <fg=gray>('.$item->match_confidence.')</>');
                }
            } catch (\Throwable $e) {
                $failed++;
                // Logged by the pipeline's own handling; counted here so the
                // summary is honest about what did not complete.
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("{$matched} matched a provider, ".($total - $matched - $failed).' unchanged'
            .($failed > 0 ? ", {$failed} failed" : '').'.');

        return self::SUCCESS;
    }

    /**
     * Dispatches enrichment to the queue, staggered.
     *
     * A full re-enrich is thousands of tracks, and MusicBrainz rate-limits
     * anonymous clients to about one request a second, so the jobs are spread out
     * with an increasing delay rather than dumped on the worker all at once — a
     * fast worker would otherwise burn straight through the rate limit and every
     * match would fail. The library stays usable while it runs in the background.
     */
    private function dispatchQueued(Builder $query): int
    {
        $stagger = max(0, (int) $this->option('stagger'));
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to enrich.');

            return self::SUCCESS;
        }

        $this->info("Queueing {$total} track".($total === 1 ? '' : 's')
            ." for background enrichment ({$stagger}ms apart)…");

        $bar = $this->output->createProgressBar($total);
        $offsetMs = 0;

        // Only the id is needed; chunk to keep memory flat over a large library.
        $query->select('id')->chunkById(500, function ($rows) use (&$offsetMs, $stagger, $bar): void {
            foreach ($rows as $row) {
                EnrichMediaItemJob::dispatch($row->id)
                    ->delay(now()->addMilliseconds($offsetMs));

                $offsetMs += $stagger;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $mins = (int) ceil(($offsetMs / 1000) / 60);
        $this->info("Queued. It will finish in roughly {$mins} minute".($mins === 1 ? '' : 's')
            .', spread out to respect provider rate limits. Watch the queue worker for progress.');

        return self::SUCCESS;
    }
}

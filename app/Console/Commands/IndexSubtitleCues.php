<?php

namespace App\Console\Commands;

use App\Models\Subtitle;
use Illuminate\Console\Command;

/**
 * Indexes dialogue from caption tracks so it can be searched.
 *
 * New tracks index themselves on import. This is for tracks that existed
 * before search did, and for repairing an index after a track is replaced.
 */
class IndexSubtitleCues extends Command
{
    protected $signature = 'subtitles:index
        {--force : Re-index tracks that already have cues}';

    protected $description = 'Index subtitle dialogue for search';

    public function handle(): int
    {
        $tracks = Subtitle::query()
            ->when(! $this->option('force'), fn ($query) => $query->doesntHave('cues'))
            ->with('mediaItem:id,title')
            ->get();

        if ($tracks->isEmpty()) {
            $this->info('Every track is already indexed.');

            return self::SUCCESS;
        }

        $this->info('Indexing ' . $tracks->count() . ' ' . str('track')->plural($tracks->count()) . '…');

        $indexed = 0;
        $missing = [];

        foreach ($tracks as $track) {
            $count = $track->indexCues();

            if ($count === 0) {
                // A row whose file has gone: worth naming rather than counting
                // silently, since it also means the track won't play.
                $missing[] = $track;

                continue;
            }

            $this->line(sprintf(
                '  %-38s %s cues',
                str($track->mediaItem?->title ?? 'Unknown')->limit(36),
                number_format($count),
            ));

            $indexed += $count;
        }

        $this->newLine();
        $this->info(number_format($indexed) . ' cues indexed');

        if ($missing !== []) {
            $this->warn(count($missing) . ' ' . str('track')->plural(count($missing)) . ' had no readable file:');

            foreach ($missing as $track) {
                $this->line('  ' . ($track->mediaItem?->title ?? 'Unknown') . ' — ' . $track->path);
            }
        }

        return self::SUCCESS;
    }
}

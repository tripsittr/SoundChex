<?php

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\EpisodeParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repairs items catalogued as films that are television.
 *
 * The scanner skips files it has already seen, so fixing the classification
 * fixes nothing that is already in the library — 48 Simpsons specials and two
 * `S06X01` files stay filed as films for ever otherwise.
 *
 * Reports by default and changes nothing. `--apply` is a separate, deliberate
 * act, because this rewrites rows in the only copy of a real catalogue.
 *
 * It touches `type`, `title` and `parent_id` only. No file is moved: what these
 * items are is wrong, where they sit on disk is not.
 */
class ReclassifyTelevision extends Command
{
    protected $signature = 'library:reclassify
                            {--apply : Write the corrections. Without this, nothing changes.}';

    protected $description = 'Find items catalogued as films that are really television';

    public function handle(EpisodeParser $episodes): int
    {
        $apply = (bool) $this->option('apply');

        $wrong = MediaItem::query()
            ->where('type', MediaItemType::Movie)
            ->whereNotNull('file_path')
            ->get()
            ->filter(fn (MediaItem $item) => $episodes->isTelevision($this->basename($item)));

        if ($wrong->isEmpty()) {
            $this->info('Nothing catalogued as a film looks like television.');

            return self::SUCCESS;
        }

        $this->line($wrong->count() . ' item(s) catalogued as films are television:');
        $this->newLine();

        $rows = [];

        foreach ($wrong as $item) {
            $marker = $episodes->marker($this->basename($item));

            $title = $marker !== null && ! $episodes->isMultiEpisode($this->basename($item))
                ? sprintf('%s S%02dE%02d', $marker['series'], $marker['season'], $marker['episode'])
                : $item->title;

            $rows[] = [$item->id, $item->title, $title, $marker['series'] ?? '?'];

            if (! $apply) {
                continue;
            }

            $item->forceFill([
                'type' => MediaItemType::Show,
                'title' => $title,
            ])->save();
        }

        $this->table(['id', 'was', 'becomes', 'series'], $rows);

        if (! $apply) {
            $this->newLine();
            $this->warn('Nothing was changed. Run again with --apply to write these.');

            return self::SUCCESS;
        }

        Log::warning('Items catalogued as films were reclassified as television', [
            'count' => $wrong->count(),
            'ids' => $wrong->pluck('id')->all(),
        ]);

        $this->newLine();
        $this->info($wrong->count() . ' item(s) reclassified.');

        // Left to the scan rather than done here: attaching an episode to its
        // series creates rows, and one deliberate act per run is enough.
        $this->line('Run library:scan afterwards to attach them to their series.');

        return self::SUCCESS;
    }

    /** The filename is the only signal; the stored path may be absolute. */
    private function basename(MediaItem $item): string
    {
        $path = str_replace('\\', '/', (string) $item->file_path);

        return pathinfo($path, PATHINFO_FILENAME);
    }
}

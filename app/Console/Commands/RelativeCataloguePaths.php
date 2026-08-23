<?php

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\TransferReceiver;
use Illuminate\Console\Command;

/**
 * Repairs a catalogue imported before the paths were rewritten on the way in.
 *
 * The import does this now. This exists for the library that was already
 * imported carrying another machine's absolute paths, where every item
 * resolves to nothing and no amount of copying files will help.
 *
 * Reports by default; `--apply` writes. It touches `file_path` only, and moves
 * no files — where the rows point is wrong, where the files sit is not.
 */
class RelativeCataloguePaths extends Command
{
    protected $signature = 'library:relative-paths
                            {--apply : Write the corrections. Without this, nothing changes.}';

    protected $description = 'Rewrite an imported catalogue\'s absolute paths for this machine';

    public function handle(TransferReceiver $receiver): int
    {
        $unreadable = MediaItem::query()
            ->whereNotNull('file_path')
            ->get(['id', 'type', 'title', 'file_path'])
            ->filter(fn (MediaItem $item) => $item->absoluteFilePath() === null);

        if ($unreadable->isEmpty()) {
            $this->info('Every catalogued file resolves to something on this machine.');

            return self::SUCCESS;
        }

        $this->line($unreadable->count() . ' catalogued item(s) resolve to nothing here. For example:');
        $this->newLine();

        $this->table(
            ['id', 'type', 'stored path'],
            $unreadable->take(5)->map(fn (MediaItem $i) => [
                $i->id,
                $i->type instanceof MediaItemType ? $i->type->value : (string) $i->type,
                mb_substr((string) $i->file_path, 0, 78),
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Nothing was changed. Run again with --apply to rewrite these.');

            return self::SUCCESS;
        }

        $changed = $receiver->makeCataloguePathsRelative();

        $this->newLine();
        $this->info($changed . ' path(s) rewritten.');
        $this->line('Anything still unreadable is a file that is genuinely not here yet.');

        return self::SUCCESS;
    }
}

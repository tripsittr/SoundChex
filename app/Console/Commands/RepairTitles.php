<?php

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\MetadataHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Restores titles that were left holding their own artist.
 *
 * The scanner catalogues with the filename, FileTagger promotes the real tag
 * title afterwards — but the guard compared against the current filename, and
 * the filer renames before enrichment runs. So "Gold" stayed "Gold - Imagine
 * Dragons" and the app printed the artist twice.
 *
 * The fix stops it happening again; this repairs what it already did. Read
 * from each file's own tags rather than by parsing the stored title, because
 * the tag is the answer and the title is the corruption.
 *
 * Dry run by default. It rewrites a field the user can edit by hand.
 */
class RepairTitles extends Command
{
    protected $signature = 'library:repair-titles
        {--apply : Write the corrected titles. Without this, nothing changes.}
        {--limit= : Stop after this many, for a look at the shape of it.}';

    protected $description = 'Restore music titles that carry their artist';

    public function handle(MetadataHistory $history): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        $items = MediaItem::query()
            ->where('type', MediaItemType::Music)
            ->whereNotNull('file_path')
            ->with('musicMetadata')
            ->get()
            ->filter(fn (MediaItem $item): bool => $this->suffixed($item));

        if ($limit !== null) {
            $items = $items->take($limit);
        }

        if ($items->isEmpty()) {
            $this->info('No titles carrying an artist.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $apply) {
            $this->comment("Dry run over {$items->count()} tracks. Nothing will be written — pass --apply to do it.");
            $this->newLine();
        }

        $fixed = 0;
        $skipped = 0;
        $shown = 0;

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        foreach ($items as $item) {
            $tagTitle = $this->tagTitle($item);

            // No tag to trust, so nothing to restore. Parsing the stored title
            // would be guessing, and a wrong guess here is a title nobody can
            // tell was invented.
            if ($tagTitle === null || $tagTitle === $item->title) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if ($shown < 12) {
                $bar->clear();
                $this->line(sprintf('    %-42s → %s',
                    mb_strimwidth($item->title, 0, 42, '…'),
                    $tagTitle,
                ));
                $bar->display();
                $shown++;
            }

            if ($apply) {
                // Recorded before the change, so a wrong correction is
                // recoverable rather than merely regrettable.
                $history->capture($item, 'title-repair', 'file-tags');

                $item->forceFill(['title' => $tagTitle])->saveQuietly();
            }

            $fixed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s %d title%s. %d skipped for want of a usable tag.',
            $apply ? 'Restored' : 'Would restore',
            $fixed,
            $fixed === 1 ? '' : 's',
            $skipped,
        ));

        return self::SUCCESS;
    }

    /** Whether the title ends with this track's own artist. */
    private function suffixed(MediaItem $item): bool
    {
        $artist = trim((string) $item->musicMetadata?->artist);

        if ($artist === '') {
            return false;
        }

        foreach ([' - ', ' — ', ' – '] as $separator) {
            if (str_ends_with($item->title, $separator . $artist)) {
                return true;
            }
        }

        return false;
    }

    /** The title the file itself claims, or null when it claims none. */
    private function tagTitle(MediaItem $item): ?string
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $result = Process::timeout(20)->run([
            'ffprobe', '-v', 'quiet',
            '-show_entries', 'format_tags=title',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            '--', $path,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $title = trim(strtok($result->output(), "\n") ?: '');

        return $title === '' ? null : $title;
    }
}

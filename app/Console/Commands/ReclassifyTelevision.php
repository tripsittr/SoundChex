<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\ContainerProbe;
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

            // Falls through rather than returning: audio filed as video is a
            // different wrong type with a different cause, and skipping it
            // because there happened to be no television was a way to report
            // "nothing to do" while three tracks sat in the film list.
            $this->demoteAudioFilms($apply);

            return self::SUCCESS;
        }

        $this->line($wrong->count() . ' item(s) catalogued as films are television:');
        $this->newLine();

        $rows = [];

        foreach ($wrong as $item) {
            $basename = $this->basename($item);
            $marker = $episodes->marker($basename);

            // A multi-episode file ("S01E01-E02") keeps its existing title: the
            // marker names only the first episode, so renaming to it would claim
            // the file is just that one.
            $title = $marker !== null && ! $episodes->isMultiEpisode($basename)
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

        $this->demoteAudioFilms($apply);

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

    /**
     * Films that hold no video, which makes them music.
     *
     * Only where the file is here and ffprobe gives a definite answer. A row
     * whose file has not arrived, or whose probe fails, is left exactly as it
     * is and counted — "I could not tell" reported as "not a film" would empty
     * the film list of anything this machine has not got yet, which for a
     * half-finished transfer is most of it.
     */
    private function demoteAudioFilms(bool $apply): void
    {
        $probe = app(ContainerProbe::class);

        $films = MediaItem::query()
            ->where('type', MediaItemType::Movie)
            ->whereNotNull('file_path')
            ->get();

        $audio = [];
        $unknown = 0;

        foreach ($films as $item) {
            $path = $item->absoluteFilePath();

            if ($path === null || ! $probe->isAmbiguous($path)) {
                if ($path === null) {
                    $unknown++;
                }

                continue;
            }

            $hasVideo = $probe->hasVideo($path);

            if ($hasVideo === null) {
                $unknown++;

                continue;
            }

            if ($hasVideo === false) {
                $audio[] = $item;
            }
        }

        if ($unknown > 0) {
            $this->newLine();
            $this->warn($unknown . ' film(s) could not be checked — the file is not on this machine, '
                . 'or ffprobe could not read it. Left alone.');
        }

        if ($audio === []) {
            $this->info('No film that could be checked turned out to be audio.');

            return;
        }

        $this->newLine();
        $this->line(count($audio) . ' film(s) hold no video and are music:');

        $this->table(
            ['id', 'title', 'file'],
            array_map(fn (MediaItem $i) => [
                $i->id,
                mb_substr((string) $i->title, 0, 40),
                mb_substr(basename((string) $i->file_path), 0, 45),
            ], $audio),
        );

        if (! $apply) {
            $this->warn('Nothing was changed. Run again with --apply to rewrite these.');

            return;
        }

        foreach ($audio as $item) {
            $item->forceFill(['type' => MediaItemType::Music])->save();
        }

        Log::warning('Films holding no video were recatalogued as music', [
            'count' => count($audio),
            'ids' => array_map(fn (MediaItem $i) => $i->id, $audio),
        ]);

        $this->info(count($audio) . ' recatalogued as music.');
    }

    /** The filename is the only signal; the stored path may be absolute. */
    private function basename(MediaItem $item): string
    {
        $path = str_replace('\\', '/', (string) $item->file_path);

        return pathinfo($path, PATHINFO_FILENAME);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Sources\Show\Tmdb;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetch the artwork that shows and episodes were never given.
 *
 * `Tmdb` requested the episode record — which carries `still_path` — and threw
 * the image away, and wrote no series poster either. `Tvdb` was the only
 * source that wrote episode stills and it needs its own API key, so a server
 * holding a TMDB key and no TVDB key had **nothing** fetching show artwork at
 * all. Every episode list was a column of grey glyphs.
 *
 * Fixing the source only helps items enriched afterwards, and enrichment does
 * not re-run on its own: an already-enriched episode keeps its empty cover
 * for ever. This is what goes back for them.
 *
 * Series first, deliberately. An episode with no still of its own falls back
 * to the series poster, so fetching the posters first means one pass can leave
 * every row showing *something* even where TMDB has no still.
 */
class BackfillShowArtwork extends Command
{
    protected $signature = 'library:show-artwork
        {--limit=0 : Stop after this many items (0 = all)}
        {--dry-run : Report what would be fetched without writing}';

    protected $description = 'Fetch posters and episode stills for shows that have none';

    public function handle(Tmdb $tmdb, SettingsService $settings): int
    {
        // Checked up front rather than per item: without a key every single
        // fetch would decline silently and the run would report "filled 0 of
        // 400" with no reason given.
        if (blank($settings->get('tmdb_api_key'))) {
            $this->error('No TMDB API key, so there is nothing to fetch with. Set one in Integrations.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        // Series before episodes: the episodes fall back to the poster.
        $series = $this->missingArtwork()->whereNull('parent_id')->get();
        $episodes = $this->missingArtwork()->whereNotNull('parent_id')->get();

        $queue = $series->concat($episodes);

        if ($limit > 0) {
            $queue = $queue->take($limit);
        }

        if ($queue->isEmpty()) {
            $this->info('Every show and episode already has artwork.');

            return self::SUCCESS;
        }

        $this->info("{$series->count()} series and {$episodes->count()} episode(s) have no artwork.");

        if ($dryRun) {
            $this->comment('Dry run: nothing was fetched.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($queue->count());
        $bar->start();

        $filled = 0;

        foreach ($queue as $item) {
            try {
                $tmdb->enrich($item);

                if (filled($item->fresh()?->cover_image_url)) {
                    $filled++;
                }
            } catch (Throwable $e) {
                // One item TMDB cannot answer for must not stop the run: a
                // library has plenty of oddities, and the next episode is
                // still worth fetching.
                $this->newLine();
                $this->warn("#{$item->id} {$item->title}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Filled {$filled} of {$queue->count()}.");

        if ($filled < $queue->count()) {
            // Said plainly rather than left as a silent shortfall: TMDB
            // genuinely has no still for plenty of episodes, and an episode
            // with none still shows its series poster.
            $this->comment('The rest had nothing on TMDB. Episodes fall back to the series poster.');
        }

        return self::SUCCESS;
    }

    /** Shows and episodes with no cover of their own. */
    private function missingArtwork()
    {
        return MediaItem::withoutGlobalScopes()
            ->where('type', MediaItemType::Show)
            ->where(fn ($q) => $q->whereNull('cover_image_url')->orWhere('cover_image_url', ''))
            ->orderBy('id');
    }
}

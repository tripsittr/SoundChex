<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\MusicCredits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes credits for music that has none.
 *
 * Parses the free-text artist string rather than asking MusicBrainz: this runs
 * over the whole library, and MusicBrainz allows one request a second. The
 * network path belongs in enrichment, one track at a time, where a slow lookup
 * costs nothing.
 *
 * Dry run by default. It writes to a quarter of the library.
 */
class DeriveMusicCredits extends Command
{
    protected $signature = 'music:derive-credits
        {--apply : Write the credits. Without this, nothing is changed.}
        {--limit= : Only process this many tracks, for a look at the shape of it.}';

    protected $description = 'Derive artist credits from the stored artist string';

    public function handle(MusicCredits $credits): int
    {
        $query = MediaItem::unresolved()
            ->where('type', MediaItemType::Music)
            ->whereHas('musicMetadata', fn ($q) => $q
                ->whereNotNull('artist')
                ->where('artist', '!=', ''))
            ->with('musicMetadata');

        // chunkById() applies its own limit per chunk and ignores one set on
        // the query, so a --limit here has to be counted rather than pushed
        // into SQL. Silently walking the whole library when asked for 300 is
        // worse than not offering the option.
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        $total = $limit === null ? $query->count() : min($limit, $query->count());

        if ($total === 0) {
            $this->info('No music with an artist to read.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $this->newLine();

        if (! $apply) {
            $this->comment("Dry run over {$total} tracks. Nothing will be written — pass --apply to do it.");
            $this->newLine();
        }

        $collaborations = 0;
        $changed = 0;
        $samples = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $seen = 0;

        $query->chunkById(200, function ($items) use ($credits, $apply, $limit, &$seen, &$collaborations, &$changed, &$samples, $bar) {
            foreach ($items as $item) {
                if ($limit !== null && $seen >= $limit) {
                    return false;
                }

                $seen++;

                $stored = $item->musicMetadata?->artist;
                $primary = $credits->primaryFor($stored);

                if ($primary === null) {
                    $bar->advance();

                    continue;
                }

                $names = $credits->fromCreditString($item, $stored, dryRun: ! $apply);

                if (count($names) > 1) {
                    $collaborations++;

                    if (count($samples) < 12) {
                        $samples[] = [$stored, implode(' + ', $names)];
                    }
                }

                // The denormalised column browsing groups on. The credits are
                // the truth; this is the index into them.
                if ($apply && $item->musicMetadata?->primary_artist !== $primary) {
                    DB::table('music_metadata')
                        ->where('media_item_id', $item->id)
                        ->update(['primary_artist' => $primary]);
                }

                if ($item->musicMetadata?->primary_artist !== $primary) {
                    $changed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($samples !== []) {
            $this->line('  Collaborations, as they would be split:');
            $this->newLine();

            foreach ($samples as [$before, $after]) {
                $this->line(sprintf('    %-44s → %s', mb_strimwidth($before, 0, 44, '…'), $after));
            }

            $this->newLine();
        }

        $this->info(sprintf(
            '%s %d track%s, %d of them collaborations.',
            $apply ? 'Wrote credits for' : 'Would write credits for',
            $changed,
            $changed === 1 ? '' : 's',
            $collaborations,
        ));

        return self::SUCCESS;
    }
}

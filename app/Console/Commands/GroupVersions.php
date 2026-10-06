<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\Versions\EditionKey;
use App\Services\Versions\VersionGrouper;
use App\Services\Versions\WorkKey;
use Illuminate\Console\Command;

/**
 * Gives existing rows their work and edition keys (#489).
 *
 * The pipeline classifies everything from now on, but a library catalogued
 * before the version model has no keys at all -- so nothing groups, every
 * version still looks like a duplicate, and the review queue keeps asking
 * about pairs that both belong.
 *
 * Local work: derived entirely from identifiers already stored, so no network
 * and no rate limit. A library of 8,000 takes seconds.
 */
class GroupVersions extends Command
{
    protected $signature = 'library:group-versions
        {--limit=0 : Stop after this many items (0 = all)}
        {--type= : Only this media type}
        {--dry-run : Report what would be set without writing}';

    protected $description = 'Work out which library items are versions of the same work';

    public function handle(VersionGrouper $grouper): int
    {
        $query = MediaItem::withoutGlobalScopes()
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'probe'])
            ->orderBy('id');

        if ($type = $this->option('type')) {
            $query->where('type', $type);
        }

        $limit = max(0, (int) $this->option('limit'));
        $total = $query->count();
        $take = $limit > 0 ? min($limit, $total) : $total;

        if ($take === 0) {
            $this->info('Nothing to group.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? 'Would classify ' : 'Classifying ').$take.' item(s).');

        $keyed = $unkeyed = $editions = 0;

        $bar = $this->output->createProgressBar($take);
        $bar->start();

        foreach ($query->limit($take)->get() as $item) {
            if ($dryRun) {
                // Derived without writing, so a run can be inspected before it
                // touches 8,000 rows.
                $key = app(WorkKey::class)->for($item);
                $edition = app(EditionKey::class)->for($item);
            } else {
                $grouper->classify($item);
                $fresh = $item->fresh();
                $key = $fresh->work_key;
                $edition = $fresh->edition;
            }

            $key === null ? $unkeyed++ : $keyed++;

            if ($edition !== null) {
                $editions++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("{$keyed} identified, {$unkeyed} with no identifier, {$editions} carrying an edition.");

        if ($dryRun) {
            $this->comment('Nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        // Electing primaries needs every key written first, or a work whose
        // second copy has not been classified yet would elect the wrong one.
        $this->electPrimaries($grouper);

        return self::SUCCESS;
    }

    /**
     * Picks the primary for each group that has more than one member.
     *
     * A second pass, because electing from a half-classified group gives the
     * wrong answer -- and a group of one is already correct by default.
     */
    private function electPrimaries(VersionGrouper $grouper): void
    {
        $groups = MediaItem::withoutGlobalScopes()
            ->whereNotNull('work_key')
            ->selectRaw('work_key, COUNT(*) as copies')
            ->groupBy('work_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('copies', 'work_key');

        if ($groups->isEmpty()) {
            $this->info('No work has more than one copy, so every item is its own primary.');

            return;
        }

        $this->info($groups->count().' work(s) have more than one copy. Electing a primary for each.');

        $bar = $this->output->createProgressBar($groups->count());
        $bar->start();

        foreach ($groups->keys() as $key) {
            $first = MediaItem::withoutGlobalScopes()
                ->where('work_key', $key)
                ->with(['probe'])
                ->first();

            if ($first !== null) {
                $grouper->electPrimary($first);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Done. Versions of one work now group, and each has a primary for playback.');
    }
}

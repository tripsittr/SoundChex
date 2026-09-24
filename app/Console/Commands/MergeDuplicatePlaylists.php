<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\Collection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collapses playlists a user has more than one of under the same name (S-325).
 *
 * Importing the same playlist twice used to make a second playlist silently,
 * with nothing to say which was which. The import now asks first, but the
 * duplicates it already made are still there.
 *
 * The oldest playlist of each name wins and the rest are folded into it: its
 * tracks keep their positions, anything only the others held is appended, and
 * the emptied duplicates are deleted. Names are compared as a person reads
 * them, so "Karaoke" and "karaoke " are the same playlist.
 *
 * Shows what it would do first; `--force` applies it.
 */
class MergeDuplicatePlaylists extends Command
{
    protected $signature = 'playlists:merge-duplicates
        {--force : Apply the merge (otherwise a dry run)}';

    protected $description = 'Fold playlists sharing a name into the oldest one, per user';

    public function handle(): int
    {
        $groups = $this->duplicateGroups();

        if ($groups === []) {
            $this->info('No user has two playlists by the same name. Nothing to do.');

            return self::SUCCESS;
        }

        $merges = 0;

        foreach ($groups as $group) {
            /** @var Collection $keep */
            $keep = array_shift($group);

            $this->line("  <info>{$keep->name}</info> (#{$keep->id}, user {$keep->user_id}) keeps ".$keep->mediaItems()->count().' track(s)');

            foreach ($group as $drop) {
                $incoming = $drop->mediaItems()->pluck('media_items.id');
                $already = $keep->mediaItems()->pluck('media_items.id');
                $new = $incoming->diff($already);

                $this->line("      <comment>#{$drop->id}</comment> ({$incoming->count()} track(s)) → {$new->count()} new, ".($incoming->count() - $new->count()).' already there');

                $merges++;

                if (! $this->option('force')) {
                    continue;
                }

                $this->merge($keep, $drop, $new);
            }
        }

        $this->newLine();

        if (! $this->option('force')) {
            $this->warn("Dry run: {$merges} playlist(s) would be folded in and deleted.");
            $this->line('Run again with --force to apply.');

            return self::SUCCESS;
        }

        $this->info("Folded in and deleted {$merges} duplicate playlist(s).");

        return self::SUCCESS;
    }

    /**
     * Every set of two or more playlists one user has under the same name,
     * oldest first so the first of each set is the one to keep.
     *
     * @return array<int, array<int, Collection>>
     */
    private function duplicateGroups(): array
    {
        $keys = Collection::query()
            ->selectRaw('user_id, LOWER(TRIM(name)) as name_key')
            ->groupBy('user_id', 'name_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $groups = [];

        foreach ($keys as $key) {
            $groups[] = Collection::query()
                ->where('user_id', $key->user_id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$key->name_key])
                ->orderBy('id')
                ->get()
                ->all();
        }

        return $groups;
    }

    /**
     * Appends the tracks only the duplicate held, then deletes it.
     *
     * One transaction per duplicate: a merge that fails half way would
     * otherwise leave tracks copied and the duplicate still present, and the
     * next run would copy them again.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $new
     */
    private function merge(Collection $keep, Collection $drop, \Illuminate\Support\Collection $new): void
    {
        DB::transaction(function () use ($keep, $drop, $new): void {
            $sortOrder = (int) DB::table('collection_media_item')
                ->where('collection_id', $keep->id)
                ->max('sort_order') + 1;

            foreach ($new as $mediaItemId) {
                $keep->mediaItems()->syncWithoutDetaching([
                    $mediaItemId => ['sort_order' => $sortOrder++],
                ]);
            }

            $drop->mediaItems()->detach();
            $drop->delete();
        });
    }
}

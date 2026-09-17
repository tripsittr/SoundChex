<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\Person;
use App\Services\ArtistProfiles;
use App\Services\MusicCredits;
use Illuminate\Console\Command;

/**
 * Looks up who the artists in the library are.
 *
 * Paced deliberately: MusicBrainz asks for one request a second and enforces
 * it, and each artist costs two or three. 1,281 artists is therefore the best
 * part of an hour, which is why this is resumable — it skips anything looked up
 * recently, so stopping and starting again continues rather than restarts.
 */
class FetchArtistProfiles extends Command
{
    protected $signature = 'library:artist-profiles
        {--limit= : Stop after this many.}
        {--force : Look up even artists fetched recently.}
        {--artist= : One artist by name, for a look at what comes back.}';

    protected $description = 'Fetch artist biographies, images and details';

    public function handle(ArtistProfiles $profiles): int
    {
        $query = Person::query()
            ->when($this->option('artist'), fn ($q, $name) => $q->where('name', $name))
            // Only people credited on music. The table also holds book authors
            // and film directors, and MusicBrainz has nothing to say about them.
            ->when(! $this->option('artist'), fn ($q) => $q->whereIn(
                'id',
                \DB::table('media_item_person')
                    ->whereIn('role', [MusicCredits::PRIMARY, MusicCredits::FEATURED])
                    ->distinct()
                    ->pluck('person_id'),
            ))
            // Most-credited first, so a run that is stopped early has done the
            // artists whose pages are most likely to be visited.
            ->orderByDesc(
                \DB::table('media_item_person')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('person_id', 'people.id'),
            );

        $people = $query->get()
            ->filter(fn (Person $p) => $this->option('force') || $profiles->isStale($p));

        if ($limit = $this->option('limit')) {
            $people = $people->take((int) $limit);
        }

        if ($people->isEmpty()) {
            $this->info('Every artist has been looked up recently.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment(sprintf(
            '%d artist%s to look up, about %d minutes at MusicBrainz\'s rate limit.',
            $people->count(),
            $people->count() === 1 ? '' : 's',
            (int) ceil($people->count() * 2.5 / 60),
        ));
        $this->newLine();

        $found = 0;
        $missed = 0;

        $bar = $this->output->createProgressBar($people->count());
        $bar->start();

        foreach ($people as $person) {
            try {
                $profiles->fetch($person, force: (bool) $this->option('force'))
                    ? $found++
                    : $missed++;
            } catch (\Throwable $e) {
                $missed++;

                $bar->clear();
                $this->line("  <fg=red>failed</> {$person->name}: " . $e->getMessage());
                $bar->display();
            }

            $bar->advance();

            // One request a second is the published limit and it is enforced by
            // blocking, not by an error — going faster gets slower.
            usleep(1_100_000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%d profile%s found, %d without a confident match.',
            $found,
            $found === 1 ? '' : 's',
            $missed,
        ));

        return self::SUCCESS;
    }
}

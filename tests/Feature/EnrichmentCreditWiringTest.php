<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\MusicCredits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The enrichment job's credit step prefers MusicBrainz's identified credits and
 * does not overwrite them by re-parsing the joined artist string (S-38).
 *
 * fromMusicBrainz() was written and tested but never called in production, so a
 * matched recording's stable artist ids were thrown away and the string was
 * parsed instead. These cover the wiring: once credits carry MBIDs, the string
 * fallback leaves them alone.
 */
class EnrichmentCreditWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_identified_credits_are_not_clobbered_by_the_string_parser(): void
    {
        $item = $this->track('Ev0lution');

        // Stand in for what MusicBrainz's writeCredits() does: credits with ids.
        app(MusicCredits::class)->fromMusicBrainz($item, [
            ['artist' => ['name' => 'Ev0lution', 'id' => 'mbid-ev0']],
        ]);

        // Run the job's credit step; the artist string ("Ev0lution") would parse
        // to the same name with no id if it ran, dropping the mbid.
        $this->invokeWriteCredits($item);

        $this->assertSame(
            'mbid-ev0',
            $item->people()->wherePivot('role', MusicCredits::PRIMARY)->value('musicbrainz_artist_id'),
        );
    }

    public function test_the_string_parser_still_runs_when_there_are_no_identified_credits(): void
    {
        // A track MusicBrainz did not match: the artist string is all there is,
        // and the credit must still be written from it.
        $item = $this->track('Some Artist, A Guest');

        $this->invokeWriteCredits($item);

        $this->assertSame(2, $item->people()->count());
        $this->assertSame(
            'Some Artist',
            $item->people()->wherePivot('role', MusicCredits::PRIMARY)->value('name'),
        );
    }

    private function track(string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Track',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => $artist]);

        return $item->fresh(['musicMetadata']);
    }

    /** Reach the job's private credit step without running the whole pipeline. */
    private function invokeWriteCredits(MediaItem $item): void
    {
        $job = new EnrichMediaItemJob($item->id);

        $method = new \ReflectionMethod($job, 'writeCredits');
        $method->invoke($job, $item);
    }
}

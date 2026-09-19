<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Music\ItunesSearch;
use App\Services\Metadata\Sources\Music\MusicBrainz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A matched music track must record that it matched (S-273).
 *
 * The bug: music sources fetched data but never set match_confidence, so the
 * whole library read as unmatched — enrichment looked like it did nothing.
 */
class MusicMatchConfidenceTest extends TestCase
{
    use RefreshDatabase;

    private function track(array $meta): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'Stressed Out',
            'owned' => true,
            'match_confidence' => 'none',
        ]);
        $item->musicMetadata()->create($meta);

        return $item->fresh();
    }

    /** A minimal MusicBrainz recording search/lookup response. */
    private function fakeMusicBrainz(): void
    {
        Http::fake([
            'musicbrainz.org/*' => Http::response([
                'recordings' => [[
                    'id' => 'badf0c46-e52b-4534-b59b-0aea31d32d61',
                    'title' => 'Stressed Out',
                    'first-release-date' => '2015-04-28',
                    'releases' => [[
                        'title' => 'Blurryface',
                        'date' => '2015-05-17',
                        'release-group' => ['primary-type' => 'Album'],
                    ]],
                ]],
                // For the by-id lookup shape too.
                'id' => 'badf0c46-e52b-4534-b59b-0aea31d32d61',
                'title' => 'Stressed Out',
            ]),
        ]);
    }

    public function test_musicbrainz_records_exact_when_matched_by_id(): void
    {
        $this->fakeMusicBrainz();
        $item = $this->track([
            'artist' => 'Twenty One Pilots',
            'musicbrainz_recording_id' => 'badf0c46-e52b-4534-b59b-0aea31d32d61',
        ]);

        app(MusicBrainz::class)->enrich($item);

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
        $this->assertSame('MusicBrainz', $item->fresh()->matched_by);
    }

    public function test_musicbrainz_records_fuzzy_when_matched_by_search(): void
    {
        $this->fakeMusicBrainz();
        // No id/ISRC — reached by an artist+title search, so Fuzzy.
        $item = $this->track(['artist' => 'Twenty One Pilots']);

        app(MusicBrainz::class)->enrich($item);

        $this->assertSame(MatchConfidence::Fuzzy, $item->fresh()->match_confidence);
    }

    public function test_itunes_records_fuzzy_but_never_downgrades_exact(): void
    {
        Http::fake(['itunes.apple.com/*' => Http::response(['results' => [
            ['artistName' => 'Twenty One Pilots', 'collectionName' => 'Blurryface',
                'artworkUrl100' => 'https://example.test/bf-100x100.jpg'],
        ]])]);

        // Already Exact from a stronger source — iTunes must not downgrade it.
        $exact = $this->track(['artist' => 'Twenty One Pilots', 'album' => 'Blurryface']);
        $exact->forceFill(['match_confidence' => MatchConfidence::Exact, 'matched_by' => 'MusicBrainz'])->saveQuietly();

        app(ItunesSearch::class)->enrich($exact->fresh());

        $this->assertSame(MatchConfidence::Exact, $exact->fresh()->match_confidence);
        $this->assertSame('MusicBrainz', $exact->fresh()->matched_by);

        // From None, iTunes lifts it to Fuzzy.
        $none = $this->track(['artist' => 'Twenty One Pilots', 'album' => 'Blurryface']);
        app(ItunesSearch::class)->enrich($none);

        $this->assertSame(MatchConfidence::Fuzzy, $none->fresh()->match_confidence);
        $this->assertSame('iTunes Search', $none->fresh()->matched_by);
    }
}

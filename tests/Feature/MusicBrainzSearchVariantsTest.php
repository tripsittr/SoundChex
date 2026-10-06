<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\MusicMetadata;
use App\Models\User;
use App\Services\Metadata\Sources\Music\MusicBrainz;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A findable recording is found, whatever the tagger wrote (part of #489).
 *
 * MusicBrainz matches the query literally, so an edition suffix or a second
 * credited artist makes a recording it plainly knows about unfindable.
 * Measured against the live API before the fix was written:
 *
 *   "The Modern Age - Rough Trade Version" / The Strokes -> nothing
 *   "The Modern Age"                       / The Strokes -> FOUND
 *   "In Spite of Ourselves" / "Viagra Boys, Amy Taylor"  -> nothing
 *   "In Spite of Ourselves" / "Viagra Boys"              -> FOUND
 *
 * 360 of this library's 642 unmatched rows carry a comma-joined artist and 155
 * an edition suffix, so this is most of the remaining problem — not the 8,440
 * the plan predicted (#489).
 *
 * The edition is dropped **from the query only**. The stored title keeps it:
 * "Psycho Killer - Acoustic" and "1979 - Remastered 2012" are distinct
 * recordings, and collapsing them is the loss #489 forbids.
 */
class MusicBrainzSearchVariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        app(SettingsService::class)->set('musicbrainz_enabled', true);
    }

    /* -------------------------------------------------------- variants -- */

    public function test_the_file_as_written_is_tried_first(): void
    {
        // A precise match must never be passed over for a looser one.
        $variants = $this->variantsFor('A Song', 'An Artist');

        $this->assertSame(['A Song', 'An Artist'], $variants[0]);
    }

    public function test_a_comma_joined_artist_adds_a_primary_only_variant(): void
    {
        $variants = $this->variantsFor('In Spite of Ourselves', 'Viagra Boys, Amy Taylor');

        $this->assertContains(['In Spite of Ourselves', 'Viagra Boys'], $variants);
    }

    public function test_an_edition_suffix_adds_a_bare_title_variant(): void
    {
        $variants = $this->variantsFor('The Modern Age - Rough Trade Version', 'The Strokes');

        $this->assertContains(['The Modern Age', 'The Strokes'], $variants);
    }

    public function test_a_clean_title_and_artist_cost_exactly_one_search(): void
    {
        // At MusicBrainz's 1 req/s, a needless extra variant on every
        // well-tagged file would double a library-wide run.
        $this->assertCount(1, $this->variantsFor('A Song', 'An Artist'));
    }

    public function test_nothing_is_queried_twice(): void
    {
        // Both problems at once still produces four distinct pairs, not a
        // repeat -- deduplication is what keeps the cost proportional.
        $variants = $this->variantsFor('The Modern Age - Rough Trade Version', 'The Strokes, Someone');

        $this->assertCount(4, $variants);
        $this->assertSame(count($variants), count(array_unique(array_map('serialize', $variants))));
    }

    /* ------------------------------------------------------- behaviour -- */

    public function test_a_recording_found_only_by_the_bare_title_is_matched(): void
    {
        // The whole point: the first query finds nothing, a later variant
        // finds the recording, and the item stops reading as unmatchable.
        Http::fake([
            // Anything carrying the edition wording finds nothing...
            'musicbrainz.org/*Rough*' => Http::response(['recordings' => []]),
            // ...and the bare title resolves.
            'musicbrainz.org/*' => Http::response(['recordings' => [$this->recording()]]),
        ]);

        $item = $this->track('The Modern Age - Rough Trade Version', 'The Strokes');

        app(MusicBrainz::class)->enrich($item);

        $this->assertSame(
            MatchConfidence::Fuzzy,
            $item->fresh()->match_confidence,
            'A text match is Fuzzy, not Exact -- it was not reached by an identifier (#489).',
        );
    }

    public function test_the_stored_title_keeps_its_edition(): void
    {
        // Dropping the edition from the QUERY must not drop it from the title.
        // "Psycho Killer - Acoustic" is a distinct recording (#489).
        Http::fake(['musicbrainz.org/*' => Http::response(['recordings' => [$this->recording()]])]);

        $item = $this->track('Psycho Killer - Acoustic', 'Talking Heads');

        app(MusicBrainz::class)->enrich($item);

        $this->assertSame('Psycho Killer - Acoustic', $item->fresh()->title);
        $this->assertSame('Acoustic', $item->fresh()->editionSuffix());
    }

    public function test_a_genuinely_unknown_recording_still_matches_nothing(): void
    {
        // Widening the search must not invent matches. A row nothing knows
        // about stays unmatched rather than being filed on a guess.
        Http::fake(['musicbrainz.org/*' => Http::response(['recordings' => []])]);

        $item = $this->track('Inbox Flow Probe', 'Verification Suite');

        app(MusicBrainz::class)->enrich($item);

        $this->assertSame(MatchConfidence::None, $item->fresh()->match_confidence);
    }

    /* --------------------------------------------------------- helpers -- */

    /** @return array<int, array{0: string, 1: string}> */
    private function variantsFor(string $title, string $artist): array
    {
        $item = new MediaItem(['title' => $title]);
        $item->type = MediaItemType::Music;

        $meta = new MusicMetadata(['artist' => $artist]);

        $method = new ReflectionMethod(MusicBrainz::class, 'searchVariants');

        // Lucene escaping happens inside, so unescape for a readable
        // assertion -- the test is about which pairs are tried, not quoting.
        return array_map(
            fn (array $pair): array => array_map(fn (string $v): string => str_replace('\\', '', $v), $pair),
            $method->invoke(app(MusicBrainz::class), $item, $meta),
        );
    }

    /** @return array<string, mixed> */
    private function recording(): array
    {
        return [
            'id' => 'badf0c46-e52b-4534-b59b-0aea31d32d61',
            'title' => 'The Modern Age',
            'first-release-date' => '2001-01-29',
            'releases' => [[
                'title' => 'Is This It',
                'date' => '2001-07-30',
                'release-group' => ['primary-type' => 'Album'],
            ]],
        ];
    }

    private function track(string $title, string $artist): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => $artist]);
        $item->forceFill(['match_confidence' => MatchConfidence::None])->saveQuietly();

        return $item->fresh();
    }

    public function test_a_featured_artist_is_dropped_from_the_query(): void
    {
        // Measured against the live API on a real unidentified file:
        // "NYE [Feat Suki Waterhouse]" by "Local Natives, Suki Waterhouse"
        // matches **nothing**, while "NYE" by "Local Natives" scores 100.
        // MusicBrainz keeps the guest in the artist credit, not in the
        // recording title, so a filename's bracketed feature defeats the
        // search outright.
        $variants = $this->variantsFor('NYE [Feat Suki Waterhouse]', 'Local Natives, Suki Waterhouse');

        $titles = array_map(fn (array $v): string => $v[0], $variants);

        $this->assertContains('NYE', $titles, 'The feature was never dropped, so this file stays unidentifiable.');
    }

    public function test_the_bracketless_form_is_dropped_too(): void
    {
        // Taggers write both shapes.
        $titles = array_map(fn (array $v): string => $v[0], $this->variantsFor('Song ft. Someone', 'A Band'));

        $this->assertContains('Song', $titles);
    }

    public function test_a_title_that_merely_starts_with_feat_is_left_alone(): void
    {
        // "Features" is a word. The marker has to be preceded by whitespace or
        // a bracket, or a real title gets truncated into a different song.
        $titles = array_map(fn (array $v): string => $v[0], $this->variantsFor('Features', 'A Band'));

        foreach ($titles as $title) {
            $this->assertSame('Features', $title);
        }
    }

    public function test_an_edition_suffix_is_still_not_confused_with_a_feature(): void
    {
        // "Psycho Killer - Acoustic" and the studio cut are different
        // recordings; collapsing them is the loss the version model forbids.
        $titles = array_map(fn (array $v): string => $v[0], $this->variantsFor('Psycho Killer - Acoustic', 'Talking Heads'));

        $this->assertNotContains('Psycho', $titles);
    }
}

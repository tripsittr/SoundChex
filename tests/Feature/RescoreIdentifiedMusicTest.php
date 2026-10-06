<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Re-scoring music that already carries an identifier (#489).
 *
 * The plan said the library read `none` because the MusicBrainz id was never
 * read. Measuring said otherwise: 6,050 of 8,314 rows carry one, and the real
 * problem was 3,429 rows that have an id and score `Fuzzy` anyway because they
 * predate the current pipeline.
 *
 * The rule these defend: **presence of an id is not a match.** Setting `Exact`
 * from the column alone is the mistake #489 fixed, so an id that no longer
 * resolves must stay a guess.
 */
class RescoreIdentifiedMusicTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        app(SettingsService::class)->set('musicbrainz_enabled', true);
    }

    public function test_a_resolving_identifier_is_promoted_to_exact(): void
    {
        $this->fakeMusicBrainzHit();

        $item = $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d61');

        $this->artisan('music:rescore', ['--sleep' => 0])
            ->expectsOutputToContain('Promoted 1 to exact')
            ->assertSuccessful();

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
    }

    public function test_an_identifier_that_no_longer_resolves_stays_a_guess(): void
    {
        // The rule #489 established: an id being *present* is not a match. A
        // recording MusicBrainz has merged away or retired legitimately stays
        // Fuzzy, and a command that promoted it anyway would be reintroducing
        // the bug this library was just cleaned of.
        Http::fake(['musicbrainz.org/*' => Http::response([], 404)]);

        $item = $this->track(MatchConfidence::Fuzzy, 'dead0000-0000-0000-0000-000000000000');

        $this->artisan('music:rescore', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame(
            MatchConfidence::Fuzzy,
            $item->fresh()->match_confidence,
            'An id that does not resolve must not be promoted.',
        );
    }

    public function test_rows_without_an_identifier_are_left_alone(): void
    {
        // Those are the genuinely unmatched ones -- 642 on the real library --
        // and they need the scorer and fingerprinting, not this.
        Http::fake();

        $item = $this->track(MatchConfidence::None, null);

        $this->artisan('music:rescore', ['--sleep' => 0])
            ->expectsOutputToContain('Nothing to re-score')
            ->assertSuccessful();

        $this->assertSame(MatchConfidence::None, $item->fresh()->match_confidence);
        Http::assertNothingSent();
    }

    public function test_a_row_that_already_scores_exactly_is_not_looked_up_again(): void
    {
        // Re-running must be cheap. At MusicBrainz's 1/s, needlessly
        // re-checking 2,600 settled rows would add three quarters of an hour.
        Http::fake();

        $this->track(MatchConfidence::Exact, 'badf0c46-e52b-4534-b59b-0aea31d32d61');

        $this->artisan('music:rescore', ['--sleep' => 0])
            ->expectsOutputToContain('Nothing to re-score')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->fakeMusicBrainzHit();

        $item = $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d61');

        $this->artisan('music:rescore', ['--dry-run' => true])
            ->expectsOutputToContain('Would re-score')
            ->assertSuccessful();

        $this->assertSame(MatchConfidence::Fuzzy, $item->fresh()->match_confidence);
        Http::assertNothingSent();
    }

    public function test_the_limit_is_respected(): void
    {
        $this->fakeMusicBrainzHit();

        $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d61');
        $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d62');
        $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d63');

        $this->artisan('music:rescore', ['--limit' => 1, '--sleep' => 0])
            ->expectsOutputToContain('Promoted 1 to exact')
            ->assertSuccessful();

        $this->assertSame(
            2,
            MediaItem::withoutGlobalScopes()->where('match_confidence', MatchConfidence::Fuzzy)->count(),
            'Only one row should have been touched.',
        );
    }

    public function test_one_failing_row_does_not_end_the_run(): void
    {
        // A run of thousands must not be lost to a single bad row.
        Http::fake(['musicbrainz.org/*' => fn () => throw new \RuntimeException('provider exploded')]);

        $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d61');
        $this->track(MatchConfidence::Fuzzy, 'badf0c46-e52b-4534-b59b-0aea31d32d62');

        $this->artisan('music:rescore', ['--sleep' => 0])->assertSuccessful();
    }

    public function test_an_isrc_only_row_is_in_scope_too(): void
    {
        // a5's review note. On the Mac library this is moot -- all 3,598 rows
        // with an ISRC also carry an MBID, and there are zero ISRC-only rows --
        // but that is a coincidence of one library, and a scope that is only
        // accidentally complete silently misses rows on somebody else's.
        //
        // Both are routes MusicBrainz::resolveRecording() resolves by, so both
        // earn Exact when they land.
        $this->fakeMusicBrainzHit();

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Stressed Out',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => 'Twenty One Pilots',
            'musicbrainz_recording_id' => null,
            'isrc' => 'USAT21800165',
        ]);

        $item->forceFill(['match_confidence' => MatchConfidence::Fuzzy])->saveQuietly();

        $this->artisan('music:rescore', ['--sleep' => 0])
            ->expectsOutputToContain('Promoted 1 to exact')
            ->assertSuccessful();

        $this->assertSame(MatchConfidence::Exact, $item->fresh()->match_confidence);
    }

    public function test_a_row_with_no_identifier_at_all_is_still_out_of_scope(): void
    {
        // Widening to ISRC must not widen to everything: a row with neither is
        // the genuinely-unmatched case that needs the scorer, not a re-score.
        Http::fake();

        $this->track(MatchConfidence::Fuzzy, null);

        $this->artisan('music:rescore', ['--sleep' => 0])
            ->expectsOutputToContain('Nothing to re-score')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * A MusicBrainz that resolves one recording, by either route.
     *
     * Both shapes in one payload, because the two paths read different keys: a
     * by-id lookup takes the recording resource at the top level, while the
     * ISRC path goes through `searchRecordings()` and reads `recordings`. A
     * fake with only the former makes an ISRC search silently find nothing --
     * which is what my first version of this did, and the ISRC test failed for
     * that reason rather than any fault in the command.
     */
    private function fakeMusicBrainzHit(): void
    {
        $recording = [
            'id' => 'badf0c46-e52b-4534-b59b-0aea31d32d61',
            'title' => 'Stressed Out',
            'first-release-date' => '2015-04-28',
            'releases' => [[
                'title' => 'Blurryface',
                'date' => '2015-05-17',
                'release-group' => ['primary-type' => 'Album'],
            ]],
        ];

        Http::fake(['musicbrainz.org/*' => Http::response($recording + ['recordings' => [$recording]])]);
    }

    private function track(MatchConfidence $confidence, ?string $recordingId): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Stressed Out',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => 'Twenty One Pilots',
            'musicbrainz_recording_id' => $recordingId,
        ]);

        $item->forceFill(['match_confidence' => $confidence])->saveQuietly();

        return $item->fresh();
    }
}

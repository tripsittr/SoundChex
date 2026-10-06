<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\MusicMetadata;
use App\Models\User;
use App\Services\Metadata\Sources\Music\FileTagger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A track number that is not a track number (#507).
 *
 * Measured on a real library: **7,654 of 8,233** rows carried a value above
 * 100, each matching the index prefix on its own filename — `906 Stressed
 * Out.mp3` stored as track 906. Only 579 were plausible. Disc numbers had no
 * such problem, so this is specific to the track field.
 *
 * It matters beyond tidiness: the review page answers "no album — is this a
 * single?" by reading the track number, and the organiser pads it into the
 * filename it files under.
 */
class TrackNumberSanityTest extends TestCase
{
    use RefreshDatabase;

    private function trackNumber(?string $raw): ?int
    {
        $method = new \ReflectionMethod(FileTagger::class, 'trackNumber');

        return $method->invoke(app(FileTagger::class), $raw);
    }

    public function test_a_real_track_number_is_kept(): void
    {
        $this->assertSame(7, $this->trackNumber('7'));
        $this->assertSame(1, $this->trackNumber('1'));
    }

    public function test_the_common_slash_form_still_works(): void
    {
        // "3/12" is how most taggers write it, and reading the leading number
        // is the behaviour this must not break.
        $this->assertSame(3, $this->trackNumber('3/12'));
    }

    public function test_a_filename_index_is_rejected(): void
    {
        // The actual defect. 906 is not a position on a record.
        $this->assertNull($this->trackNumber('906'));
        $this->assertNull($this->trackNumber('7373'));
    }

    public function test_zero_is_rejected(): void
    {
        // There is no track zero, and storing it sorts an album wrongly while
        // looking like a real answer.
        $this->assertNull($this->trackNumber('0'));
    }

    public function test_the_boundary_is_inclusive(): void
    {
        $this->assertSame(100, $this->trackNumber('100'));
        $this->assertNull($this->trackNumber('101'));
    }

    public function test_an_absent_tag_is_still_null(): void
    {
        $this->assertNull($this->trackNumber(null));
        $this->assertNull($this->trackNumber(''));
        $this->assertNull($this->trackNumber('not a number'));
    }

    /* ------------------------------------------------------- cleanup ---- */

    public function test_the_command_clears_the_bad_rows_and_keeps_the_good(): void
    {
        $this->rowWithTrack(906);
        $this->rowWithTrack(918);
        $this->rowWithTrack(7);

        $this->artisan('library:clear-bad-track-numbers')->assertSuccessful();

        $this->assertSame(0, MusicMetadata::where('track_number', '>', 100)->count());
        $this->assertSame(1, MusicMetadata::whereNotNull('track_number')->count(), 'A plausible track number was cleared.');
        $this->assertSame(7, MusicMetadata::whereNotNull('track_number')->first()->track_number);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        // The command reports what it would do and writes nothing, because
        // 7,654 rows is not a change to make without looking first.
        $this->rowWithTrack(906);

        $this->artisan('library:clear-bad-track-numbers', ['--dry-run' => true])
            ->expectsOutputToContain('Nothing was written')
            ->assertSuccessful();

        $this->assertSame(906, MusicMetadata::first()->track_number);
    }

    public function test_nulls_rather_than_clamping(): void
    {
        // A wrong small number is worse than none: it reads as real, sorts an
        // album into nonsense, and nothing later can tell it was invented.
        $this->rowWithTrack(906);

        $this->artisan('library:clear-bad-track-numbers')->assertSuccessful();

        $this->assertNull(MusicMetadata::first()->track_number);
    }

    public function test_the_filename_pass_is_opt_in(): void
    {
        // The ceiling rests on a fact; this rests on a judgement, so the safe
        // pass stays safe unless somebody asks for the other one.
        $this->rowWithTrackAndFile(68, '68 Impossible Germany.mp3');

        $this->artisan('library:clear-bad-track-numbers')->assertSuccessful();

        $this->assertSame(68, MusicMetadata::first()->track_number);
    }

    public function test_it_clears_a_number_that_is_the_filename_index(): void
    {
        $this->rowWithTrackAndFile(68, '68 Impossible Germany.mp3');

        $this->artisan('library:clear-bad-track-numbers', ['--match-filename' => true])
            ->assertSuccessful();

        $this->assertNull(MusicMetadata::first()->track_number);
    }

    public function test_ordinary_album_numbering_is_never_cleared(): void
    {
        // The finding that forced this guard: "02 Hang Me Up to Dry.mp3" as
        // track 2 is correct, and 162 of this library's 431 filename matches
        // were that shape. Clearing them would destroy right answers to tidy
        // up wrong ones.
        $this->rowWithTrackAndFile(2, '02 Hang Me Up to Dry.mp3');
        $this->rowWithTrackAndFile(18, "18 Don't Carry It All.mp3");

        $this->artisan('library:clear-bad-track-numbers', ['--match-filename' => true])
            ->assertSuccessful();

        $this->assertSame(2, MusicMetadata::whereNotNull('track_number')->count());
    }

    public function test_a_number_that_does_not_match_its_filename_is_left_alone(): void
    {
        // Only an exact match is evidence. A real track 40 on a file named
        // something else is just a track number.
        $this->rowWithTrackAndFile(40, 'Some Song.mp3');

        $this->artisan('library:clear-bad-track-numbers', ['--match-filename' => true])
            ->assertSuccessful();

        $this->assertSame(40, MusicMetadata::first()->track_number);
    }

    public function test_the_dry_run_counts_what_the_real_run_clears(): void
    {
        // Shared rule rather than two copies, so a dry run cannot promise a
        // different number from what the run does.
        $this->rowWithTrackAndFile(68, '68 Impossible Germany.mp3');
        $this->rowWithTrackAndFile(2, '02 Keep Me.mp3');

        $this->artisan('library:clear-bad-track-numbers', ['--match-filename' => true, '--dry-run' => true])
            ->expectsOutputToContain('1 row(s) match')
            ->assertSuccessful();

        $this->artisan('library:clear-bad-track-numbers', ['--match-filename' => true])->assertSuccessful();

        $this->assertSame(1, MusicMetadata::whereNotNull('track_number')->count());
    }

    private function rowWithTrackAndFile(int $track, string $filename): MusicMetadata
    {
        $user = User::factory()->create();

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => "/music/{$filename}",
            'owned' => true,
        ]);

        return $item->musicMetadata()->create(['artist' => 'Someone', 'track_number' => $track]);
    }

    private function rowWithTrack(int $track): MusicMetadata
    {
        $user = User::factory()->create();

        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => "/music/{$track} A Song.mp3",
            'owned' => true,
        ]);

        return $item->musicMetadata()->create(['artist' => 'Someone', 'track_number' => $track]);
    }
}

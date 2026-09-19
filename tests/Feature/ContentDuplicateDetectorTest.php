<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\DuplicateMatch;
use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Same recording, different file (S-257).
 *
 * Byte detection misses the common case — the same song acquired twice at a
 * different bitrate or in a different format. These match on the recording's
 * identity (ISRC / MusicBrainz / AcoustID) or, failing that, close tags plus a
 * near-equal length. Because the two files genuinely differ, they are flagged
 * for review only and are deleted only by the user choosing which copy to keep.
 */
class ContentDuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(DuplicateDetector::class);
        $this->user = User::factory()->create();

        app(SettingsService::class)->set('library_detect_duplicates', true);
        app(SettingsService::class)->set('library_detect_content_duplicates', true);
        app(SettingsService::class)->set('library_duplicate_action', 'review');
    }

    /* ------------------------------------------------------ detection --- */

    public function test_it_flags_the_same_isrc_in_a_different_file(): void
    {
        // Different bytes (a FLAC and an MP3), same recording code.
        $original = $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);

        $found = $this->detector->check($copy);

        $this->assertNotNull($found);
        $this->assertSame($original->id, $found->id);
        $this->assertSame(DuplicateStatus::Pending, $copy->fresh()->duplicate_status);
        $this->assertSame(DuplicateMatch::Isrc, $copy->fresh()->duplicate_match);
    }

    public function test_it_flags_the_same_musicbrainz_recording(): void
    {
        $original = $this->track('a.flac', 'aaa', ['musicbrainz_recording_id' => 'b1a9c0e9-1111-2222-3333-444455556666']);
        $copy = $this->track('b.mp3', 'bbb', ['musicbrainz_recording_id' => 'b1a9c0e9-1111-2222-3333-444455556666']);

        $found = $this->detector->check($copy);

        $this->assertSame($original->id, $found?->id);
        $this->assertSame(DuplicateMatch::MusicBrainz, $copy->fresh()->duplicate_match);
    }

    public function test_it_flags_the_same_acoustid_fingerprint(): void
    {
        $original = $this->track('a.flac', 'aaa', ['acoustid' => 'e5f6a7b8-1234']);
        $copy = $this->track('b.mp3', 'bbb', ['acoustid' => 'e5f6a7b8-1234']);

        $found = $this->detector->check($copy);

        $this->assertSame($original->id, $found?->id);
        $this->assertSame(DuplicateMatch::AcoustId, $copy->fresh()->duplicate_match);
    }

    public function test_it_flags_a_close_tag_and_length_match_when_no_ids(): void
    {
        $original = $this->track('take-on-me.flac', 'aaa', [
            'artist' => 'a-ha', 'album' => 'Hunting High and Low', 'duration_ms' => 225000,
        ], title: 'Take On Me');
        $copy = $this->track('take_on_me.mp3', 'bbb', [
            'artist' => 'A-HA', 'album' => 'Hunting High and Low', 'duration_ms' => 226500,
        ], title: 'take on me');

        $found = $this->detector->check($copy);

        $this->assertSame($original->id, $found?->id);
        $this->assertSame(DuplicateMatch::Fuzzy, $copy->fresh()->duplicate_match);
    }

    public function test_a_length_outside_tolerance_is_not_a_fuzzy_match(): void
    {
        // A radio edit and the album cut are the same title but different tracks.
        $this->track('song.flac', 'aaa', ['artist' => 'X', 'duration_ms' => 180000], title: 'Song');
        $edit = $this->track('song-radio.mp3', 'bbb', ['artist' => 'X', 'duration_ms' => 210000], title: 'Song');

        $this->assertNull($this->detector->check($edit));
        $this->assertNull($edit->fresh()->duplicate_status);
    }

    public function test_a_different_album_is_not_a_fuzzy_match(): void
    {
        // The single and the album cut of the same song are separate files.
        $this->track('song.flac', 'aaa', ['artist' => 'X', 'album' => 'Single', 'duration_ms' => 200000], title: 'Song');
        $albumCut = $this->track('song2.mp3', 'bbb', ['artist' => 'X', 'album' => 'The Album', 'duration_ms' => 200000], title: 'Song');

        $this->assertNull($this->detector->check($albumCut));
    }

    public function test_content_matching_can_be_switched_off_independently(): void
    {
        app(SettingsService::class)->set('library_detect_content_duplicates', false);

        $this->track('song.flac', 'aaa', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'bbb', ['isrc' => 'USRC17607839']);

        $this->assertNull($this->detector->check($copy));
    }

    public function test_a_content_match_is_never_auto_deleted(): void
    {
        // Even in auto mode: the files differ, so deleting one is the user's call.
        app(SettingsService::class)->set('library_duplicate_action', 'auto');

        $this->track('song.flac', 'aaa', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'bbb', ['isrc' => 'USRC17607839']);
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertFileExists($copyPath);
        $this->assertSame(DuplicateStatus::Pending, $copy->fresh()->duplicate_status);
    }

    public function test_content_matching_does_not_apply_to_non_music(): void
    {
        // Two books with the same "isrc"-shaped field would never be compared —
        // the content pass is music-only.
        $this->track('a.epub', 'aaa', [], type: MediaItemType::Book);
        $book = $this->track('b.epub', 'bbb', [], type: MediaItemType::Book);

        $this->assertNull($this->detector->check($book));
    }

    /* ---------------------------------------------------- resolution --- */

    public function test_keeping_the_original_deletes_the_flagged_copy(): void
    {
        $original = $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertTrue($this->detector->resolveKeeping($copy->fresh()));
        $this->assertFileDoesNotExist($copyPath);
        $this->assertFileExists($original->absoluteFilePath());
        $this->assertSame(DuplicateStatus::Merged, $copy->fresh()->duplicate_status);
        // The surviving row points at the kept file.
        $this->assertSame($original->fresh()->file_path, $copy->fresh()->file_path);
    }

    public function test_keeping_the_flagged_copy_deletes_the_original_file(): void
    {
        $original = $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);
        $originalPath = $original->absoluteFilePath();
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertTrue($this->detector->resolveKeeping($copy->fresh(), keepDuplicate: true));
        $this->assertFileDoesNotExist($originalPath);
        $this->assertFileExists($copyPath);
        $this->assertSame(DuplicateStatus::Merged, $copy->fresh()->duplicate_status);
        // The flagged copy keeps its own path — it is the survivor.
        $this->assertSame($copyPath, $copy->fresh()->absoluteFilePath());
    }

    public function test_merge_refuses_a_content_match(): void
    {
        // merge() is the byte path; a content match's files differ, so the byte
        // re-compare would fail. It must not un-flag or touch anything.
        $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertFalse($this->detector->merge($copy->fresh()));
        $this->assertFileExists($copyPath);
        // Still flagged — not un-flagged by a failed byte compare.
        $this->assertSame(DuplicateStatus::Pending, $copy->fresh()->duplicate_status);
    }

    public function test_resolving_refuses_when_the_kept_copy_is_missing(): void
    {
        $original = $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        // The copy we intend to keep (the original) is gone.
        Storage::disk('local')->delete('media/unsorted/song.flac');

        $this->assertFalse($this->detector->resolveKeeping($copy->fresh()));
        // The other file is left in place rather than deleted into nothing.
        $this->assertFileExists($copyPath);
    }

    public function test_resolve_best_keeps_the_surviving_copy_when_the_original_file_is_gone(): void
    {
        // A copy deleted from disk in an earlier round leaves an orphaned original
        // row. The flagged copy is then the only real file — resolveKeepingBest
        // must keep it, not skip forever with "a file was missing".
        $original = $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        // The original's file was removed outside the app.
        Storage::disk('local')->delete('media/unsorted/song.flac');

        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->assertSame('resolved', $this->detector->resolveKeepingBest($flagged, breakTies: true));
        $this->assertFileExists($copyPath); // the surviving copy is kept
        $this->assertSame(DuplicateStatus::Merged, $flagged->fresh()->duplicate_status);
    }

    public function test_resolve_best_clears_the_flag_when_neither_file_exists(): void
    {
        $original = $this->track('song.flac', 'flac bytes', ['isrc' => 'USRC17607839']);
        $copy = $this->track('song.mp3', 'mp3 bytes', ['isrc' => 'USRC17607839']);

        $this->detector->check($copy);

        Storage::disk('local')->delete('media/unsorted/song.flac');
        Storage::disk('local')->delete('media/unsorted/song.mp3');

        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        // Nothing to merge, but it must leave the review list rather than fail.
        $this->assertSame('resolved', $this->detector->resolveKeepingBest($flagged, breakTies: true));
        $this->assertSame(DuplicateStatus::Merged, $flagged->fresh()->duplicate_status);
    }

    /* ----------------------------------------------- keep the best --- */

    public function test_best_copy_prefers_the_higher_bitrate(): void
    {
        // Same duration, one file twice the size — clearly higher bitrate.
        $low = $this->track('low.mp3', 'aaa', ['isrc' => 'X', 'duration_ms' => 200000]);
        $low->forceFill(['file_size' => 3_000_000])->saveQuietly();
        $high = $this->track('high.mp3', 'bbb', ['isrc' => 'X', 'duration_ms' => 200000]);
        $high->forceFill(['file_size' => 6_000_000])->saveQuietly();

        $best = $this->detector->bestCopy($low->fresh(), $high->fresh());

        $this->assertTrue($best?->is($high));
    }

    public function test_best_copy_breaks_a_bitrate_tie_on_sample_rate(): void
    {
        $a = $this->track('a.mp3', 'aaa', ['isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 44100]);
        $a->forceFill(['file_size' => 5_000_000])->saveQuietly();
        $b = $this->track('b.mp3', 'bbb', ['isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 48000]);
        $b->forceFill(['file_size' => 5_000_000])->saveQuietly();

        $best = $this->detector->bestCopy($a->fresh(), $b->fresh());

        $this->assertTrue($best?->is($b));
    }

    public function test_best_copy_breaks_a_remaining_tie_on_tag_completeness(): void
    {
        $sparse = $this->track('sparse.mp3', 'aaa', [
            'isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 44100, 'artist' => 'A',
        ]);
        $sparse->forceFill(['file_size' => 5_000_000])->saveQuietly();
        $full = $this->track('full.mp3', 'bbb', [
            'isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 44100,
            'artist' => 'A', 'album' => 'Album', 'track_number' => 3, 'release_year' => 2001,
        ]);
        $full->forceFill(['file_size' => 5_000_000])->saveQuietly();

        $best = $this->detector->bestCopy($sparse->fresh(), $full->fresh());

        $this->assertTrue($best?->is($full));
    }

    public function test_best_copy_returns_null_for_a_genuine_tie(): void
    {
        // Same bitrate, sample rate, and tags — nothing to choose between them.
        $meta = ['isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 44100, 'artist' => 'A', 'album' => 'Album'];
        $a = $this->track('a.mp3', 'aaa', $meta);
        $a->forceFill(['file_size' => 5_000_000])->saveQuietly();
        $b = $this->track('b.mp3', 'bbb', $meta);
        $b->forceFill(['file_size' => 5_050_000])->saveQuietly(); // within the 5% margin

        $this->assertNull($this->detector->bestCopy($a->fresh(), $b->fresh()));
    }

    public function test_resolve_keeping_best_deletes_the_lesser_copy(): void
    {
        $low = $this->track('low.mp3', 'aaa', ['isrc' => 'X', 'duration_ms' => 200000]);
        $low->forceFill(['file_size' => 3_000_000])->saveQuietly();
        $high = $this->track('high.mp3', 'bbb', ['isrc' => 'X', 'duration_ms' => 200000]);
        $high->forceFill(['file_size' => 6_000_000])->saveQuietly();
        $lowPath = $low->absoluteFilePath();
        $highPath = $high->absoluteFilePath();

        // 'high' is flagged as a duplicate of 'low' (arrival order), but 'high'
        // is the better copy — so 'low' must be the one deleted.
        $this->detector->check($high->fresh());
        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->assertSame('resolved', $this->detector->resolveKeepingBest($flagged));
        $this->assertFileExists($highPath);
        $this->assertFileDoesNotExist($lowPath);
    }

    public function test_resolve_keeping_best_leaves_a_tie_for_review(): void
    {
        $meta = ['isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 44100, 'artist' => 'A', 'album' => 'Album'];
        $a = $this->track('a.mp3', 'aaa', $meta);
        $a->forceFill(['file_size' => 5_000_000])->saveQuietly();
        $b = $this->track('b.mp3', 'bbb', $meta);
        $b->forceFill(['file_size' => 5_050_000])->saveQuietly();
        $aPath = $a->absoluteFilePath();
        $bPath = $b->absoluteFilePath();

        $this->detector->check($b->fresh());
        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->assertSame('tie', $this->detector->resolveKeepingBest($flagged));
        // Both survive, and it stays pending for a human.
        $this->assertFileExists($aPath);
        $this->assertFileExists($bPath);
        $this->assertSame(DuplicateStatus::Pending, $flagged->fresh()->duplicate_status);
    }

    public function test_break_ties_keeps_the_newer_copy(): void
    {
        // Identical quality; with breakTies the newer row (higher id) is kept.
        $meta = ['isrc' => 'X', 'duration_ms' => 200000, 'sample_rate' => 44100, 'artist' => 'A', 'album' => 'Album'];
        $older = $this->track('older.mp3', 'aaa', $meta);
        $older->forceFill(['file_size' => 5_000_000])->saveQuietly();
        $newer = $this->track('newer.mp3', 'bbb', $meta);
        $newer->forceFill(['file_size' => 5_050_000])->saveQuietly();
        $olderPath = $older->absoluteFilePath();
        $newerPath = $newer->absoluteFilePath();

        $this->detector->check($newer->fresh());
        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->assertSame('resolved', $this->detector->resolveKeepingBest($flagged, breakTies: true));
        $this->assertFileExists($newerPath);      // newer survives
        $this->assertFileDoesNotExist($olderPath); // older deleted

        [$winner, $reason] = $this->detector->decideKeeper($older->fresh(), $newer->fresh(), breakTies: true);
        $this->assertTrue($winner?->is($newer));
        $this->assertSame('newer', $reason);
    }

    /* ----------------------------------------------- verify cover art --- */

    public function test_merge_flags_cover_review_when_the_covers_differ(): void
    {
        $keeper = $this->track('keeper.mp3', 'aaa', ['isrc' => 'X', 'duration_ms' => 200000]);
        $keeper->forceFill(['file_size' => 6_000_000, 'cover_image_url' => $this->cover('keeper.jpg', 'real album art')])->saveQuietly();
        $loser = $this->track('loser.mp3', 'bbb', ['isrc' => 'X', 'duration_ms' => 200000]);
        $loser->forceFill(['file_size' => 3_000_000, 'cover_image_url' => $this->cover('loser.jpg', 'a compilation cover')])->saveQuietly();

        // keeper is higher bitrate, so it wins; its cover differs from the loser's.
        $this->detector->check($keeper->fresh());
        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->assertSame('resolved', $this->detector->resolveKeepingBest($flagged, breakTies: true));
        $this->assertTrue((bool) $flagged->fresh()->needs_cover_review);
    }

    public function test_merge_does_not_flag_when_the_covers_match(): void
    {
        $art = 'identical album art';
        $keeper = $this->track('keeper.mp3', 'aaa', ['isrc' => 'X', 'duration_ms' => 200000]);
        $keeper->forceFill(['file_size' => 6_000_000, 'cover_image_url' => $this->cover('k.jpg', $art)])->saveQuietly();
        $loser = $this->track('loser.mp3', 'bbb', ['isrc' => 'X', 'duration_ms' => 200000]);
        $loser->forceFill(['file_size' => 3_000_000, 'cover_image_url' => $this->cover('l.jpg', $art)])->saveQuietly();

        $this->detector->check($keeper->fresh());
        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->detector->resolveKeepingBest($flagged, breakTies: true);
        $this->assertFalse((bool) $flagged->fresh()->needs_cover_review);
    }

    public function test_no_cover_flag_when_one_copy_has_no_art(): void
    {
        // Can't compare a difference we can't see — a missing cover never raises
        // the flag (only a visible difference does).
        $keeper = $this->track('keeper.mp3', 'aaa', ['isrc' => 'X', 'duration_ms' => 200000]);
        $keeper->forceFill(['file_size' => 6_000_000, 'cover_image_url' => $this->cover('k.jpg', 'art')])->saveQuietly();
        $loser = $this->track('loser.mp3', 'bbb', ['isrc' => 'X', 'duration_ms' => 200000]);
        $loser->forceFill(['file_size' => 3_000_000])->saveQuietly(); // no cover

        $this->detector->check($keeper->fresh());
        $flagged = MediaItem::whereNotNull('duplicate_of_id')->first();

        $this->detector->resolveKeepingBest($flagged, breakTies: true);
        $this->assertFalse((bool) $flagged->fresh()->needs_cover_review);
    }

    public function test_clearing_cover_review_unsets_the_flag(): void
    {
        $item = $this->track('x.mp3', 'aaa', ['isrc' => 'X']);
        $item->forceFill(['needs_cover_review' => true])->saveQuietly();

        $this->detector->clearCoverReview($item);
        $this->assertFalse((bool) $item->fresh()->needs_cover_review);
    }

    /* -------------------------------------------------------- helpers --- */

    /** Writes a cover file to the public disk and returns its stored path. */
    private function cover(string $name, string $bytes): string
    {
        $path = 'artwork/'.$name;
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /**
     * A catalogued, hashed music track with the given metadata.
     *
     * @param  array<string, mixed>  $meta
     */
    private function track(
        string $filename,
        string $contents,
        array $meta,
        ?string $title = null,
        MediaItemType $type = MediaItemType::Music,
    ): MediaItem {
        $path = 'media/unsorted/'.$filename;
        Storage::disk('local')->put($path, $contents);

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title ?? pathinfo($filename, PATHINFO_FILENAME),
            'file_path' => Storage::disk('local')->path($path),
            'owned' => true,
        ]);

        if ($type === MediaItemType::Music) {
            $item->musicMetadata()->create($meta);
        }

        // Mirror the scanner, which hashes everything it catalogues.
        $this->detector->ensureHashed($item);

        return $item->fresh();
    }
}

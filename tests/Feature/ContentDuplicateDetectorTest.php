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

    /* -------------------------------------------------------- helpers --- */

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

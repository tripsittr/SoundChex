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
 * Resolving a duplicate must leave *both* rows describing reality (#461).
 *
 * Three defects covered here, all of which end with a row pointing at a file
 * that is not there:
 *
 *  - "Keep the duplicate" deleted the original's file and never touched the
 *    original's row, leaving a dead path, no status, and other duplicates still
 *    flagged against it.
 *  - `pickOriginal()` ranked the candidates but never the item being checked,
 *    so an older *filed* row could become the duplicate of a newer loose copy —
 *    and under `duplicate_action = auto` the filed copy is the one deleted.
 *  - A bulk merge accepted title-only matches, which can delete a different
 *    song's file.
 */
class DuplicateResolutionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(DuplicateDetector::class);
        $this->user = User::factory()->create();
    }

    public function test_keeping_the_duplicate_updates_the_original_row_too(): void
    {
        // The original's FILE is deleted in this branch, so its ROW has to stop
        // claiming to describe it. Otherwise the library holds an unplayable
        // row, plays and playlist entries point at nothing, and any other
        // duplicate flagged against it can never be resolved.
        [$original, $copy] = $this->contentPair();

        $originalPath = $original->absoluteFilePath();

        $this->assertTrue($this->detector->resolveKeeping($copy, keepDuplicate: true));

        $original = $original->fresh();

        $this->assertFileDoesNotExist($originalPath, 'The original was the loser, so its file goes.');
        $this->assertSame(
            $copy->fresh()->file_path,
            $original->file_path,
            'The original row must point at the surviving file, not at the deleted one.',
        );
    }

    public function test_the_filed_copy_is_never_made_the_duplicate_of_a_loose_one(): void
    {
        // The filed copy is the one the library is built around. Flagging it as
        // the duplicate of a loose import inverts the pair, and under
        // duplicate_action=auto it is the filed copy that gets deleted.
        $filed = $this->row('Chicago', 'media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3', 'same bytes');
        $loose = $this->row('Chicago', 'media/unsorted/chicago.mp3', 'same bytes');

        $this->detector->ensureHashed($filed);
        $this->detector->ensureHashed($loose);

        // Checked in the order that used to invert the pair: the filed row
        // first, against a loose copy that already exists.
        $this->detector->check($filed->fresh());

        $this->assertNull(
            $filed->fresh()->duplicate_of_id,
            'The filed copy must not become the duplicate of a loose one.',
        );
    }

    public function test_a_resolved_pair_leaves_no_row_pointing_at_a_missing_file(): void
    {
        // The property that matters, stated directly: whichever way a pair is
        // resolved, every surviving row describes a file that exists.
        [$original, $copy] = $this->contentPair();

        $this->detector->resolveKeeping($copy, keepDuplicate: true);

        foreach ([$original->fresh(), $copy->fresh()] as $row) {
            $path = $row->absoluteFilePath();

            $this->assertNotNull($path, "Row {$row->id} has no resolvable path.");
            $this->assertFileExists($path, "Row {$row->id} points at a file that is not there.");
        }
    }

    public function test_report_mode_refuses_to_resolve_from_anywhere(): void
    {
        // The settings page promises "never act, even from the review screen".
        // Only the automatic sweep honoured it; the table actions and
        // library:duplicates --merge did not (#461).
        app(SettingsService::class)->set('library_duplicate_action', 'report');

        [$original, $copy] = $this->contentPair();
        $copyPath = $copy->absoluteFilePath();
        $originalPath = $original->absoluteFilePath();

        $this->assertFalse($this->detector->resolveKeeping($copy));
        $this->assertFileExists($copyPath);
        $this->assertFileExists($originalPath);

        // And the byte path too, which is the one that deletes without asking.
        $filed = $this->row('Track', 'media/library/Music/A/One/t.mp3', 'same bytes');
        $loose = $this->row('Track', 'media/unsorted/t.mp3', 'same bytes');
        $this->detector->ensureHashed($filed);
        $this->detector->ensureHashed($loose);
        $this->detector->check($loose->fresh());

        $loosePath = $loose->absoluteFilePath();

        $this->assertFalse($this->detector->merge($loose->fresh()));
        $this->assertFileExists($loosePath);
    }

    public function test_a_loose_match_is_not_eligible_for_bulk_resolution(): void
    {
        // The rule the bulk action now reads. Likely and SameTitle exist to
        // surface things worth a look, never to decide them.
        $this->assertFalse(DuplicateMatch::Likely->allowsBulkResolution());
        $this->assertFalse(DuplicateMatch::SameTitle->allowsBulkResolution());

        // Identifier matches and the strict fuzzy pass stay eligible, or bulk
        // merge would stop doing the job it exists for.
        $this->assertTrue(DuplicateMatch::Bytes->allowsBulkResolution());
        $this->assertTrue(DuplicateMatch::Isrc->allowsBulkResolution());
        $this->assertTrue(DuplicateMatch::MusicBrainz->allowsBulkResolution());
        $this->assertTrue(DuplicateMatch::AcoustId->allowsBulkResolution());
        $this->assertTrue(DuplicateMatch::Fuzzy->allowsBulkResolution());
        $this->assertTrue(DuplicateMatch::Tmdb->allowsBulkResolution());
        $this->assertTrue(DuplicateMatch::Episode->allowsBulkResolution());
    }

    /**
     * A content-matched pair: two different files holding the same recording,
     * which is what `resolveKeeping()` is for.
     */
    private function contentPair(): array
    {
        $original = $this->row('Chicago', 'media/library/Music/flipturn/Heavy Colors/03 Chicago.flac', 'the flac');
        $copy = $this->row('Chicago', 'media/unsorted/chicago.mp3', 'the mp3');

        $copy->forceFill([
            'duplicate_of_id' => $original->id,
            'duplicate_status' => DuplicateStatus::Pending,
            'duplicate_match' => DuplicateMatch::Isrc,
        ])->saveQuietly();

        return [$original->fresh(), $copy->fresh()];
    }

    private function row(string $title, string $path, string $contents): MediaItem
    {
        Storage::disk('local')->put($path, $contents);

        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'owned' => true,
        ]);
    }
}

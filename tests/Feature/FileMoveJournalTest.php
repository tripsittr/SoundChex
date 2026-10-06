<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\FileMoveKind;
use App\Enums\FileMoveState;
use App\Enums\MediaItemType;
use App\Models\FileMove;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\FileMoveJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every move is recorded before it happens, and every outcome is recoverable
 * (#465).
 *
 * The crash window this closes: `rename()` succeeded, the process died before
 * the row was updated, the item still pointed at the old path, the sweep called
 * it `file_missing`, and the next scan catalogued the moved file as a *new*
 * item — the same file in the library twice, with nothing recording the move.
 *
 * The interesting tests here are the reconciliation ones, because they simulate
 * dying half way through.
 */
class FileMoveJournalTest extends TestCase
{
    use RefreshDatabase;

    private FileMoveJournal $journal;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = app(FileMoveJournal::class);
        $this->user = User::factory()->create();
    }

    public function test_a_plan_records_the_source_facts_before_anything_moves(): void
    {
        // Captured beforehand because afterwards the source is gone and there
        // is nothing left to compare against.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');

        $move = $this->journal->plan($item, $source, Storage::path('media/library/Music/A/One/track.mp3'));

        $this->assertSame(FileMoveState::Planned, $move->state);
        $this->assertNotNull($move->from_inode);
        $this->assertSame(strlen('the audio'), $move->size);
        $this->assertNotNull($move->hash_before);
        $this->assertFileExists($source, 'Planning must not move anything.');
    }

    public function test_executing_a_plan_moves_the_file_and_completes_the_row(): void
    {
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        $this->assertTrue($this->journal->move($item, $source, $target));

        $this->assertFileExists($target);
        $this->assertFileDoesNotExist($source);

        $move = FileMove::first();
        $this->assertSame(FileMoveState::Done, $move->state);
        $this->assertSame($move->hash_before, $move->hash_after, 'The bytes must survive the move.');
        $this->assertNotNull($move->completed_at);
    }

    public function test_it_refuses_to_move_onto_a_different_file(): void
    {
        // Overwriting is how #454 lost a file. A target that is occupied by
        // something else is not ours to replace.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the new one');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        Storage::disk('local')->put('media/library/Music/A/One/track.mp3', 'somebody else');

        $this->assertFalse($this->journal->move($item, $source, $target));

        $this->assertFileExists($source, 'The source stays put on a refusal.');
        $this->assertSame('somebody else', file_get_contents($target));
        $this->assertSame(FileMoveState::Failed, FileMove::first()->state);
    }

    public function test_executing_a_done_move_twice_does_nothing(): void
    {
        // A stage re-running after a crash must not move a file twice -- for a
        // trash move that would mean trashing the survivor.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        $move = $this->journal->plan($item, $source, $target);
        $this->assertTrue($this->journal->execute($move));
        $this->assertTrue($this->journal->execute($move->fresh()), 'A second execute is a no-op, not a failure.');

        $this->assertSame('the audio', file_get_contents($target));
    }

    /* ------------------------------------------------- crash recovery ---- */

    public function test_reconcile_finishes_a_move_that_happened_before_the_crash(): void
    {
        // The exact organizer crash window: the file moved, the row did not.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        $move = $this->journal->plan($item, $source, $target);

        // Die mid-move: mark started, perform the rename by hand, stop there.
        $move->forceFill(['state' => FileMoveState::Started])->save();
        @mkdir(dirname($target), 0775, true);
        rename($source, $target);

        $outcome = $this->journal->reconcile();

        $this->assertSame(1, $outcome['finished']);
        $this->assertSame(FileMoveState::Done, $move->fresh()->state);
        $this->assertSame(
            $target,
            $item->fresh()->file_path,
            'The row must follow the file, or the next scan catalogues it as a new item.',
        );
    }

    public function test_reconcile_replans_a_move_that_had_not_started(): void
    {
        // Marked started, nothing moved. The work is simply owed again.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');

        $move = $this->journal->plan($item, $source, Storage::path('media/library/Music/A/One/track.mp3'));
        $move->forceFill(['state' => FileMoveState::Started])->save();

        $outcome = $this->journal->reconcile();

        $this->assertSame(1, $outcome['replanned']);
        $this->assertSame(FileMoveState::Planned, $move->fresh()->state);
        $this->assertFileExists($source);
    }

    public function test_reconcile_leaves_an_ambiguous_pair_for_a_person(): void
    {
        // Both paths hold different files. Guessing deletes one of them, so
        // this is the one case the reconciler must refuse.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the source');
        $target = Storage::path('media/library/Music/A/One/track.mp3');
        Storage::disk('local')->put('media/library/Music/A/One/track.mp3', 'something else');

        $move = $this->journal->plan($item, $source, $target);
        $move->forceFill(['state' => FileMoveState::Started])->save();

        $outcome = $this->journal->reconcile();

        $this->assertSame(1, $outcome['ambiguous']);
        $this->assertSame(FileMoveState::Started, $move->fresh()->state, 'It stays visible until resolved.');
        $this->assertSame('the source', file_get_contents($source));
        $this->assertSame('something else', file_get_contents($target));
    }

    /* --------------------------------------------------------- undo ----- */

    public function test_a_move_can_be_undone(): void
    {
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        $this->journal->move($item, $source, $target);

        $this->assertTrue($this->journal->undo(FileMove::first()));

        $this->assertFileExists($source);
        $this->assertFileDoesNotExist($target);
        $this->assertSame($source, $item->fresh()->file_path);
        $this->assertSame(FileMoveState::Undone, FileMove::first()->state);
    }

    public function test_an_undo_refuses_when_the_original_path_is_taken(): void
    {
        // An undo that loses data is worse than no undo.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        $this->journal->move($item, $source, $target);

        Storage::disk('local')->put('media/unsorted/track.mp3', 'something took the old name');

        $this->assertFalse($this->journal->undo(FileMove::first()));
        $this->assertSame('something took the old name', file_get_contents($source));
        $this->assertFileExists($target, 'The moved file stays where it is.');
    }

    public function test_a_move_refuses_to_overwrite_an_occupant_that_appeared_mid_flight(): void
    {
        // a5's review of #271, note 1 — a real TOCTOU, verified by hand.
        //
        // `isReversible()` checks `! file_exists(from)` and then hands off to
        // `moveFile()`, which calls `rename()`. On POSIX that replaces the
        // target silently and returns true:
        //
        //     isReversible() -> true
        //     occupant written at the old path
        //     moveFile() -> true
        //     old path now holds: the audio      <- the occupant was destroyed
        //
        // So the guard has to sit at the rename, not at the caller: anything
        // appearing between the check and the move was lost without a word.
        // Narrow in practice (undo is the manual `library:undo-moves`), but it
        // is the same class of bug as #454 and this phase exists to end it.
        //
        // Driving `moveFile()` directly is deliberate. Calling `undo()` here
        // would pass for the wrong reason: `isReversible()` re-reads the disk
        // at call time and would catch the occupant itself, never reaching the
        // window this covers.
        [$item, $source] = $this->fileAt('media/unsorted/track.mp3', 'the audio');
        $target = Storage::path('media/library/Music/A/One/track.mp3');

        $this->journal->move($item, $source, $target);

        $move = FileMove::first();
        $this->assertTrue($move->isReversible(), 'Precondition: at this instant the undo looks safe.');

        // The window: something takes the old path after that check passed.
        Storage::disk('local')->put('media/unsorted/track.mp3', 'a different file arrived here');

        $moveFile = new \ReflectionMethod($this->journal, 'moveFile');

        $this->assertFalse(
            $moveFile->invoke($this->journal, $target, $source),
            'The move must refuse rather than replace the occupant.',
        );
        $this->assertSame(
            'a different file arrived here',
            file_get_contents($source),
            'The file occupying the old path must survive.',
        );
        $this->assertFileExists($target, 'And the moved file stays where it is.');
    }

    public function test_a_move_still_completes_a_case_only_rename(): void
    {
        // The guard must not block one file under two spellings, which is a
        // rename to perform rather than a collision (#454). Identity-equal
        // paths are let through.
        $disk = Storage::disk('local');
        $disk->put('media/library/Music/A/One/Track.mp3', 'the audio');

        $absolute = $disk->path('media/library/Music/A/One/Track.mp3');
        $recased = dirname($absolute).'/TRACK.mp3';

        if (! file_exists($recased)) {
            $this->markTestSkipped('The test volume is case-sensitive, so the two spellings are two files.');
        }

        $moveFile = new \ReflectionMethod($this->journal, 'moveFile');

        $this->assertTrue(
            $moveFile->invoke($this->journal, $absolute, $recased),
            'A case-only rename is one file under two names, not an occupied target.',
        );
        $this->assertSame('the audio', $disk->get('media/library/Music/A/One/TRACK.mp3'));
    }

    public function test_a_trashed_file_is_journalled_and_restorable(): void
    {
        // MediaTrash keeps the file; the journal records where it came from,
        // which is what an undo needs and the trash folder cannot say.
        [$item, $source] = $this->fileAt('media/library/Music/A/One/track.mp3', 'the audio');

        $this->assertTrue($this->journal->trash($item, $source, reason: 'duplicate resolved'));
        $this->assertFileDoesNotExist($source);

        $move = FileMove::first();
        $this->assertSame(FileMoveKind::Trash, $move->kind);
        $this->assertSame(FileMoveState::Done, $move->state);

        $this->assertTrue($this->journal->undo($move));
        $this->assertSame('the audio', file_get_contents($source));
    }

    /** @return array{0: MediaItem, 1: string} */
    private function fileAt(string $relative, string $contents): array
    {
        Storage::disk('local')->put($relative, $contents);
        $absolute = Storage::disk('local')->path($relative);

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Track',
            'file_path' => $absolute,
            'owned' => true,
        ]);

        return [$item->fresh(), $absolute];
    }
}

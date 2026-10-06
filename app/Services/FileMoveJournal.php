<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\FileMoveKind;
use App\Enums\FileMoveState;
use App\Models\FileMove;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Moves files, and records every move before making it (#465).
 *
 * The rule this enforces: **no byte moves without a journal row first.** That
 * row is written in `planned`, flipped to `started` immediately before the
 * filesystem call, and to `done` after. A process killed at any point leaves a
 * row that says exactly what was being attempted, with the device, inode, size
 * and hash of the source captured *beforehand*.
 *
 * That is what closes the organizer's crash window. Previously `rename()` could
 * succeed and the process die before the row was updated: the item still
 * pointed at the old path, the sweep called it `file_missing`, and the next scan
 * catalogued the moved file as a new item — the same file in the library twice,
 * with nothing recording that a move had happened.
 *
 * It is also what `library:undo-moves` reverses, and what the sweeper
 * reconciles.
 */
class FileMoveJournal
{
    public function __construct(private MediaTrash $trash) {}

    /**
     * Records an intended move without performing it.
     *
     * The planning stage writes these; the filing stage executes them. Split
     * because a plan is reviewable — it is what a dry run prints and what a
     * person approves before 8,000 files move.
     */
    public function plan(
        MediaItem $item,
        string $from,
        string $to,
        FileMoveKind $kind = FileMoveKind::File,
        ?string $batchId = null,
    ): FileMove {
        return FileMove::create([
            'media_item_id' => $item->id,
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'kind' => $kind,
            'from_path' => $from,
            'to_path' => $to,
            'state' => FileMoveState::Planned,
        ] + $this->factsAbout($from));
    }

    /**
     * Performs a planned move.
     *
     * Returns true when the file is at `to_path` and the row says so. On any
     * failure the file is left at `from_path`, the row records why, and the
     * caller must not treat the move as having happened.
     */
    public function execute(FileMove $move): bool
    {
        if ($move->state === FileMoveState::Done) {
            // Idempotent: a stage re-running after a crash must not move a
            // file twice, which for a trash move would mean trashing the
            // survivor.
            return true;
        }

        $from = (string) $move->from_path;
        $to = (string) $move->to_path;

        if (! is_file($from)) {
            // Already moved, or never there. The reconciler decides which;
            // this is not the place to guess.
            return $this->fail($move, 'the source file is not there');
        }

        if (blank($to)) {
            return $this->fail($move, 'no target path was planned');
        }

        // Written before the first byte moves. Everything after this point is
        // recoverable precisely because this row exists.
        $move->forceFill(['state' => FileMoveState::Started])->save();

        $directory = dirname($to);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return $this->fail($move, "could not create {$directory}");
        }

        // Refuse rather than overwrite. A file at the target is somebody's,
        // and deciding it is expendable is exactly the mistake #454 was.
        if (file_exists($to) && ! FileIdentity::same($from, $to)) {
            return $this->fail($move, 'something else already occupies the target');
        }

        if (! $this->moveFile($from, $to)) {
            return $this->fail($move, 'the move failed');
        }

        $move->forceFill([
            'state' => FileMoveState::Done,
            'hash_after' => $this->hash($to),
            'completed_at' => now(),
            'error' => null,
        ])->save();

        // The row follows the file. `reconcile()` and `undo()` already did
        // this and `execute()` did not, so a caller other than the organizer
        // -- which happens to write `file_path` itself -- left the row pointing
        // at a path that no longer exists, making the item unplayable. That is
        // the crash-window symptom Phase 2 exists to prevent, reachable
        // through the ordinary path (found by the manifest tests a5 asked for
        // on #280).
        //
        // Not for a trashed file (its row keeps the surviving copy's path) and
        // not for a sidecar (whose journal row carries the media item's id, so
        // repointing would aim the catalogue at a .srt).
        $item = $move->mediaItem;

        if ($item !== null && ! in_array($move->kind, [FileMoveKind::Trash, FileMoveKind::Sidecar], true)) {
            $item->forceFill([
                'file_path' => $to,
                // Rule 2: the stored hash described the old path.
                'content_hash' => null,
            ])->saveQuietly();
        }

        return true;
    }

    /** Plans and immediately performs a move. */
    public function move(
        MediaItem $item,
        string $from,
        string $to,
        FileMoveKind $kind = FileMoveKind::File,
        ?string $batchId = null,
    ): bool {
        return $this->execute($this->plan($item, $from, $to, $kind, $batchId));
    }

    /**
     * Trashes a file through the journal, so the delete can be undone.
     *
     * `MediaTrash` already keeps the file; this records *where it came from*,
     * which is what an undo needs and what the trash folder alone cannot say.
     */
    public function trash(MediaItem $item, string $path, ?string $reason = null, ?string $batchId = null): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $move = FileMove::create([
            'media_item_id' => $item->id,
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'kind' => FileMoveKind::Trash,
            'from_path' => $path,
            'state' => FileMoveState::Started,
            'error' => $reason,
        ] + $this->factsAbout($path));

        $relative = $this->trash->discard($path, $reason);

        if ($relative === null) {
            $move->forceFill([
                'state' => FileMoveState::Failed,
                'error' => 'could not be moved to the trash',
            ])->save();

            return false;
        }

        $move->forceFill([
            'to_path' => $relative,
            'state' => FileMoveState::Done,
            'completed_at' => now(),
            'error' => null,
        ])->save();

        return true;
    }

    /**
     * Reverses a completed move.
     *
     * Only when the original path is free. Restoring over a file that has since
     * taken that path would destroy it, and an undo that loses data is worse
     * than no undo.
     */
    public function undo(FileMove $move): bool
    {
        if (! $move->isReversible()) {
            return false;
        }

        $from = (string) $move->from_path;
        $to = (string) $move->to_path;

        $restored = $move->kind === FileMoveKind::Trash
            ? $this->trash->restore($to, $from)
            : $this->moveFile($to, $from);

        if (! $restored) {
            return false;
        }

        $move->forceFill(['state' => FileMoveState::Undone])->save();

        // The row has to follow the file, or the catalogue points at a path
        // that is now empty.
        $item = $move->mediaItem;

        // Same exclusion as reconcile(): undoing a sidecar's move must not
        // point the item at the sidecar.
        if ($item !== null && $move->kind !== FileMoveKind::Sidecar) {
            $item->forceFill(['file_path' => $from, 'content_hash' => null])->saveQuietly();
        }

        return true;
    }

    /**
     * Resolves rows left in `started` by a crash.
     *
     * Four cases, each decidable from what was recorded before the move:
     *
     *  - target present and matching, source gone  -> the move happened; finish
     *                                                 the bookkeeping.
     *  - source present, target absent or partial  -> nothing happened; drop
     *                                                 any partial and replan.
     *  - both present and the same file            -> a hardlink or case-only
     *                                                 move; finish.
     *  - both present and different                -> leave it. A person has to
     *                                                 look, and guessing here
     *                                                 deletes one of them.
     *
     * @return array{finished: int, replanned: int, ambiguous: int}
     */
    public function reconcile(): array
    {
        $finished = $replanned = $ambiguous = 0;

        foreach (FileMove::where('state', FileMoveState::Started)->get() as $move) {
            $from = (string) $move->from_path;
            $to = (string) $move->to_path;

            $sourceThere = is_file($from);
            $targetThere = $to !== '' && is_file($to);

            if ($targetThere && ! $sourceThere) {
                $move->forceFill([
                    'state' => FileMoveState::Done,
                    'hash_after' => $this->hash($to),
                    'completed_at' => now(),
                ])->save();

                $item = $move->mediaItem;

                // Not for a trashed file (its row keeps the surviving copy's
                // path) and not for a sidecar: a journalled sidecar carries
                // the media item's id because that is what relates them, and
                // repointing file_path would leave the catalogue aimed at a
                // .srt rather than the film (a5's review of #278).
                if ($item !== null && ! in_array($move->kind, [FileMoveKind::Trash, FileMoveKind::Sidecar], true)) {
                    $item->forceFill(['file_path' => $to, 'content_hash' => null])->saveQuietly();
                }

                $finished++;

                continue;
            }

            if ($sourceThere && ! $targetThere) {
                $move->forceFill(['state' => FileMoveState::Planned, 'error' => null])->save();
                $replanned++;

                continue;
            }

            if ($sourceThere && $targetThere && FileIdentity::same($from, $to)) {
                $move->forceFill(['state' => FileMoveState::Done, 'completed_at' => now()])->save();
                $finished++;

                continue;
            }

            Log::warning('A file move cannot be reconciled automatically', [
                'move' => $move->id,
                'from' => $from,
                'to' => $to,
                'source_exists' => $sourceThere,
                'target_exists' => $targetThere,
            ]);

            $ambiguous++;
        }

        return ['finished' => $finished, 'replanned' => $replanned, 'ambiguous' => $ambiguous];
    }

    /**
     * Moves a file, verifying a cross-volume copy before removing the source.
     *
     * `rename()` first: atomic on one volume, and needs no room for a second
     * copy of a film. Across volumes it fails, and the copy fallback is
     * verified by content — a copy can be the right length and the wrong bytes
     * (#462).
     */
    private function moveFile(string $from, string $to): bool
    {
        // Never over an occupant. `rename()` on POSIX replaces the target
        // silently and still returns true -- verified:
        //
        //     before: from.txt = "the file that reappeared"
        //     rename() returned: true
        //     after : from.txt = "the moved file coming back"
        //
        // `execute()` already refuses an occupied target, but `undo()` reaches
        // here through `isReversible()`, which checks the path and then hands
        // off -- so anything appearing in between was destroyed without a word
        // (a5's review of #271). The guard belongs at the rename, which is the
        // last moment before the bytes move, rather than at a caller that then
        // has to hold the fact true.
        //
        // Identity-equal is allowed through: that is one file under two
        // spellings, which is a rename to perform rather than a collision
        // (#454).
        if (file_exists($to) && ! FileIdentity::same($from, $to)) {
            Log::warning('Refused to move a file onto something else', [
                'from' => $from,
                'to' => $to,
            ]);

            return false;
        }

        if (@rename($from, $to)) {
            return true;
        }

        // Windows refuses to move a read-only file, the same way it refuses to
        // unlink one. 285 of 2,843 files in one real library carry it.
        if (@chmod($from, 0666) && @rename($from, $to)) {
            return true;
        }

        // Cross-volume: copy to a partial name so an interrupted copy is never
        // mistaken for a finished file by anything else looking at the folder.
        $partial = $to.'.sc-partial';

        if (! @copy($from, $partial)) {
            @unlink($partial);

            return false;
        }

        if (@filesize($partial) !== @filesize($from) || $this->hash($partial) !== $this->hash($from)) {
            @unlink($partial);

            return false;
        }

        if (! @rename($partial, $to)) {
            @unlink($partial);

            return false;
        }

        if (! @unlink($from)) {
            Log::warning('Moved a file but could not remove the source', [
                'from' => $from,
                'to' => $to,
                'note' => 'both copies exist; the journal row records the move as done',
            ]);
        }

        return true;
    }

    /**
     * The facts needed to recognise this file later.
     *
     * Captured before the move, because afterwards the source is gone and there
     * is nothing left to compare against.
     *
     * @return array<string, mixed>
     */
    private function factsAbout(string $path): array
    {
        if (! is_file($path)) {
            return ['from_device' => null, 'from_inode' => null, 'size' => null, 'hash_before' => null];
        }

        $stat = @stat($path);

        return [
            'from_device' => $stat['dev'] ?? null,
            'from_inode' => $stat['ino'] ?? null,
            'size' => $stat['size'] ?? null,
            // Only for files small enough that hashing is not the slowest part
            // of the move. A 40 GB remux is identified by device+inode+size.
            'hash_before' => ($stat['size'] ?? PHP_INT_MAX) <= 512 * 1024 * 1024
                ? $this->hash($path)
                : null,
        ];
    }

    private function hash(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = @hash_file(DuplicateDetector::HASH, $path);

        return $hash === false ? null : $hash;
    }

    private function fail(FileMove $move, string $why): bool
    {
        $move->forceFill(['state' => FileMoveState::Failed, 'error' => $why])->save();

        Log::warning('A journalled file move failed', [
            'move' => $move->id,
            'item' => $move->media_item_id,
            'from' => $move->from_path,
            'to' => $move->to_path,
            'reason' => $why,
        ]);

        return false;
    }
}

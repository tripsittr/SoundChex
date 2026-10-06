<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\FileMoveKind;
use App\Enums\FileMoveState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded move of one file (#465).
 *
 * Written *before* the move happens, which is the whole point. The organizer's
 * crash window was: `rename()` succeeds, the process dies, the row still points
 * at the old path, the sweep marks the item `file_missing`, and the next scan
 * catalogues the moved file as a brand-new item. The same file is then in the
 * library twice under two rows, and nothing anywhere records that one move
 * happened.
 *
 * With a journal row in `planned` before the first byte moves, every outcome is
 * recoverable: the reconciler compares what is on disk against what the row
 * says was intended, and the device and inode captured beforehand distinguish
 * "the target is the file we moved" from "the target is something else".
 *
 * It is also what `library:undo-moves` reverses.
 */
class FileMove extends Model
{
    protected $fillable = [
        'media_item_id',
        'batch_id',
        'kind',
        'from_path',
        'to_path',
        'from_device',
        'from_inode',
        'size',
        'hash_before',
        'hash_after',
        'state',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'kind' => FileMoveKind::class,
        'state' => FileMoveState::class,
        'from_device' => 'integer',
        'from_inode' => 'integer',
        'size' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /**
     * Whether this move can be reversed.
     *
     * Only a completed move, and only when its source is free — restoring over
     * something that has since taken the old path would destroy that file,
     * which is the class of mistake this whole phase exists to stop.
     */
    public function isReversible(): bool
    {
        return $this->state === FileMoveState::Done
            && filled($this->to_path)
            && filled($this->from_path)
            && ! file_exists($this->from_path);
    }
}

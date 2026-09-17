<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file in a transfer.
 *
 * Carries its own failure reason rather than throwing it away, because "1,204
 * transferred, 3 failed" is useless without knowing which three and why.
 */
class TransferItem extends Model
{
    public const PENDING = 'pending';
    public const TRANSFERRING = 'transferring';
    public const COMPLETE = 'complete';
    public const FAILED = 'failed';

    /** Already present with the right hash. Done, without being fetched. */
    public const SKIPPED = 'skipped';

    /** Enough to ride out a dropped connection, few enough to stop eventually. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'transfer_id', 'remote_id', 'path', 'expected_hash', 'expected_bytes',
        'state', 'failure_reason', 'attempts', 'bytes_received',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'state' => self::FAILED,
            // Truncated rather than dropped: the column is indexed and a stack
            // trace in it helps nobody.
            'failure_reason' => mb_strimwidth($reason, 0, 250, '…'),
        ])->save();
    }

    /** Whether another attempt is worth making. */
    public function canRetry(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }
}

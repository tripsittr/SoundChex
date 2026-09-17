<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One transfer, from the receiving side.
 *
 * The receiver pulls: it knows what it already has, so it decides what to ask
 * for, which is what makes resuming cheap and repeating free.
 */
class Transfer extends Model
{
    public const REQUESTED = 'requested';
    public const APPROVED = 'approved';
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const COMPLETE = 'complete';
    public const FAILED = 'failed';

    /**
     * Called off from this end.
     *
     * Distinct from `failed`, which is something going wrong, and from
     * `paused`, which leaves the request approved and the token live on the
     * other machine. Cancelling ends it there as well as here.
     */
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'source_url', 'remote_request_id', 'token', 'wants', 'metadata_imported', 'claim',
        'state', 'last_error', 'total_files', 'total_bytes',
        'started_at', 'finished_at',
    ];

    protected $hidden = ['token', 'claim'];

    protected function casts(): array
    {
        return [
            'wants' => 'array',
            'metadata_imported' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }

    /**
     * What is left to do.
     *
     * The bookmark is not a separate thing: an interrupted transfer resumes by
     * asking exactly the question it asked at the start.
     */
    public function remaining(): HasMany
    {
        return $this->items()->whereIn('state', [TransferItem::PENDING, TransferItem::TRANSFERRING]);
    }

    public function wants(string $what): bool
    {
        return in_array($what, $this->wants ?? [], true);
    }

    /** @return array<string, int> */
    public function progress(): array
    {
        $counts = $this->items()
            ->selectRaw('state, COUNT(*) as n, COALESCE(SUM(expected_bytes), 0) as bytes')
            ->groupBy('state')
            ->get()
            ->keyBy('state');

        return [
            'complete' => (int) ($counts['complete']->n ?? 0),
            'skipped' => (int) ($counts['skipped']->n ?? 0),
            'failed' => (int) ($counts['failed']->n ?? 0),
            'pending' => (int) ($counts['pending']->n ?? 0),
            // Skipped counts as done: the file is present and correct, which is
            // the only thing anyone cares about.
            'done_bytes' => (int) (($counts['complete']->bytes ?? 0) + ($counts['skipped']->bytes ?? 0)),
        ];
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A request from another server to read this one.
 *
 * Lives on the source. Nothing is readable until a person here approves it —
 * which is the whole security model, and better than a pre-shared token
 * because a token copied between machines can be copied a third time, and once
 * issued it exists whether anyone is watching or not.
 */
class TransferRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DENIED = 'denied';
    public const REVOKED = 'revoked';
    public const COMPLETE = 'complete';

    /** What a token minted for a transfer is allowed to do, and nothing else. */
    public const ABILITY = 'transfer:read';

    /**
     * How long a request waits for a person, before it is approved.
     *
     * Long enough to walk to the other machine, short enough not to linger.
     */
    public const LIFETIME_HOURS = 4;

    /**
     * How long an approval lasts, from the moment it is given.
     *
     * Sized against the job rather than the walk: a full copy of this library
     * is 46.3 GB, which is ~2.6 hours at 5 MB/s and longer if the link drops
     * to a relay or the run is paused. Four hours left no margin for either,
     * and expiring mid-copy costs the whole remaining transfer.
     */
    public const APPROVAL_HOURS = 12;

    protected $hidden = ['plain_token'];

    protected $fillable = [
        'ip', 'device_name', 'platform', 'wants', 'code',
        'state', 'denied_reason', 'expires_at', 'approved_at', 'token_id', 'plain_token',
        'items_total', 'items_complete', 'items_failed', 'items_skipped',
        'items_pending', 'bytes_complete', 'bytes_total', 'worker_alive', 'progress_state',
        'progress_note', 'progress_at', 'claim_hash',
    ];

    protected function casts(): array
    {
        return [
            'wants' => 'array',
            'expires_at' => 'datetime',
            'progress_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * A short code shown on both screens.
     *
     * Not a secret — it is shown before anyone has authenticated. Its job is to
     * tell the person approving that the request in front of them is the one
     * that was just started, rather than another arriving at the same moment.
     */
    public static function newCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Whether this request can still be used.
     *
     * Expiry is checked here rather than by a scheduled sweep, so a request
     * that lapses while nobody is looking is still refused.
     */
    public function isUsable(): bool
    {
        return $this->state === self::APPROVED
            && $this->expires_at->isFuture();
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING
            && $this->expires_at->isFuture();
    }

    /**
     * What to tell the requester, without telling it anything useful.
     *
     * An expired request reports as expired rather than pending, so a receiver
     * polling forever learns to stop.
     */
    public function publicState(): string
    {
        // An approved request that has run out of time reports as expired too,
        // not merely a pending one.
        //
        // `isUsable()` already refuses it, so the source was right to answer
        // 401 — but this told the receiver "approved", so it polled, believed
        // it, and asked again with a token that could never work. A status
        // that says approved while every request is refused is worse than no
        // status.
        //
        // Denied and revoked keep their own names: a decision someone made is
        // more use to whoever reads it than the clock running out.
        if (in_array($this->state, [self::PENDING, self::APPROVED], true)
            && $this->expires_at->isPast()) {
            return 'expired';
        }

        return $this->state;
    }

    /** Ends the request and the token with it. */
    public function revoke(string $state = self::REVOKED): void
    {
        if ($this->token_id !== null) {
            \Laravel\Sanctum\PersonalAccessToken::where('id', $this->token_id)->delete();
        }

        // Both copies go: the Sanctum row above, and ours.
        $this->forceFill([
            'state' => $state,
            'token_id' => null,
            'plain_token' => null,
        ])->save();
    }

    /** A human summary of what was asked for, for the approval screen. */
    public function wantsLabel(): string
    {
        $labels = [
            'metadata' => 'the catalogue',
            'files' => 'the media files',
            'profiles' => 'profiles and history',
            'settings' => 'settings and keys',
        ];

        return collect($this->wants ?? [])
            ->map(fn (string $w) => $labels[$w] ?? $w)
            ->implode(', ') ?: 'nothing';
    }
}

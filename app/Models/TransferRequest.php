<?php

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

    /** Long enough to walk to the other machine, short enough not to linger. */
    public const LIFETIME_HOURS = 4;

    protected $hidden = ['plain_token'];

    protected $fillable = [
        'ip', 'device_name', 'platform', 'wants', 'code',
        'state', 'denied_reason', 'expires_at', 'approved_at', 'token_id', 'plain_token',
    ];

    protected function casts(): array
    {
        return [
            'wants' => 'array',
            'expires_at' => 'datetime',
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
        if ($this->state === self::PENDING && $this->expires_at->isPast()) {
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

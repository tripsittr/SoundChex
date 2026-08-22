<?php

namespace App\Services;

use App\Models\TransferRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Saying yes to another server.
 *
 * Approval is the security boundary — not the password on the page, and not
 * the token. Nothing can be read from this machine until a person here decides,
 * and what they decided stays revocable while it runs.
 */
class TransferApprovals
{
    /**
     * Approves a request and mints the token it will use.
     *
     * Scoped to one ability and tied to this request, so it can read the
     * manifest and the files and nothing else, and dies when the request does.
     */
    public function approve(TransferRequest $request, User $approver): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        $token = $approver->createToken(
            'transfer-' . $request->id,
            [TransferRequest::ABILITY],
            $request->expires_at,
        );

        $request->forceFill([
            'state' => TransferRequest::APPROVED,
            'approved_at' => now(),
            'token_id' => $token->accessToken->id,
            'plain_token' => $token->plainTextToken,
        ])->save();

        Log::warning('A server was approved to copy this one', [
            'request' => $request->id,
            'ip' => $request->ip,
            'wants' => $request->wants,
            'approved_by' => $approver->id,
        ]);

        return true;
    }

    public function deny(TransferRequest $request, string $reason = 'Denied'): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        $request->forceFill([
            'state' => TransferRequest::DENIED,
            'denied_reason' => $reason,
        ])->save();

        Log::info('A transfer request was denied', ['request' => $request->id, 'ip' => $request->ip]);

        return true;
    }

    /**
     * Stops a transfer that is already running.
     *
     * 46 GB takes hours, which is long enough to change your mind — and every
     * request the receiver makes checks the state, so this stops it rather
     * than merely marking it.
     */
    public function revoke(TransferRequest $request): void
    {
        $request->revoke();

        Log::warning('A running transfer was revoked', [
            'request' => $request->id,
            'ip' => $request->ip,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, TransferRequest> */
    public function pending()
    {
        return TransferRequest::where('state', TransferRequest::PENDING)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get();
    }

    /** Approved and still live, so they can be revoked from the same screen. */
    public function active()
    {
        return TransferRequest::where('state', TransferRequest::APPROVED)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get();
    }
}

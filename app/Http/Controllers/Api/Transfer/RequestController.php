<?php

namespace App\Http\Controllers\Api\Transfer;

use App\Http\Controllers\Controller;
use App\Models\TransferRequest;
use App\Services\TransferApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Asking a server for permission to copy it.
 *
 * The two endpoints here are the only unauthenticated writes in the
 * application, and they have to be: a request is *how* authentication is
 * obtained. They are rate-limited hard in routes/api.php, they store nothing
 * an attacker controls beyond a device name shown as untrusted, and they
 * give nothing away until a person on this machine approves.
 */
class RequestController extends Controller
{
    /**
     * Records a request and returns its code.
     *
     * Nothing about the library is disclosed here — not its size, not its
     * name, not whether it holds anything at all. An unapproved requester
     * learns only that a SoundChex server answered.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:60'],
            'platform' => ['nullable', 'string', 'max:120'],
            'wants' => ['required', 'array', 'min:1'],
            'wants.*' => ['string', 'in:metadata,files,profiles,settings'],
            // A secret the receiver makes up before there is anything to
            // steal, and presents later to collect its token. Optional so a
            // request made before this existed keeps working.
            'claim' => ['nullable', 'string', 'min:16', 'max:200'],
        ]);

        $transferRequest = TransferRequest::create([
            // From the connection, never from the body: a requester reporting
            // its own address would report whatever it liked.
            'ip' => $request->ip(),
            'device_name' => $data['device_name'] ?? null,
            'platform' => $data['platform'] ?? null,
            'wants' => array_values(array_unique($data['wants'])),
            'code' => TransferRequest::newCode(),
            'state' => TransferRequest::PENDING,
            'expires_at' => now()->addHours(TransferRequest::LIFETIME_HOURS),
            // Hashed: the plain value lives only on the machine that made it
            // up, so this column is worth nothing to anyone reading the
            // database.
            'claim_hash' => isset($data['claim']) ? hash('sha256', $data['claim']) : null,
        ]);

        Log::info('A server asked to copy this one', [
            'request' => $transferRequest->id,
            'ip' => $transferRequest->ip,
            'wants' => $transferRequest->wants,
        ]);

        return response()->json([
            'id' => $transferRequest->id,
            'code' => $transferRequest->code,
            'state' => 'pending',
            'expires_at' => $transferRequest->expires_at->toIso8601String(),
        ], 201);
    }

    /**
     * Where a request got to, and the token once it is approved.
     *
     * The receiver polls this. The token is returned every time rather than
     * once, because a receiver that loses it between the poll and the transfer
     * would otherwise have to ask a person to approve all over again.
     */
    public function show(Request $request, TransferRequest $transferRequest): JsonResponse
    {
        $state = $transferRequest->publicState();

        $body = ['id' => $transferRequest->id, 'state' => $state];

        if ($state === TransferRequest::APPROVED && $transferRequest->isUsable()
            && $this->claimMatches($request, $transferRequest)) {
            // Minted at approval and stored in plain text on the source, which
            // is the machine that issued it and can revoke it.
            $body['token'] = $transferRequest->plain_token;
            $body['expires_at'] = $transferRequest->expires_at->toIso8601String();
        }

        if ($state === TransferRequest::DENIED) {
            $body['reason'] = $transferRequest->denied_reason;
        }

        return response()->json($body);
    }

    /**
     * Whether this poller is the machine that opened the request.
     *
     * The endpoint cannot be authenticated — collecting the token is *how* a
     * receiver authenticates — so it was handing a live bearer token to
     * anyone who asked for the right id, over a Funnel address, with ids
     * running sequentially from 1. The token grants read of the whole
     * library.
     *
     * So the receiver proves itself with a secret it generated before there
     * was anything worth stealing. Compared in constant time against a hash,
     * so neither the value nor the time taken to reject it says anything.
     *
     * Default deny. A request that never proved itself never collects a
     * token — including one made before this column existed.
     *
     * The first version of this let those through, to avoid breaking a copy
     * that was running at the time. That exception protected exactly the
     * request that was leaking, and a test asserted it stayed that way, so
     * the change closed nothing. It cost nothing to remove either: `poll()`
     * has one caller, the admin page's button, and a running job uses the
     * token already on its row.
     */
    private function claimMatches(Request $request, TransferRequest $transferRequest): bool
    {
        if ($transferRequest->claim_hash === null) {
            return false;
        }

        $claim = $request->query('claim') ?? $request->input('claim');

        if (! is_string($claim) || $claim === '') {
            return false;
        }

        return hash_equals($transferRequest->claim_hash, hash('sha256', $claim));
    }

    /**
     * The receiver calling off its own transfer.
     *
     * Which request is being cancelled comes from the token, never from the
     * URL. A receiver holds a token for exactly one request, so it can end
     * that one and no other — otherwise cancelling would be a way to stop
     * somebody else's transfer by guessing an id, and the id is a small
     * integer.
     *
     * Cancelling destroys the token, so a second attempt with it is refused
     * at authentication rather than answered here. The receiver reads that
     * refusal as already-ended, which it is.
     */
    /**
     * The receiver saying how far it has got.
     *
     * The machine doing the copying is the only one that knows, and the
     * machine being copied had no way to ask. Both sides guessed instead: one
     * read tailnet byte counters and called a running transfer stalled, twice,
     * because the copy moves in bursts and a short sample lands in a gap; the
     * other reported a queue count it had changed by hand minutes earlier.
     *
     * Which request this is comes from the token, not the body, so a receiver
     * can only report its own — the same rule as `cancel()`. Nothing here is
     * trusted for anything but display: the worst a receiver can do is lie
     * about its own progress.
     */
    public function progress(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token?->can(TransferRequest::ABILITY), 403, 'Not a transfer token.');

        $transferRequest = TransferRequest::where('token_id', $token->id)->first();

        abort_unless($transferRequest !== null, 403, 'Not a transfer token.');

        $data = $request->validate([
            'items_total' => ['nullable', 'integer', 'min:0'],
            'items_complete' => ['nullable', 'integer', 'min:0'],
            'items_failed' => ['nullable', 'integer', 'min:0'],
            'items_skipped' => ['nullable', 'integer', 'min:0'],
            'items_pending' => ['nullable', 'integer', 'min:0'],
            'worker_alive' => ['nullable', 'boolean'],
            'bytes_complete' => ['nullable', 'integer', 'min:0'],
            'bytes_total' => ['nullable', 'integer', 'min:0'],
            'state' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $transferRequest->forceFill([
            'items_total' => $data['items_total'] ?? null,
            'items_complete' => $data['items_complete'] ?? null,
            'items_failed' => $data['items_failed'] ?? null,
            'items_skipped' => $data['items_skipped'] ?? null,
            'items_pending' => $data['items_pending'] ?? null,
            'bytes_complete' => $data['bytes_complete'] ?? null,
            'bytes_total' => $data['bytes_total'] ?? null,
            // A dead worker is indistinguishable from a stalled transfer
            // from outside, and cost half an hour today before anyone thought
            // to check. Reported rather than inferred.
            'worker_alive' => $data['worker_alive'] ?? null,
            'progress_state' => $data['state'] ?? null,
            'progress_note' => $data['note'] ?? null,
            'progress_at' => now(),
        ])->save();

        return response()->json(['recorded' => true]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token?->can(TransferRequest::ABILITY), 403, 'Not a transfer token.');

        $transferRequest = TransferRequest::where('token_id', $token->id)->first();

        // 403 rather than 404, matching SourceController::authorizeTransfer():
        // a token that belongs to no transfer learns that it is not a transfer
        // token, and nothing about which requests exist.
        abort_unless($transferRequest !== null, 403, 'Not a transfer token.');

        app(TransferApprovals::class)->cancelledByReceiver($transferRequest);

        return response()->json(['state' => TransferRequest::REVOKED]);
    }
}

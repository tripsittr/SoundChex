<?php

namespace App\Http\Controllers\Api\Transfer;

use App\Http\Controllers\Controller;
use App\Models\TransferRequest;
use App\Models\User;
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

        if ($state === TransferRequest::APPROVED && $transferRequest->isUsable()) {
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
}

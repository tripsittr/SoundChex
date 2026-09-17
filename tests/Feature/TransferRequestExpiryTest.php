<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\TransferRequest;
use App\Models\Transfer;
use App\Services\TransferApprovals;
use App\Services\TransferReceiver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a request says about itself once it has run out of time.
 *
 * `isUsable()` has always refused an expired request, so the source correctly
 * answered 401. But `publicState()` still called it "approved", so the receiver
 * polled, believed it, and asked again with a token that could never work —
 * which is precisely what the real transfer did after its four hours were up.
 *
 * A status that reads approved while every request is refused is worse than no
 * status at all.
 */
class TransferRequestExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expired_approval_reports_as_expired(): void
    {
        $request = $this->approved();

        $request->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame('expired', $request->fresh()->publicState());
    }

    public function test_a_live_approval_still_reports_as_approved(): void
    {
        $this->assertSame('approved', $this->approved()->publicState());
    }

    public function test_an_expired_pending_request_still_reports_as_expired(): void
    {
        $request = $this->pending();

        $request->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame('expired', $request->fresh()->publicState());
    }

    public function test_a_decision_someone_made_keeps_its_own_name(): void
    {
        // More use to whoever reads it than the clock running out.
        $denied = $this->pending();
        app(TransferApprovals::class)->deny($denied);
        $denied->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame('denied', $denied->fresh()->publicState());
    }

    public function test_the_receiver_stops_polling_an_expired_request(): void
    {
        // The whole point: the receiver reads this over the wire and must give
        // up rather than retry a token that cannot work.
        Http::fake(['*/transfer/requests/*' => Http::response(['id' => 3, 'state' => 'expired'])]);

        $transfer = Transfer::create([
            'source_url' => 'https://other.example',
            'remote_request_id' => 3,
            'wants' => ['files'],
            'state' => Transfer::REQUESTED,
        ]);

        $this->assertSame('expired', app(TransferReceiver::class)->poll($transfer));

        $transfer->refresh();

        $this->assertSame(Transfer::FAILED, $transfer->state);
        $this->assertStringContainsString('expired', $transfer->last_error);
    }

    private function pending(): TransferRequest
    {
        return TransferRequest::create([
            'ip' => '100.64.0.1',
            'wants' => ['files'],
            'code' => TransferRequest::newCode(),
            'state' => TransferRequest::PENDING,
            'expires_at' => now()->addHours(TransferRequest::LIFETIME_HOURS),
        ]);
    }

    private function approved(): TransferRequest
    {
        $request = $this->pending();

        app(TransferApprovals::class)->approve($request, User::factory()->create());

        return $request->fresh();
    }
}

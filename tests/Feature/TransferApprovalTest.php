<?php

namespace Tests\Feature;

use App\Models\TransferRequest;
use App\Models\User;
use App\Services\TransferApprovals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Asking another server for its library.
 *
 * The approval is the security boundary — not the password on the page and not
 * the token. A token copied between machines can be copied a third time and
 * exists whether anyone is watching; a request that must be approved while
 * someone is looking at it cannot be used by someone who is not.
 */
class TransferApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_starts_pending_and_yields_nothing(): void
    {
        $response = $this->postJson('/api/v1/transfer/requests', [
            'device_name' => 'Windows Box',
            'wants' => ['metadata', 'files'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('state', 'pending')
            // Nothing about the library is disclosed before approval.
            ->assertJsonMissingPath('token');

        $this->getJson('/api/v1/transfer/requests/' . $response->json('id'))
            ->assertOk()
            ->assertJsonPath('state', 'pending')
            ->assertJsonMissingPath('token');
    }

    public function test_the_manifest_is_refused_until_approved(): void
    {
        $this->getJson('/api/v1/transfer/manifest')->assertUnauthorized();
    }

    public function test_approving_yields_a_token_that_works(): void
    {
        $request = $this->pending();

        $this->assertTrue(app(TransferApprovals::class)->approve($request, User::factory()->create()));

        $token = $request->fresh()->plain_token;

        $this->assertNotNull($token);

        $this->withToken($token)->getJson('/api/v1/transfer/manifest')->assertOk();
    }

    public function test_a_denied_request_never_yields_a_token(): void
    {
        $request = $this->pending();

        app(TransferApprovals::class)->deny($request, 'Not this machine');

        $this->getJson('/api/v1/transfer/requests/' . $request->id)
            ->assertOk()
            ->assertJsonPath('state', 'denied')
            ->assertJsonMissingPath('token');
    }

    public function test_revoking_stops_a_transfer_that_is_already_running(): void
    {
        // 46 GB takes hours, which is long enough to change your mind — so
        // approval has to be revocable rather than final.
        $request = $this->pending();
        app(TransferApprovals::class)->approve($request, User::factory()->create());

        $token = $request->fresh()->plain_token;
        $this->withToken($token)->getJson('/api/v1/transfer/manifest')->assertOk();

        app(TransferApprovals::class)->revoke($request->fresh());

        // 401 or 403 depending on whether the token row is gone or merely
        // orphaned; what matters is that the library can no longer be read.
        $status = $this->withToken($token)->getJson('/api/v1/transfer/manifest')->status();

        $this->assertContains($status, [401, 403], 'A revoked transfer could still read the library.');
    }

    public function test_an_expired_request_cannot_be_approved_afterwards(): void
    {
        // An unapproved request left forever is a way in.
        $request = $this->pending();
        $request->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertFalse(app(TransferApprovals::class)->approve($request, User::factory()->create()));

        $this->getJson('/api/v1/transfer/requests/' . $request->id)
            ->assertJsonPath('state', 'expired');
    }

    public function test_a_token_cannot_read_what_its_request_did_not_ask_for(): void
    {
        // Asked for metadata only; the files endpoint is not part of the deal.
        $request = $this->pending(['metadata']);
        app(TransferApprovals::class)->approve($request, User::factory()->create());

        $this->withToken($request->fresh()->plain_token)
            ->getJson('/api/v1/transfer/profiles')
            ->assertForbidden();
    }

    public function test_an_ordinary_api_token_is_not_a_transfer_token(): void
    {
        // The library API and the transfer API are different privileges.
        $user = User::factory()->create();
        $token = $user->createToken('a phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/transfer/manifest')->assertForbidden();
    }

    /*
     * The compression that broke the first real transfer is covered by
     * tests/Unit/CatalogueArchiveTest.php, which calls the class the endpoint
     * calls. It is not exercised through the route here on purpose: the route
     * checkpoints the WAL, and that fights the harness's own transaction. The
     * accepted gap is the routing, which did not break; the compression, which
     * did, is covered against the real code.
     */

    public function test_a_receiver_can_cancel_its_own_transfer(): void
    {
        $request = $this->pending();

        $this->assertTrue(app(TransferApprovals::class)->approve($request, User::factory()->create()));

        $token = $request->fresh()->plain_token;
        $tokenId = $request->fresh()->token_id;

        $this->withToken($token)->deleteJson('/api/v1/transfer/requests/mine')->assertOk();

        // Ended, and the token destroyed with it — so cancelling is not merely
        // a label on a request that can still read this machine.
        $this->assertSame(TransferRequest::REVOKED, $request->fresh()->state);
        $this->assertNull($request->fresh()->token_id);
        $this->assertSame(0, PersonalAccessToken::where('id', $tokenId)->count());
    }

    public function test_cancelling_ends_only_the_caller_s_own_transfer(): void
    {
        // Otherwise cancelling would be a way to stop somebody else's transfer
        // by guessing an id, and the id is a small integer.
        $mine = $this->pending();
        $theirs = $this->pending();

        $approvals = app(TransferApprovals::class);
        $user = User::factory()->create();

        $this->assertTrue($approvals->approve($mine, $user));
        $this->assertTrue($approvals->approve($theirs, $user));

        $this->withToken($mine->fresh()->plain_token)
            ->deleteJson('/api/v1/transfer/requests/mine')
            ->assertOk();

        $this->assertSame(TransferRequest::REVOKED, $mine->fresh()->state);
        $this->assertSame(TransferRequest::APPROVED, $theirs->fresh()->state);
    }

    public function test_cancelling_is_refused_without_a_transfer_token(): void
    {
        $this->deleteJson('/api/v1/transfer/requests/mine')->assertUnauthorized();
    }

    public function test_an_ordinary_api_token_cannot_cancel_a_transfer(): void
    {
        $token = User::factory()->create()->createToken('phone')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/transfer/requests/mine')->assertForbidden();
    }

    public function test_a_receiver_can_report_its_progress(): void
    {
        $request = $this->pending();

        app(TransferApprovals::class)->approve($request, User::factory()->create());

        $this->withToken($request->fresh()->plain_token)
            ->postJson('/api/v1/transfer/progress', [
                'items_total' => 8309,
                'items_complete' => 65,
                'items_failed' => 5,
                'bytes_complete' => 375809638,
                'bytes_total' => 49712345678,
                'state' => 'running',
            ])
            ->assertOk();

        $request->refresh();

        $this->assertSame(8309, $request->items_total);
        $this->assertSame(65, $request->items_complete);
        $this->assertSame('running', $request->progress_state);
        $this->assertNotNull($request->progress_at, 'the time of the report is the signal that it is still alive');
    }

    public function test_an_expired_transfer_cannot_report_progress(): void
    {
        // Today this cannot happen: approval mints the Sanctum token with the
        // same expiry it writes to the row, so both die together. This forces
        // the row past its expiry while the token still stands — the state the
        // app would reach if those two ever stopped sharing one value — and
        // asserts the endpoint refuses it on its own account rather than
        // relying on the token to have died.
        $request = $this->pending();

        app(TransferApprovals::class)->approve($request, User::factory()->create());

        $token = $request->fresh()->plain_token;

        $request->fresh()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($token)
            ->postJson('/api/v1/transfer/progress', ['items_complete' => 1])
            ->assertForbidden();
    }

    public function test_progress_needs_a_transfer_token(): void
    {
        // An ordinary API token must not be able to write here: it would let
        // any signed-in device rewrite what the page reports about a copy.
        $token = User::factory()->create()->createToken('phone')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/transfer/progress', ['items_complete' => 1])
            ->assertForbidden();
    }

    public function test_a_receiver_can_only_report_its_own_progress(): void
    {
        // Which request this is comes from the token, never the body — the
        // same rule as cancel(). A receiver cannot report about another.
        $mine = $this->pending();
        $theirs = $this->pending();

        app(TransferApprovals::class)->approve($mine, User::factory()->create());

        $this->withToken($mine->fresh()->plain_token)
            ->postJson('/api/v1/transfer/progress', [
                'items_complete' => 42,
                'id' => $theirs->id,
            ])
            ->assertOk();

        $this->assertSame(42, $mine->fresh()->items_complete);
        $this->assertNull($theirs->fresh()->items_complete);
    }

    public function test_a_files_only_transfer_can_read_the_manifest(): void
    {
        // The manifest *is* the list of files. Gating it behind `metadata`
        // alone made `wants: ['files']` unusable by construction: the transfer
        // authenticated, asked what to fetch, and was refused 403. A real
        // request hit this — nothing rejects the combination at request time.
        $request = $this->pending(['files']);

        app(TransferApprovals::class)->approve($request, User::factory()->create());

        $token = $request->fresh()->plain_token;

        $this->withToken($token)->getJson('/api/v1/transfer/manifest')->assertOk();
    }

    public function test_a_files_only_transfer_still_cannot_read_the_catalogue(): void
    {
        // Widening the manifest must not widen the database dump with it.
        $request = $this->pending(['files']);

        app(TransferApprovals::class)->approve($request, User::factory()->create());

        $this->withToken($request->fresh()->plain_token)
            ->get('/api/v1/transfer/database')
            ->assertForbidden();
    }

    public function test_the_approval_window_runs_from_approval_not_from_the_request(): void
    {
        // A request that has already sat for most of its life. The old code
        // handed the token whatever was left of that, so an approval given
        // late was already nearly dead — and a 46 GB copy does not finish in
        // the remainder. Four real transfers died this way.
        $request = $this->pending();
        $request->forceFill(['expires_at' => now()->addMinutes(5)])->save();

        app(TransferApprovals::class)->approve($request->fresh(), User::factory()->create());

        $request->refresh();

        $this->assertTrue(
            $request->expires_at->gt(now()->addHours(TransferRequest::APPROVAL_HOURS - 1)),
            'the approval should be good for hours after it was given, not minutes',
        );
        $this->assertTrue($request->isUsable(), 'a freshly approved request must be usable');
    }

    private function pending(array $wants = ['metadata', 'files', 'profiles']): TransferRequest
    {
        return TransferRequest::create([
            'ip' => '10.0.0.5',
            'device_name' => 'Test',
            'wants' => $wants,
            'code' => TransferRequest::newCode(),
            'state' => TransferRequest::PENDING,
            'expires_at' => now()->addHours(4),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\TransferRequest;
use App\Models\User;
use App\Services\TransferApprovals;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

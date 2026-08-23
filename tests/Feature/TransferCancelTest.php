<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\TransferReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Calling a transfer off from the machine doing the copying.
 *
 * Pausing was the only way to stop one from this end, and pausing leaves the
 * request approved and the token live on the other machine — so a transfer
 * paused and forgotten leaves another server able to read this one until the
 * token lapses. Cancelling has to end it at both ends, and has to end it here
 * even when the other end cannot be reached.
 */
class TransferCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_stops_it_here_and_tells_the_source(): void
    {
        Http::fake(['*/transfer/requests/mine' => Http::response(['state' => 'revoked'])]);

        $transfer = $this->transfer(['token' => 'live-token']);

        $this->assertTrue(app(TransferReceiver::class)->cancel($transfer));

        $transfer->refresh();

        $this->assertSame(Transfer::CANCELLED, $transfer->state);
        $this->assertNotNull($transfer->finished_at);

        // The token is gone from this machine as well as the other, so a
        // cancelled transfer cannot resume by accident.
        $this->assertNull($transfer->token);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/v1/transfer/requests/mine'));
    }

    public function test_it_is_cancelled_here_even_when_the_source_cannot_be_reached(): void
    {
        // The local stop is the one that matters. A source that is asleep must
        // not leave this machine still transferring.
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $transfer = $this->transfer(['token' => 'live-token']);

        $this->assertFalse(app(TransferReceiver::class)->cancel($transfer));

        $transfer->refresh();

        $this->assertSame(Transfer::CANCELLED, $transfer->state);
        $this->assertStringContainsString('could not be told', $transfer->last_error);
    }

    public function test_a_dead_token_counts_as_already_ended(): void
    {
        // Revoked from the other end, or expired. The request is over either
        // way, which is what was being asked for.
        Http::fake(['*/transfer/requests/mine' => Http::response('', 401)]);

        $transfer = $this->transfer(['token' => 'stale-token']);

        $this->assertTrue(app(TransferReceiver::class)->cancel($transfer));
        $this->assertSame(Transfer::CANCELLED, $transfer->fresh()->state);
    }

    public function test_cancelling_before_approval_asks_the_source_for_nothing(): void
    {
        // Never approved, so there is no token and nothing over there to end.
        // It expires on its own, unusable in the meantime.
        Http::fake();

        $transfer = $this->transfer(['token' => null, 'state' => Transfer::REQUESTED]);

        $this->assertTrue(app(TransferReceiver::class)->cancel($transfer));
        $this->assertSame(Transfer::CANCELLED, $transfer->fresh()->state);

        Http::assertNothingSent();
    }

    public function test_a_cancelled_transfer_stops_the_queue(): void
    {
        // The state is what queued file jobs read to decide whether to carry
        // on, so cancelling has to mean the queue drains rather than working
        // through what is already enqueued.
        Http::fake(['*/transfer/requests/mine' => Http::response(['state' => 'revoked'])]);

        $transfer = $this->transfer(['token' => 'live-token']);

        app(TransferReceiver::class)->cancel($transfer);

        $this->assertNotContains(
            $transfer->fresh()->state,
            [Transfer::RUNNING, Transfer::APPROVED],
            'A cancelled transfer is still in a state TransferFileJob will act on.',
        );
    }

    /* ------------------------------------------------------- deleting ---- */

    public function test_a_deleted_transfer_takes_its_items_with_it(): void
    {
        $transfer = $this->transfer(['state' => Transfer::COMPLETE]);

        TransferItem::create([
            'transfer_id' => $transfer->id,
            'remote_id' => 1,
            'path' => 'music/a.mp3',
            'state' => TransferItem::COMPLETE,
        ]);

        $this->assertTrue(app(TransferReceiver::class)->discard($transfer));

        $this->assertNull(Transfer::find($transfer->id));
        $this->assertSame(0, TransferItem::where('transfer_id', $transfer->id)->count());
    }

    public function test_a_running_transfer_cannot_be_deleted(): void
    {
        // Clearing the list must not be a way to abandon a transfer half way:
        // the row is what the queued jobs read, and deleting it under them
        // leaves them fetching into a transfer that no longer exists.
        $transfer = $this->transfer(['state' => Transfer::RUNNING]);

        $this->assertFalse(app(TransferReceiver::class)->discard($transfer));
        $this->assertNotNull(Transfer::find($transfer->id));
    }

    public function test_a_cancelled_transfer_can_then_be_deleted(): void
    {
        // The route out of the refusal above, and the one the page points at.
        Http::fake(['*/transfer/requests/mine' => Http::response(['state' => 'revoked'])]);

        $transfer = $this->transfer(['token' => 'live-token']);

        app(TransferReceiver::class)->cancel($transfer);

        $this->assertTrue(app(TransferReceiver::class)->discard($transfer->fresh()));
        $this->assertNull(Transfer::find($transfer->id));
    }

    private function transfer(array $attributes = []): Transfer
    {
        return Transfer::create(array_merge([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['metadata', 'files'],
            'state' => Transfer::RUNNING,
        ], $attributes));
    }
}

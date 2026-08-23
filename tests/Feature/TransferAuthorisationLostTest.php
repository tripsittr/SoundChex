<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\TransferReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A transfer whose permission has gone should stop, not grind.
 *
 * Transfer tokens last four hours from approval. When one expires mid-copy
 * every remaining file answers 401 — and the receiver treated each as that
 * one file failing, so a real transfer sat retrying a dead token through 6,964
 * items that could never arrive, reporting itself as running the whole time.
 */
class TransferAuthorisationLostTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_401_ends_the_transfer_rather_than_one_file(): void
    {
        Http::fake(['*/transfer/file/*' => Http::response('', 401)]);

        $transfer = $this->transfer();
        $item = $this->item($transfer);

        $this->assertFalse(app(TransferReceiver::class)->fetch($item));

        $transfer->refresh();

        $this->assertSame(Transfer::FAILED, $transfer->state);
        $this->assertStringContainsString('no longer approved', $transfer->last_error);
    }

    public function test_a_403_ends_it_too(): void
    {
        // Revoked at the other end rather than expired. Same conclusion: a
        // person has to approve it again.
        Http::fake(['*/transfer/file/*' => Http::response('', 403)]);

        $transfer = $this->transfer();

        $this->assertFalse(app(TransferReceiver::class)->fetch($this->item($transfer)));
        $this->assertSame(Transfer::FAILED, $transfer->fresh()->state);
    }

    public function test_an_ordinary_failure_still_only_fails_that_file(): void
    {
        // A missing file, a truncated response, a server hiccup — these are
        // about one file, and stopping the whole transfer over one would be a
        // far worse bug than the one being fixed.
        Http::fake(['*/transfer/file/*' => Http::response('', 404)]);

        $transfer = $this->transfer();
        $item = $this->item($transfer);

        $this->assertFalse(app(TransferReceiver::class)->fetch($item));

        $this->assertSame(Transfer::RUNNING, $transfer->fresh()->state);
        $this->assertSame(TransferItem::FAILED, $item->fresh()->state);
    }

    public function test_what_has_already_arrived_is_kept(): void
    {
        // Resuming after a fresh approval should carry on rather than start
        // over. 46 GB is not something to fetch twice.
        Http::fake(['*/transfer/file/*' => Http::response('', 401)]);

        $transfer = $this->transfer();

        $done = TransferItem::create([
            'transfer_id' => $transfer->id,
            'remote_id' => 1,
            'path' => 'media/library/already-here.mp3',
            'state' => TransferItem::COMPLETE,
        ]);

        app(TransferReceiver::class)->fetch($this->item($transfer));

        $this->assertSame(TransferItem::COMPLETE, $done->fresh()->state);
    }

    private function transfer(): Transfer
    {
        return Transfer::create([
            'source_url' => 'https://other.example',
            'token' => 'expired-token',
            'wants' => ['files'],
            'state' => Transfer::RUNNING,
        ]);
    }

    private function item(Transfer $transfer): TransferItem
    {
        Storage::fake('local');

        return TransferItem::create([
            'transfer_id' => $transfer->id,
            'remote_id' => 42,
            'path' => 'media/library/Music/Wanted.mp3',
            'expected_bytes' => 10,
            'state' => TransferItem::PENDING,
        ]);
    }
}

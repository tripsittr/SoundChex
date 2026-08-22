<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\TransferReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Receiving a library from another server.
 *
 * This writes the user's media to disk, so the failures that matter are the
 * ones that leave something wrong in place: a truncated film that looks like a
 * film is worse than no film, because nothing will ever tell you it is wrong.
 */
class TransferReceiverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_that_is_already_here_is_not_fetched_again(): void
    {
        // What makes a resumed transfer cheap and a repeated one free.
        Storage::disk('local')->put('media/library/song.mp3', 'the real bytes');

        $item = $this->item([
            'expected_hash' => hash('xxh128', 'the real bytes'),
            'expected_bytes' => 14,
        ]);

        Http::fake();

        $this->assertTrue(app(TransferReceiver::class)->fetch($item));
        $this->assertSame(TransferItem::SKIPPED, $item->fresh()->state);

        // Nothing crossed the network.
        Http::assertNothingSent();
    }

    public function test_a_file_that_arrives_correct_is_placed(): void
    {
        $item = $this->item([
            'expected_hash' => hash('xxh128', 'the real bytes'),
            'expected_bytes' => 14,
        ]);

        Http::fake(['*/transfer/file/*' => Http::response('the real bytes')]);

        $this->assertTrue(app(TransferReceiver::class)->fetch($item));
        $this->assertSame(TransferItem::COMPLETE, $item->fresh()->state);

        Storage::disk('local')->assertExists('media/library/song.mp3');
        $this->assertSame('the real bytes', Storage::disk('local')->get('media/library/song.mp3'));
    }

    public function test_a_corrupt_file_is_deleted_rather_than_kept(): void
    {
        // The failure this whole design exists for.
        $item = $this->item([
            'expected_hash' => hash('xxh128', 'the real bytes'),
            'expected_bytes' => 14,
        ]);

        // Deliberately the *same length* as the real thing, so only the hash
        // can tell them apart. An earlier version of this test used shorter
        // bytes, which meant the size check caught it and the hash check was
        // never exercised at all.
        Http::fake(['*/transfer/file/*' => Http::response('the fake bytes')]);

        $this->assertFalse(app(TransferReceiver::class)->fetch($item));

        $item->refresh();
        $this->assertSame(TransferItem::FAILED, $item->state);
        $this->assertNotNull($item->failure_reason);

        // Nothing left behind that could be mistaken for the real file.
        Storage::disk('local')->assertMissing('media/library/song.mp3');
        Storage::disk('local')->assertMissing('media/library/song.mp3.part');
    }

    public function test_a_short_file_is_caught_even_without_a_hash(): void
    {
        // 16% of this library has no content_hash. Size is weaker but it still
        // catches a half-copied file.
        $item = $this->item(['expected_hash' => null, 'expected_bytes' => 14]);

        Http::fake(['*/transfer/file/*' => Http::response('short')]);

        $this->assertFalse(app(TransferReceiver::class)->fetch($item));
        $this->assertStringContainsString('Short', $item->fresh()->failure_reason);
    }

    public function test_a_failure_records_why_and_does_not_stop_the_rest(): void
    {
        $transfer = $this->transfer();

        $good = $this->item(['expected_bytes' => 14, 'expected_hash' => hash('xxh128', 'the real bytes')], $transfer);
        $bad = $this->item(['path' => 'media/library/other.mp3', 'remote_id' => 2, 'expected_bytes' => 5], $transfer);

        Http::fake([
            '*/transfer/file/1' => Http::response('the real bytes'),
            '*/transfer/file/2' => Http::response('', 500),
        ]);

        app(TransferReceiver::class)->fetch($good);
        app(TransferReceiver::class)->fetch($bad);

        $this->assertSame(TransferItem::COMPLETE, $good->fresh()->state);
        $this->assertSame(TransferItem::FAILED, $bad->fresh()->state);
        $this->assertStringContainsString('500', $bad->fresh()->failure_reason);
    }

    public function test_what_is_left_is_what_resumes(): void
    {
        // The bookmark is not a separate thing: an interrupted transfer asks
        // the same question it asked at the start.
        $transfer = $this->transfer();

        $this->item(['remote_id' => 1], $transfer)->forceFill(['state' => TransferItem::COMPLETE])->save();
        $this->item(['remote_id' => 2, 'path' => 'media/library/b.mp3'], $transfer);
        $this->item(['remote_id' => 3, 'path' => 'media/library/c.mp3'], $transfer);

        $this->assertSame(2, $transfer->remaining()->count());
    }

    public function test_progress_counts_a_skipped_file_as_done(): void
    {
        // It is present and correct, which is the only thing anyone cares about.
        $transfer = $this->transfer();

        $this->item(['remote_id' => 1, 'expected_bytes' => 100], $transfer)
            ->forceFill(['state' => TransferItem::SKIPPED])->save();

        $this->assertSame(100, $transfer->progress()['done_bytes']);
    }

    /* --------------------------------------------------------- helpers --- */

    private function transfer(): Transfer
    {
        return Transfer::create([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['metadata', 'files'],
            'state' => Transfer::RUNNING,
        ]);
    }

    private function item(array $attributes = [], ?Transfer $transfer = null): TransferItem
    {
        return TransferItem::create([
            'transfer_id' => ($transfer ?? $this->transfer())->id,
            'remote_id' => $attributes['remote_id'] ?? 1,
            'path' => $attributes['path'] ?? 'media/library/song.mp3',
            'expected_hash' => $attributes['expected_hash'] ?? null,
            'expected_bytes' => $attributes['expected_bytes'] ?? 0,
            'state' => TransferItem::PENDING,
        ]);
    }
}

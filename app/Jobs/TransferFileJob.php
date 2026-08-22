<?php

namespace App\Jobs;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\TransferReceiver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One file of a transfer.
 *
 * One job per file rather than one for the lot: 8,315 files is hours, and a
 * single job holding all of it would lose everything to one dropped
 * connection. Each file is its own unit of work, its own retry and its own
 * record of what went wrong.
 */
class TransferFileJob implements ShouldQueue
{
    use Queueable;

    /** Retries are counted on the item, so the queue does not also count them. */
    public int $tries = 1;

    public function __construct(public int $itemId) {}

    public function handle(TransferReceiver $receiver): void
    {
        $item = TransferItem::with('transfer')->find($this->itemId);

        if ($item === null || $item->state === TransferItem::COMPLETE) {
            return;
        }

        $transfer = $item->transfer;

        // Paused or revoked while this was waiting its turn. 46 GB runs for
        // hours, and stopping has to mean the queue drains rather than
        // carrying on through what is already enqueued.
        if (! in_array($transfer->state, [Transfer::RUNNING, Transfer::APPROVED], true)) {
            return;
        }

        if ($receiver->fetch($item)) {
            return;
        }

        // Failed. Worth another go unless it has had its share.
        if ($item->canRetry()) {
            $item->forceFill(['state' => TransferItem::PENDING])->save();

            self::dispatch($item->id)->delay(now()->addSeconds(20 * $item->attempts));
        }
    }
}

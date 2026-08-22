<?php

namespace App\Jobs;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\TransferReceiver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Starts or resumes a transfer.
 *
 * Resuming and starting are the same operation. Anything not already complete
 * is what is left to do, so an interrupted transfer picks up by asking exactly
 * the question it asked at the beginning — there is no separate bookmark to
 * keep in step, and nothing to be wrong.
 */
class RunTransferJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** How many files are in flight at once. */
    private const CONCURRENCY = 3;

    public function __construct(public int $transferId) {}

    public function handle(TransferReceiver $receiver): void
    {
        $transfer = Transfer::find($this->transferId);

        if ($transfer === null || $transfer->state === Transfer::COMPLETE) {
            return;
        }

        if ($transfer->state === Transfer::PAUSED) {
            return;
        }

        // First run: fetch the manifest before anything else, so the work is
        // written down before any of it is attempted.
        if ($transfer->items()->count() === 0) {
            if (! $receiver->buildManifest($transfer)) {
                $transfer->forceFill([
                    'state' => Transfer::FAILED,
                    'last_error' => $transfer->last_error ?: 'Could not read the manifest.',
                ])->save();

                return;
            }

            Log::info('A transfer was planned', [
                'transfer' => $transfer->id,
                'files' => $transfer->total_files,
                'gb' => round($transfer->total_bytes / 1073741824, 1),
            ]);
        }

        $transfer->forceFill([
            'state' => Transfer::RUNNING,
            'started_at' => $transfer->started_at ?? now(),
        ])->save();

        // Only files. Metadata and profiles arrive as a database import, which
        // is one file and atomic.
        if (! $transfer->wants('files')) {
            $this->finish($transfer);

            return;
        }

        $next = $transfer->remaining()->limit(self::CONCURRENCY)->pluck('id');

        if ($next->isEmpty()) {
            $this->finish($transfer);

            return;
        }

        $next->each(fn (int $id) => TransferFileJob::dispatch($id));

        // Comes back to queue the next few once these are done. A self-
        // rescheduling job rather than one long loop, so a restart mid-transfer
        // loses one file rather than all of them.
        self::dispatch($transfer->id)->delay(now()->addSeconds(10));
    }

    private function finish(Transfer $transfer): void
    {
        $progress = $transfer->progress();

        $transfer->forceFill([
            'state' => Transfer::COMPLETE,
            'finished_at' => now(),
        ])->save();

        Log::info('A transfer finished', [
            'transfer' => $transfer->id,
            'complete' => $progress['complete'],
            'skipped' => $progress['skipped'],
            'failed' => $progress['failed'],
        ]);
    }
}

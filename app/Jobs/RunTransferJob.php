<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Events\TransferCompleted;
use App\Events\TransferFailed;
use App\Models\Transfer;
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

        // Cancelled while this was waiting its turn. The token is gone and the
        // source has already been told, so there is nothing left to ask for.
        if ($transfer->state === Transfer::CANCELLED) {
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

                TransferFailed::dispatch($transfer, $transfer->last_error);

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

        // The catalogue first, and only once. It replaces this machine's
        // database wholesale, so doing it after the files would overwrite the
        // rows recording what just arrived.
        if ($transfer->wants('metadata') && ! $transfer->metadata_imported) {
            if (! $receiver->importDatabase($transfer)) {
                $transfer->forceFill(['state' => Transfer::FAILED])->save();

                TransferFailed::dispatch($transfer, $transfer->last_error);

                return;
            }

            // Written with a raw update: the model's own row is in the database
            // that was just replaced, so anything read before this point is
            // stale.
            \DB::table('transfers')->where('id', $transfer->id)->update([
                'metadata_imported' => true,
            ]);

            $id = $transfer->id;
            $transfer = Transfer::find($id);

            // The import replaces this database, and the transfer's own row
            // lives in it. TransferReceiver carries the row back across, so
            // this should not happen — but "should not" and a fatal
            // `wants() on null` two lines later are not the same thing, and
            // the catalogue is already in place by now.
            if ($transfer === null) {
                Log::error('A transfer vanished with the catalogue it imported', ['transfer' => $id]);

                return;
            }
        }

        if (! $transfer->wants('files')) {
            $this->finish($transfer);

            return;
        }

        // Told to the machine being copied, every pass. It has no other way
        // to know: both sides guessed at this and both were wrong, one reading
        // byte counters that go quiet between files, the other a queue count
        // it had just changed by hand. Failing to report never stops a copy.
        $receiver->reportProgress($transfer);

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

        TransferCompleted::dispatch($transfer);

        Log::info('A transfer finished', [
            'transfer' => $transfer->id,
            'complete' => $progress['complete'],
            'skipped' => $progress['skipped'],
            'failed' => $progress['failed'],
        ]);
    }
}

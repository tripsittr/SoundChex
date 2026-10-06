<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Services\FileMoveJournal;
use Illuminate\Support\Facades\Log;

/**
 * Finds work the pipeline lost, and makes it happen (#465).
 *
 * The guarantee this delivers: **no item stays invisible and idle.** Before
 * this, a crash between cataloguing a row and dispatching its job left the row
 * hidden forever — `ResolvedScope` hides anything that is not `complete`, so
 * the item was absent from the library *and* absent from review, with nothing
 * anywhere that would ever look at it again. The audit found seven distinct
 * ways to reach that state.
 *
 * It replaces the release-all-reserved-jobs-on-boot hack in
 * `AppServiceProvider`, which only worked with exactly one worker: it released
 * jobs other workers were *actively running*, so a second worker meant the same
 * job ran twice. Requeueing by stage timeout is safe with any number, because
 * every stage is idempotent and `claim()` is atomic.
 */
class PipelineSweeper
{
    public function __construct(
        private PipelineRunner $runner,
        private FileMoveJournal $journal,
    ) {}

    /**
     * One pass.
     *
     * @return array<string, int> What it found, for the command and the log.
     */
    public function sweep(): array
    {
        return [
            'stalled' => $this->requeueStalled(),
            'queued' => $this->redispatchQueued(),
            'retryable' => $this->retryFailed(),
            'started_moves' => $this->reconcileMoves(),
            'stranded' => $this->adoptStranded(),
        ];
    }

    /**
     * Items whose stage has been `running` longer than that stage allows.
     *
     * Either the worker died or the work genuinely hung. Both are answered the
     * same way: run the stage again. Safe because stages are idempotent, and
     * the attempt counter means a stage that hangs repeatedly ends up parked
     * for a person rather than looping forever.
     */
    private function requeueStalled(): int
    {
        $swept = 0;

        $running = MediaItem::withoutGlobalScopes()
            ->where('pipeline_state', PipelineState::Running->value)
            ->whereNotNull('pipeline_stage')
            ->get(['id', 'pipeline_stage', 'pipeline_state', 'pipeline_attempts', 'pipeline_updated_at']);

        foreach ($running as $item) {
            $stage = $item->pipeline_stage;

            if (! $stage instanceof PipelineStage) {
                continue;
            }

            $updated = $item->pipeline_updated_at;

            // A null timestamp means a backfilled row, which has never been
            // touched by the pipeline and so cannot be mid-run.
            if ($updated !== null && $updated->diffInSeconds(now()) < $stage->timeoutSeconds()) {
                continue;
            }

            Log::info('Requeueing a stalled pipeline stage', [
                'item' => $item->id,
                'stage' => $stage->value,
                'running_for' => $updated?->diffInSeconds(now()),
            ]);

            $this->runner->retry($item, $stage, 'the stage stopped responding and was requeued');

            $swept++;
        }

        return $swept;
    }

    /**
     * Items marked `queued` that nothing is going to pick up.
     *
     * A job can be lost — a worker killed between reserving and running, a
     * queue flushed by hand, a `queue:restart` mid-flight. The item is left
     * saying "queued" with no job behind it, and waits forever.
     *
     * Only items untouched for a while, so a freshly dispatched job is not
     * dispatched twice; `claim()` makes a double-dispatch harmless anyway.
     */
    private function redispatchQueued(): int
    {
        $swept = 0;

        $queued = MediaItem::withoutGlobalScopes()
            ->where('pipeline_state', PipelineState::Queued->value)
            ->whereNotNull('pipeline_stage')
            ->where(function ($query): void {
                $query->whereNull('pipeline_updated_at')
                    ->orWhere('pipeline_updated_at', '<', now()->subMinutes(15));
            })
            ->limit(500)
            ->get(['id', 'pipeline_stage', 'pipeline_state', 'pipeline_attempts']);

        foreach ($queued as $item) {
            $stage = $item->pipeline_stage;

            if (! $stage instanceof PipelineStage) {
                continue;
            }

            $this->runner->dispatch($item, $stage);

            $swept++;
        }

        return $swept;
    }

    /**
     * Failed stages with attempts left.
     *
     * A stage marked `failed` is not necessarily finished with: the cause may
     * have been a provider outage or a locked file. `retry()` parks it once the
     * attempts are spent, so nothing loops.
     */
    private function retryFailed(): int
    {
        $swept = 0;

        $failed = MediaItem::withoutGlobalScopes()
            ->where('pipeline_state', PipelineState::Failed->value)
            ->where('pipeline_attempts', '<', PipelineRunner::MAX_ATTEMPTS)
            ->whereNotNull('pipeline_stage')
            ->where(function ($query): void {
                $query->whereNull('pipeline_updated_at')
                    ->orWhere('pipeline_updated_at', '<', now()->subMinutes(10));
            })
            ->limit(200)
            ->get(['id', 'pipeline_stage', 'pipeline_state', 'pipeline_attempts', 'pipeline_error']);

        foreach ($failed as $item) {
            $stage = $item->pipeline_stage;

            if (! $stage instanceof PipelineStage) {
                continue;
            }

            $this->runner->retry($item, $stage, (string) ($item->pipeline_error ?: 'retrying after a failure'));

            $swept++;
        }

        return $swept;
    }

    /** Resolves any file move left mid-flight by a crash. */
    private function reconcileMoves(): int
    {
        $outcome = $this->journal->reconcile();

        if (array_sum($outcome) > 0) {
            Log::info('Reconciled interrupted file moves', $outcome);
        }

        return $outcome['finished'] + $outcome['replanned'];
    }

    /**
     * Items that are hidden from the library and in no pipeline at all.
     *
     * The state the whole phase exists to abolish: `processing_status` is not
     * `complete`, so clients cannot see the item, and there is no stage, so
     * nothing will ever process it. Rows left by the old one-job design, by a
     * crash before this phase, or by an import path that never dispatched.
     *
     * Adopted at the first stage rather than guessed at: re-running a hash or
     * an identification is cheap and idempotent, and guessing a later stage
     * risks filing something that was never identified.
     */
    private function adoptStranded(): int
    {
        $stranded = MediaItem::withoutGlobalScopes()
            ->whereNull('pipeline_stage')
            ->whereIn('processing_status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Processing->value,
                ProcessingStatus::Failed->value,
            ])
            ->limit(500)
            ->get(['id']);

        foreach ($stranded as $item) {
            Log::info('Adopting a stranded item into the pipeline', ['item' => $item->id]);

            $this->runner->start($item);
        }

        return $stranded->count();
    }

    /**
     * The health question: how many items are invisible with nothing owed?
     *
     * Must be zero. `server:health` asserts it, which turns the guarantee into
     * something checked continuously rather than believed.
     */
    public function strandedCount(): int
    {
        return MediaItem::withoutGlobalScopes()
            ->where('processing_status', '!=', ProcessingStatus::Complete->value)
            ->where(function ($query): void {
                // No stage at all, or a stage that nothing will advance.
                $query->whereNull('pipeline_stage')
                    ->orWhereNull('pipeline_state');
            })
            ->count();
    }
}

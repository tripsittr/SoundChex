<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs\Pipeline;

use App\Enums\PipelineStage;
use App\Models\MediaItem;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Pipeline\StageRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs one pipeline stage for one item (#465).
 *
 * One job class rather than ten, because the differences between stages are
 * entirely in what they *do* — the claiming, the outcome handling, the retry
 * counting and the "item vanished" case are identical, and ten copies of that
 * is ten places for them to drift apart.
 *
 * `tries = 1` deliberately. Retrying is the pipeline's own decision, made by
 * `PipelineRunner::retry()` with a backoff and a per-stage attempt count, and
 * recorded on the item where the sweeper and the review queue can see it.
 * Laravel's own retry would do none of that and would double the attempts.
 */
class RunPipelineStageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $mediaItemId,
        public readonly PipelineStage $stage,
    ) {}

    public function handle(PipelineRunner $runner, StageRegistry $stages): void
    {
        $item = MediaItem::withoutGlobalScopes()
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
            ->find($this->mediaItemId);

        // Gone -- merged away as a duplicate, or deleted. Nothing to do, and
        // not a failure (#479).
        if ($item === null) {
            return;
        }

        // Another worker has this stage, or the item has already moved past it
        // (a duplicate dispatch, which a sweeper plus a chain can produce).
        if (! $runner->claim($item, $this->stage)) {
            return;
        }

        $handler = $stages->for($this->stage);

        try {
            $outcome = $handler->run($item->fresh(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata']));
        } catch (\Throwable $e) {
            report($e);

            // An exception is not automatically fatal: a timeout or a locked
            // file is worth another go, and `retry()` parks it once the
            // attempts are spent rather than losing it.
            $runner->retry($item->fresh(), $this->stage, class_basename($e).': '.$e->getMessage());

            return;
        }

        $this->apply($runner, $item->fresh(), $outcome);
    }

    /** Applies a handler's verdict to the item. */
    private function apply(PipelineRunner $runner, MediaItem $item, StageOutcome $outcome): void
    {
        match ($outcome->result) {
            StageResult::Done, StageResult::Skipped => $runner->advance($item, $this->stage),
            StageResult::NeedsReview => $runner->park($item, $this->stage, $outcome->reason ?? 'needs a look'),
            StageResult::Retry => $runner->retry($item, $this->stage, $outcome->reason ?? 'retrying'),
            StageResult::Failed => $runner->fail($item, $this->stage, $outcome->reason ?? 'failed'),
        };
    }

    /**
     * Called when the job itself dies — a worker killed, the process timing out
     * at the queue level.
     *
     * The item is left `running` with a stale timestamp, which is exactly what
     * the sweeper looks for, so this only has to say so.
     */
    public function failed(?\Throwable $e): void
    {
        Log::warning('A pipeline stage job died', [
            'item' => $this->mediaItemId,
            'stage' => $this->stage->value,
            'error' => $e?->getMessage(),
            'note' => 'the sweeper will requeue it once the stage timeout passes',
        ]);
    }

    /** Keeps one stage per item out of the queue twice over. */
    public function uniqueId(): string
    {
        return $this->mediaItemId.':'.$this->stage->value;
    }
}

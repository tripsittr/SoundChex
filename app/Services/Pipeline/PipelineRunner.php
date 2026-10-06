<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Jobs\Pipeline\RunPipelineStageJob;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;

/**
 * Moves items from one pipeline stage to the next (#465).
 *
 * Every transition goes through here rather than being written at each call
 * site, because the interesting part is not the stage name — it is that
 * `processing_status` (what clients read), the stage, the state and the
 * timestamp stay consistent with each other. Four columns written in four
 * places is how they drifted apart in the first place.
 *
 * The contract each stage job keeps:
 *
 *  - `claim()` before working. Returns false when another worker has it.
 *  - exactly one of `advance()`, `park()`, `retry()` or `fail()` afterwards.
 *
 * Nothing here dispatches work inside a transaction: a job picked up before its
 * transaction commits finds no row, which is a race that looks exactly like a
 * lost job.
 */
class PipelineRunner
{
    /**
     * How many times a stage may be retried before it is parked for a person.
     *
     * Three because the failures worth retrying are transient — a provider
     * blipping, a lock, a disk briefly full — and anything that fails three
     * times is a real problem that a fourth attempt will not fix.
     */
    public const MAX_ATTEMPTS = 3;

    /** Puts a freshly catalogued item into the pipeline. */
    public function start(MediaItem $item): void
    {
        $item->forceFill([
            'pipeline_stage' => PipelineStage::Catalogued,
            'pipeline_state' => PipelineState::Queued,
            'pipeline_attempts' => 0,
            'pipeline_error' => null,
            'pipeline_updated_at' => now(),
        ])->saveQuietly();

        $this->dispatch($item, PipelineStage::Catalogued);
    }

    /**
     * Takes ownership of a stage for this worker.
     *
     * The guard against two workers running one stage: the update is
     * conditional on the row still being in the state we read, so the second
     * worker's update matches nothing and it declines.
     */
    public function claim(MediaItem $item, PipelineStage $stage): bool
    {
        $claimed = MediaItem::withoutGlobalScopes()
            ->whereKey($item->id)
            ->where('pipeline_stage', $stage->value)
            ->whereIn('pipeline_state', [PipelineState::Queued->value, PipelineState::Failed->value])
            ->update([
                'pipeline_state' => PipelineState::Running->value,
                'pipeline_updated_at' => now(),
                'processing_status' => ProcessingStatus::Processing->value,
            ]);

        return $claimed === 1;
    }

    /**
     * Records a stage as finished and queues the next one.
     *
     * At the end of the pipeline the item is published: visible, complete, and
     * owed nothing.
     */
    public function advance(MediaItem $item, PipelineStage $completed): void
    {
        $next = $completed->next();

        if ($next === null) {
            $item->forceFill([
                'pipeline_stage' => $completed,
                'pipeline_state' => PipelineState::Done,
                'pipeline_attempts' => 0,
                'pipeline_error' => null,
                'pipeline_updated_at' => now(),
            ])->saveQuietly();

            return;
        }

        $item->forceFill([
            'pipeline_stage' => $next,
            'pipeline_state' => PipelineState::Queued,
            // Reset per stage: three attempts at *this* stage, not three for
            // the whole pipeline.
            'pipeline_attempts' => 0,
            'pipeline_error' => null,
            'pipeline_updated_at' => now(),
        ])->saveQuietly();

        $this->dispatch($item, $next);
    }

    /**
     * Parks an item for a person.
     *
     * This is the state that fixes the audit's "lands nowhere" list: a fuzzy
     * match, a failed move, an unreadable file and a quality problem all used
     * to leave an item hidden from the library *and* absent from review. A
     * parked item is visibly waiting, with a reason, and the sweeper leaves it
     * alone — re-queueing it would undo the pending decision and spin.
     */
    public function park(MediaItem $item, PipelineStage $stage, string $reason): void
    {
        $item->forceFill([
            'pipeline_stage' => $stage,
            'pipeline_state' => PipelineState::Waiting,
            'pipeline_error' => $reason,
            'pipeline_updated_at' => now(),
            'processing_status' => ProcessingStatus::NeedsReview,
        ])->saveQuietly();
    }

    /**
     * Gives a stage another go, or parks it once the attempts are spent.
     *
     * A provider being rate-limited is the case this exists for: it is not a
     * failure and must never be recorded as "no match" (#466), so the stage
     * simply happens again later.
     */
    public function retry(MediaItem $item, PipelineStage $stage, string $reason): void
    {
        $attempts = (int) $item->pipeline_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->park($item, $stage, "gave up after {$attempts} attempts: {$reason}");

            return;
        }

        $item->forceFill([
            'pipeline_stage' => $stage,
            'pipeline_state' => PipelineState::Queued,
            'pipeline_attempts' => $attempts,
            'pipeline_error' => $reason,
            'pipeline_updated_at' => now(),
        ])->saveQuietly();

        // Backoff, so a provider that is struggling is not hammered.
        $this->dispatch($item, $stage, delaySeconds: 60 * $attempts);
    }

    /**
     * Records a stage as failed outright.
     *
     * Distinct from `retry()`: this is for something a repeat cannot fix. The
     * sweeper will not requeue it, so it needs a review item — which is why
     * `processing_status` becomes `failed` and the reason is kept.
     */
    public function fail(MediaItem $item, PipelineStage $stage, string $reason): void
    {
        $item->forceFill([
            'pipeline_stage' => $stage,
            'pipeline_state' => PipelineState::Failed,
            'pipeline_error' => $reason,
            'pipeline_updated_at' => now(),
            'processing_status' => ProcessingStatus::Failed,
        ])->saveQuietly();

        Log::warning('A pipeline stage failed', [
            'item' => $item->id,
            'stage' => $stage->value,
            'reason' => $reason,
        ]);
    }

    /**
     * Sends an item back to a stage, to be run again.
     *
     * What resolving a review item does: picking a candidate resumes at
     * enrichment, accepting a quality finding at planning. The attempts counter
     * resets because a person has changed something, so the previous failures
     * say nothing about this run.
     */
    public function resumeAt(MediaItem $item, PipelineStage $stage): void
    {
        $item->forceFill([
            'pipeline_stage' => $stage,
            'pipeline_state' => PipelineState::Queued,
            'pipeline_attempts' => 0,
            'pipeline_error' => null,
            'pipeline_updated_at' => now(),
            'processing_status' => ProcessingStatus::Processing,
        ])->saveQuietly();

        $this->dispatch($item, $stage);
    }

    /** Queues one stage's job on the queue that stage belongs on. */
    public function dispatch(MediaItem $item, PipelineStage $stage, int $delaySeconds = 0): void
    {
        $pending = RunPipelineStageJob::dispatch($item->id, $stage)
            ->onQueue($stage->queue());

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }
}

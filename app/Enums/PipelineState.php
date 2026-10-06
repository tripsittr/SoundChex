<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Enums;

/**
 * Where an item stands within its current pipeline stage (#489).
 *
 * Separate from the stage itself because "reached identification" and "is
 * running identification right now" are different facts, and the sweeper needs
 * both: a stage that has been `Running` past its timeout is stuck, while one
 * that is `Queued` with no job in the queue was lost.
 *
 * `Waiting` is the state that fixes the audit's "lands nowhere" table. An item
 * needing a person is not failed and not in progress — it is parked, with an
 * open review item saying why, and it does not move again until that is
 * resolved.
 */
enum PipelineState: string
{
    /** A job has been dispatched, or should be. */
    case Queued = 'queued';

    /** A job is executing this stage now. */
    case Running = 'running';

    /** Parked for a person. An open review item says why. */
    case Waiting = 'waiting';

    /** This stage finished. The next one is queued. */
    case Done = 'done';

    /** This stage gave up. `pipeline_error` says why. */
    case Failed = 'failed';

    /**
     * Whether the sweeper should consider moving this item along.
     *
     * `Waiting` is excluded deliberately: re-queueing an item that is waiting
     * for a person would undo their pending decision and spin forever.
     */
    public function isSweepable(): bool
    {
        return $this !== self::Waiting && $this !== self::Done;
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Waiting => 'Waiting for review',
            self::Done => 'Done',
            self::Failed => 'Failed',
        };
    }
}

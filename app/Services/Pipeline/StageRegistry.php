<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Services\Pipeline\Stages\CatalogueStage;
use App\Services\Pipeline\Stages\CheckStage;
use App\Services\Pipeline\Stages\DedupeStage;
use App\Services\Pipeline\Stages\EnrichStage;
use App\Services\Pipeline\Stages\FileStage;
use App\Services\Pipeline\Stages\HashStage;
use App\Services\Pipeline\Stages\IdentifyStage;
use App\Services\Pipeline\Stages\PlanStage;
use App\Services\Pipeline\Stages\ProbeStage;
use App\Services\Pipeline\Stages\PublishStage;

/**
 * Which class runs which stage (#489).
 *
 * A map rather than a convention, so a missing handler is a loud error at the
 * point of lookup instead of a stage that silently does nothing -- which is
 * how an item would get marked done without the work happening.
 */
class StageRegistry
{
    /** @var array<string, class-string<Stage>> */
    private const HANDLERS = [
        PipelineStage::Catalogued->value => CatalogueStage::class,
        PipelineStage::Probed->value => ProbeStage::class,
        PipelineStage::Hashed->value => HashStage::class,
        PipelineStage::Identified->value => IdentifyStage::class,
        PipelineStage::Enriched->value => EnrichStage::class,
        PipelineStage::Deduped->value => DedupeStage::class,
        PipelineStage::Checked->value => CheckStage::class,
        PipelineStage::Planned->value => PlanStage::class,
        PipelineStage::Filed->value => FileStage::class,
        PipelineStage::Published->value => PublishStage::class,
    ];

    public function for(PipelineStage $stage): Stage
    {
        $class = self::HANDLERS[$stage->value]
            ?? throw new \LogicException("No handler for pipeline stage {$stage->value}.");

        return app($class);
    }

    /** @return array<int, PipelineStage> */
    public function stages(): array
    {
        return PipelineStage::cases();
    }
}

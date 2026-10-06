<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Pipeline\Stages;

use App\Enums\MatchConfidence;
use App\Jobs\Pipeline\StageOutcome;
use App\Models\MediaItem;
use App\Models\MetadataVersion;
use App\Services\Metadata\MetadataPipeline;
use App\Services\MetadataHistory;
use App\Services\Pipeline\Stage;

/**
 * Works out what this file is (#489).
 *
 * Runs the existing `MetadataPipeline` rather than replacing it: splitting
 * identification from enrichment properly -- scored candidates, stored
 * runners-up, a provider error that is not a no-match -- is #489, and doing it
 * here as well would mean doing it twice. What *this* stage adds now is the
 * thing the old one-job design could not: an **outcome**.
 *
 * Previously a run that matched nothing left the item `complete` and filed, or
 * `needs_review` with no reason, and a provider being down read as "no match".
 * Now a confident match continues, an unidentified item parks with a reason a
 * person can act on, and nothing is silently filed on a guess.
 */
class IdentifyStage implements Stage
{
    public function __construct(
        private MetadataPipeline $pipeline,
        private MetadataHistory $history,
    ) {}

    public function run(MediaItem $item): StageOutcome
    {
        // Taken before the run, so whatever a provider overwrites is
        // recoverable -- providers revise their own records (S-21).
        $before = $this->history->snapshot($item);

        $this->pipeline->run($item);

        $item->refresh();

        // Non-fatal: losing a history entry must not lose the identification.
        //
        // The PRE-run snapshot is what gets stored -- a version represents the
        // state before the change it is attached to, which is what makes
        // "restore this version" mean going back to it.
        try {
            $item->load(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'tags']);

            $changed = $this->history->changedFields($before, $this->history->snapshot($item));

            if ($changed !== []) {
                MetadataVersion::create([
                    'media_item_id' => $item->id,
                    'snapshot' => $before,
                    'reason' => MetadataVersion::REASON_ENRICHMENT,
                    'source' => $item->matched_by,
                    'changed_fields' => $changed,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return match ($item->match_confidence) {
            MatchConfidence::Exact => StageOutcome::done(),

            // A loose match continues, because its fields are still better than
            // nothing and the filing gate -- which #489/#489 tightened -- is
            // what stops it moving a file. The review item explains itself.
            MatchConfidence::Fuzzy => StageOutcome::done(),

            // Nothing matched. This is the case that used to vanish: filed
            // silently for music, or hidden with no reason at all.
            default => StageOutcome::needsReview($this->whyNothingMatched($item)),
        };
    }

    /**
     * The reason a person reads, taken from what the sources actually reported.
     *
     * The pipeline already records a per-source account in `enrichment_report`;
     * this surfaces it instead of writing a generic "no match", which is what
     * made 8,440 unmatched rows indistinguishable from each other.
     */
    private function whyNothingMatched(MediaItem $item): string
    {
        $report = $item->enrichment_report ?? [];
        $sources = $report['sources'] ?? [];

        $errors = collect(is_array($sources) ? $sources : [])
            ->filter(fn ($line): bool => is_array($line) && ($line['outcome'] ?? null) === 'error')
            ->pluck('name')
            ->filter()
            ->values();

        if ($errors->isNotEmpty()) {
            // A provider failing is not the same as a file being
            // unidentifiable, and recording it as one is what made real
            // outages look like an unmatchable library (#489).
            return 'no match, but these sources errored: '.$errors->implode(', ');
        }

        return 'no source could identify this file';
    }
}

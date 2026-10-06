<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Review;

use App\Enums\DuplicateStatus;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaItemReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What needs a person, grouped by the job they came to do (#480).
 *
 * The old review screen offered six tabs named after the system's own
 * taxonomy — Metadata, Duplicates, Cover art, Keeping both, Merged, All — none
 * of which is a thing anybody sets out to do. This groups the same rows by the
 * four jobs instead: identify something unknown, sort out a duplicate, fix
 * cover art, deal with a file problem.
 *
 * The grouping lives here rather than in the page because each job is a
 * different *question*, and the queries that answer them need to stay
 * mutually exclusive: an item must appear in exactly one job, or the counts
 * lie and the same decision is offered twice.
 */
class ReviewQueue
{
    public const IDENTIFY = 'identify';

    public const DUPLICATES = 'duplicates';

    public const COVERS = 'covers';

    public const FILES = 'files';

    /** @var array<string, string> */
    public const JOBS = [
        self::IDENTIFY => 'Identify unknown files',
        self::DUPLICATES => 'Sort out duplicates',
        self::COVERS => 'Fix cover art',
        self::FILES => 'File problems',
    ];

    /** The heading over the queue list for each job. */
    public const TITLES = [
        self::IDENTIFY => 'Unknown files',
        self::DUPLICATES => 'Duplicate pairs',
        self::COVERS => 'Cover art',
        self::FILES => 'File problems',
    ];

    /**
     * How many items each job holds.
     *
     * All four always, including the empty ones: the page dims an empty job
     * rather than hiding it, so the set of controls does not shift under
     * somebody between visits.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (array_keys(self::JOBS) as $job) {
            $counts[$job] = $this->query($job)->count();
        }

        return $counts;
    }

    /**
     * The items in one job, oldest first.
     *
     * Oldest first because a review queue is worked through, and the thing
     * that has been waiting longest is the thing most likely to have been
     * forgotten.
     *
     * @return Collection<int, MediaItem>
     */
    public function items(string $job, int $limit = 200): Collection
    {
        return $this->query($job)
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'duplicateOf'])
            ->orderBy('duplicate_detected_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The query behind one job.
     *
     * Unscoped on purpose: every one of these rows is hidden from the library
     * by `ResolvedScope`, which is precisely why they need a screen of their
     * own. A scoped query here would return nothing.
     */
    public function query(string $job): Builder
    {
        $base = MediaItem::withoutGlobalScopes();

        return match ($job) {
            // A duplicate pair, awaiting a decision. First because the other
            // jobs exclude it, and an item flagged as a duplicate is a
            // duplicate question whatever else is true of it.
            self::DUPLICATES => $base->where('duplicate_status', DuplicateStatus::Pending->value),

            // Two copies carried different art, so the kept cover is
            // uncertain. Excludes pending duplicates: deciding which copy to
            // keep settles the cover too, so asking both would be asking twice.
            self::COVERS => $base->where('needs_cover_review', true)
                ->where(fn (Builder $q) => $q->whereNull('duplicate_status')
                    ->orWhere('duplicate_status', '!=', DuplicateStatus::Pending->value)),

            // Something went wrong with the file itself — a move that failed, a
            // file that cannot be read. Distinct from "we do not know what this
            // is": the fix is on disk, not in metadata.
            self::FILES => $base->where(fn (Builder $q) => $q
                ->where('pipeline_state', PipelineState::Failed->value)
                ->orWhere('processing_status', ProcessingStatus::Failed->value)),

            // Everything else a person has been asked about: parked by the
            // pipeline, or flagged for review the old way. Last, so it is the
            // remainder rather than a competing claim on the same rows.
            default => $base->where(fn (Builder $q) => $q
                ->where('pipeline_state', PipelineState::Waiting->value)
                ->orWhere('processing_status', ProcessingStatus::NeedsReview->value))
                ->where('needs_cover_review', false)
                ->where(fn (Builder $q) => $q->whereNull('duplicate_status')
                    ->orWhere('duplicate_status', '!=', DuplicateStatus::Pending->value))
                ->where(fn (Builder $q) => $q->whereNull('pipeline_state')
                    ->orWhere('pipeline_state', '!=', PipelineState::Failed->value))
                ->where('processing_status', '!=', ProcessingStatus::Failed->value),
        };
    }

    /**
     * The question this item is really asking, in words.
     *
     * The old table showed a row and left the reader to work out what was
     * being asked of them, which is most of why it read as confusing. Every
     * item now opens with its question and a line on why a machine cannot
     * answer it.
     *
     * @return array{question: string, because: string}
     */
    public function ask(MediaItem $item, string $job): array
    {
        if ($job === self::DUPLICATES) {
            $original = $item->duplicateOf;
            $byteIdentical = $item->duplicate_match?->value === 'bytes';

            return $byteIdentical
                ? [
                    'question' => 'These are the same bytes.',
                    'because' => 'Removing the redundant copy loses nothing. It goes to the trash and can be restored for 30 days.',
                ]
                : [
                    'question' => 'Same recording, different file — so possibly two versions rather than two copies.',
                    'because' => 'A remaster, a single edit and a compilation cut are distinct recordings worth keeping. Only an identical edition at identical quality is really a duplicate.',
                ];
        }

        if ($job === self::COVERS) {
            return [
                'question' => 'Which cover is right?',
                'because' => 'Two copies of this release carried different art, so whichever one was kept is a guess.',
            ];
        }

        if ($job === self::FILES) {
            return [
                'question' => 'This file could not be dealt with.',
                'because' => (string) ($item->pipeline_error ?: 'No reason was recorded, which is itself worth looking at.'),
            ];
        }

        // Identify. The pipeline records why it parked the item, and that text
        // is written for a person — so it is shown rather than replaced with a
        // generic "needs review".
        $reason = (string) $item->pipeline_error;

        if ($reason !== '') {
            return [
                'question' => ucfirst($reason),
                'because' => 'Nothing can settle this automatically, which is why it is here rather than filed on a guess.',
            ];
        }

        if ($item->isMusic() && blank($item->musicMetadata?->album)) {
            return [
                'question' => 'No album.',
                'because' => 'Is this a single that never had one, or did the album tag fail to read? Nothing can tell those apart.',
            ];
        }

        return [
            'question' => 'This file has not been identified.',
            'because' => 'No source recognised it, so filing it would mean guessing at its artist and album.',
        ];
    }

    /** Open user reports, which appear as extra evidence on an item. */
    public function reportsFor(MediaItem $item): Collection
    {
        return $item->reports()
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->get();
    }

    /** Whether a job name is one we serve. */
    public function isJob(?string $job): bool
    {
        return $job !== null && array_key_exists($job, self::JOBS);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Review;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Enums\SystemReviewReason;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What needs a person, grouped by the job they came to do (#489).
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
     * One `COUNT` per job rather than a single grouped query, which a5 noted
     * on review. Deliberate: a job is *defined* by its predicate in `query()`,
     * and those predicates have to stay mutually exclusive or the counts lie
     * and the same decision is offered twice. A grouped query would restate
     * that logic as a CASE expression in a second place, and the two would
     * drift. Four counts on indexed columns is the cheaper mistake.
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
        // Items put off for later are excluded from every job. A skip wrote
        // nothing before this, so the same item sat at the top of the queue
        // through a reload, a re-search and the next day -- the button looked
        // broken because it was.
        //
        // Only items that have *something* snoozed are filtered out: an item
        // with no review row at all (older data predating the backfill) must
        // still appear, or skipping would be impossible for exactly the items
        // most in need of attention.
        // Items put off for later are excluded from every job. A skip wrote
        // nothing before this, so the same item sat at the top of the queue
        // through a reload, a re-search and the next day -- the button looked
        // broken because it was.
        //
        // Filtered on the *item's* column, not the review row's. The queue is
        // built from columns of `media_items`, and on a real library 86 of 124
        // items in the identify queue have no review row at all -- snoozing
        // only the row would have worked for 38 of them and silently done
        // nothing for the rest.
        $base = MediaItem::withoutGlobalScopes()
            ->where(fn (Builder $q) => $q
                ->whereNull('review_snoozed_until')
                ->orWhere('review_snoozed_until', '<=', now()));

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
     * The open review items for an item, newest first.
     *
     * The authoritative record since #489. The columns this used to read could
     * say THAT something was wrong but not why, so a reason comes from here
     * where there is one -- and the columns remain the fallback for anything
     * predating the backfill.
     */
    public function reviewItemsFor(MediaItem $item)
    {
        return $item->reviewItems()->open()->latest('id')->get();
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

        // A recorded review item outranks anything derived: it was written at
        // the moment the decision was needed, with the evidence to hand (#489).
        $recorded = $this->reviewItemsFor($item)->first();

        if ($recorded !== null) {
            $reason = $recorded->reasonEnum();

            if ($reason instanceof SystemReviewReason) {
                return [
                    'question' => $this->questionFor($reason),
                    'because' => $this->becauseFor($reason),
                ];
            }

            if ($reason !== null) {
                return [
                    'question' => $reason->label().', reported by someone using the library.',
                    'because' => filled($recorded->note) ? '"'.$recorded->note.'"' : $reason->hint(),
                ];
            }
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

        if ($item->type === MediaItemType::Music && blank($item->musicMetadata?->album)) {
            // The file's own tags answer this, so answer it rather than asking.
            // A track number means the file came off an album whose title did
            // not read; nothing at all means a standalone file, which is what a
            // single looks like on disk. Measured on this library: 30 of 39
            // no-album items carry a track number, so the old wording --
            // "nothing can tell those apart" -- was wrong about 77% of them.
            $track = $item->musicMetadata?->track_number;

            return [
                'question' => 'No album.',
                'because' => filled($track)
                    ? 'The file says it is track '.$track.', so it came from an album whose title did not read. Looking it up again may recover it.'
                    : 'Nothing in the file names an album or a track number, which is what a standalone single looks like on disk.',
            ];
        }

        // Identified, but not confidently enough to file on. Saying "no source
        // recognised it" here was simply false: 322 items in a real identify
        // queue had an artist, an album and cover art while being told nothing
        // had recognised them, which is why the only offered action -- look it
        // up again -- returned the same answer every time.
        $artist = $item->musicMetadata?->artist;

        if ($item->type === MediaItemType::Music && filled($artist) && filled($item->musicMetadata?->album)) {
            $by = filled($item->matched_by) ? $item->matched_by : 'a source';
            $confidence = $item->match_confidence?->value;

            return [
                'question' => 'Is this the right match?',
                'because' => $by.' matched this to "'.$item->musicMetadata->album.'" by '.$artist
                    .($confidence === 'fuzzy'
                        ? ', but on the title alone rather than on an identifier, so it needs a person to confirm it.'
                        : ', and it needs confirming before the file is moved.'),
            ];
        }

        return [
            'question' => 'This file has not been identified.',
            'because' => 'No source recognised it, so filing it would mean guessing at its artist and album.',
        ];
    }

    /**
     * The question a system reason asks, phrased for a person.
     *
     * Separate from the enum's label(), which is a badge: "No album" fits a
     * chip, and "Is this a single, or did the tag fail to read?" is what
     * somebody needs in order to answer.
     */
    private function questionFor(SystemReviewReason $reason): string
    {
        return match ($reason) {
            SystemReviewReason::MissingAlbum => 'No album.',
            SystemReviewReason::NoMatch => 'Nothing could identify this file.',
            SystemReviewReason::AmbiguousMatch => 'Several matches scored alike.',
            SystemReviewReason::LowConfidence => 'Matched loosely, not by an identifier.',
            SystemReviewReason::CompilationOrUndated => 'Matched to a compilation or an undated release.',
            SystemReviewReason::Duplicate => 'This looks like a copy of something already here.',
            SystemReviewReason::Quality => 'A quality check found something wrong.',
            SystemReviewReason::Unreadable => 'This file could not be read.',
            SystemReviewReason::CoverUncertain => 'Which cover is right?',
            SystemReviewReason::CannotFile => 'This cannot be filed yet.',
            SystemReviewReason::MoveFailed => 'Moving this file failed.',
            SystemReviewReason::MissingFile => 'The file is not where the catalogue says.',
            SystemReviewReason::Stuck => 'This gave up part way through.',
        };
    }

    /** Why a machine cannot answer it, which is why it is here at all. */
    private function becauseFor(SystemReviewReason $reason): string
    {
        return match ($reason) {
            SystemReviewReason::MissingAlbum => 'Is this a single that never had one, or did the album tag fail to read? Nothing can tell those apart.',
            SystemReviewReason::NoMatch => 'No source recognised it, so filing it would mean guessing at its artist and album.',
            SystemReviewReason::AmbiguousMatch => 'Picking one would be a coin toss, and a wrong match renames the file.',
            SystemReviewReason::LowConfidence => 'A text search is usually right and sometimes not. Only an identifier is certain.',
            SystemReviewReason::CompilationOrUndated => 'The recording is right; the release it was matched to may not be the original.',
            SystemReviewReason::Duplicate => 'Same work, same version, same quality. Which copy to keep depends on things only you can see.',
            SystemReviewReason::Quality => 'The file may be truncated, silent, or not what it claims to be.',
            SystemReviewReason::Unreadable => 'It may be corrupt, or not media at all.',
            SystemReviewReason::CoverUncertain => 'Two copies carried different art, so whichever was kept is a guess.',
            SystemReviewReason::CannotFile => 'The metadata a path is built from is missing, so there is nowhere to put it.',
            SystemReviewReason::MoveFailed => 'The journal records which half happened, so nothing is lost -- but it needs finishing.',
            SystemReviewReason::MissingFile => 'Either it moved without the catalogue noticing, or it is gone.',
            SystemReviewReason::Stuck => 'It failed repeatedly, so a person should see the error before it tries again.',
        };
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

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Enums\DuplicateStatus;
use App\Filament\Concerns\RestrictsToAdmins;
use App\Models\MediaItem;
use App\Services\DuplicateDetector;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Review\ReviewQueue;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One thing at a time, with the evidence and the two or three plausible
 * answers (#480).
 *
 * Supersedes `DuplicateResource`'s table — 1,043 lines, 16 columns, 14 actions
 * and six tabs named after the system's own taxonomy (Metadata, Duplicates,
 * Cover art, Keeping both, Merged, All), none of which is a thing anybody sets
 * out to do. The user's complaint was precise: *"the page design and layout is
 * the issue... something simpler than a tab arrangement of reasons."*
 *
 * That resource is **still registered**, deliberately. Three test suites cover
 * its behaviour, and removing it in the same change as adding this would mean
 * proving the new screen and rewriting those at once. It is navigation-hidden
 * instead, so there is one entry in the sidebar and the old URL keeps working
 * for anyone who bookmarked it, until a follow-up retires it.
 *
 * A **Page** rather than a Resource, because a table is for scanning and this
 * task is deciding (#482). It stays in the panel because `/admin` is already
 * gated on the current *profile's* permissions, and reviewing means approving
 * file moves, merging duplicates and deleting files — exactly what that gate
 * exists for. A kids profile must never reach a merge button.
 *
 * The layout this implements was approved before it was built (#481), because
 * the complaint was about design and building to my own taste first would have
 * wasted the pass.
 */
class ReviewQueuePage extends Page
{
    use RestrictsToAdmins;

    protected string $view = 'filament.pages.review-queue';

    protected static ?string $slug = 'review';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $navigationLabel = 'Needs Review';

    protected static ?string $title = 'Needs Review';

    protected static ?int $navigationSort = 20;

    /**
     * Which job is open, and which item within it.
     *
     * In the URL so a decision can be linked to and survives a refresh — a
     * queue you lose your place in is a queue you stop working.
     */
    #[Url]
    public string $job = ReviewQueue::IDENTIFY;

    #[Url]
    public ?int $item = null;

    public function mount(): void
    {
        if (! app(ReviewQueue::class)->isJob($this->job)) {
            $this->job = ReviewQueue::IDENTIFY;
        }

        $this->ensureSelection();
    }

    /** The badge beside the nav entry: how much is waiting in total. */
    public static function getNavigationBadge(): ?string
    {
        $total = array_sum(app(ReviewQueue::class)->counts());

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Switches job, keeping the user at the top of the new one. */
    public function openJob(string $job): void
    {
        if (! app(ReviewQueue::class)->isJob($job)) {
            return;
        }

        $this->job = $job;
        $this->item = null;

        $this->ensureSelection();
    }

    public function select(int $id): void
    {
        $this->item = $id;
    }

    /** Moves to the next item, which is what every decision does. */
    public function next(): void
    {
        $ids = $this->itemIds();

        if ($ids === []) {
            $this->item = null;

            return;
        }

        $at = array_search($this->item, $ids, true);

        $this->item = $at === false || $at >= count($ids) - 1
            ? $ids[0]
            : $ids[$at + 1];
    }

    public function previous(): void
    {
        $ids = $this->itemIds();

        if ($ids === []) {
            return;
        }

        $at = array_search($this->item, $ids, true);

        $this->item = $at === false || $at === 0
            ? $ids[count($ids) - 1]
            : $ids[$at - 1];
    }

    /* ------------------------------------------------------- decisions -- */

    /**
     * "Looks fine" — the answer to most of the queue.
     *
     * Stamps `reviewed_at`, which every other path checks so a later
     * re-enrichment cannot drag the item back (the S-302 trap). Then publishes
     * it, because a judged item belongs in the library.
     */
    public function accept(int $id): void
    {
        $item = $this->find($id);

        if ($item === null) {
            return;
        }

        $item->forceFill(['reviewed_at' => now()])->saveQuietly();

        app(PipelineRunner::class)->resumeAt($item, \App\Enums\PipelineStage::Published);

        $this->after($item, 'Marked as fine');
    }

    /**
     * Sends the item back to be identified again.
     *
     * For when the match is wrong rather than missing: re-running
     * identification is the fix, and it resumes at that stage rather than
     * re-running the whole pipeline.
     */
    public function reidentify(int $id): void
    {
        $item = $this->find($id);

        if ($item === null) {
            return;
        }

        app(PipelineRunner::class)->resumeAt($item, \App\Enums\PipelineStage::Identified);

        $this->after($item, 'Looking it up again');
    }

    /** Keeps both copies of a pair, as versions rather than duplicates. */
    public function keepBoth(int $id): void
    {
        $item = $this->find($id);

        if ($item === null) {
            return;
        }

        app(DuplicateDetector::class)->keepBoth($item);

        $this->after($item, 'Keeping both');
    }

    /**
     * Resolves a pair by keeping one copy.
     *
     * Delegates to the detector, which re-hashes before deleting and routes
     * the loser to the trash rather than unlinking it (#464). Refuses on its
     * own when `duplicate_action` is `report` (#461), so this does not need to
     * re-check that.
     */
    public function keepOne(int $id, bool $keepFlagged = false): void
    {
        $item = $this->find($id);

        if ($item === null) {
            return;
        }

        $detector = app(DuplicateDetector::class);

        $resolved = $item->duplicate_match?->isContent()
            ? $detector->resolveKeeping($item, keepDuplicate: $keepFlagged)
            : $detector->merge($item);

        if (! $resolved) {
            // Named, because the common causes are actionable: duplicate
            // handling set to "report", a file that is read-only or open in
            // another program, or bytes that no longer match.
            Notification::make()
                ->title('Could not resolve that pair')
                ->body('Check that duplicate handling is not set to Report, and that neither file is read-only or open elsewhere. The log names which.')
                ->warning()
                ->send();

            return;
        }

        $this->after($item, 'Resolved');
    }

    /** Accepts the cover that is already on the item. */
    public function acceptCover(int $id): void
    {
        $item = $this->find($id);

        if ($item === null) {
            return;
        }

        app(DuplicateDetector::class)->clearCoverReview($item);

        $this->after($item, 'Cover confirmed');
    }

    /**
     * Accepts every cover waiting for review.
     *
     * Bulk only where it is safe. These are items whose audio decision is
     * already made and where only the artwork was uncertain, so confirming
     * them en masse risks nothing on disk. Bulk deliberately does **not**
     * exist for loose duplicate matches — the detector refuses those outright
     * (#461), and offering a button that cannot work would be worse than none.
     */
    public function acceptAllCovers(): void
    {
        $queue = app(ReviewQueue::class);
        $detector = app(DuplicateDetector::class);

        $count = 0;

        foreach ($queue->query(ReviewQueue::COVERS)->get() as $item) {
            $detector->clearCoverReview($item);
            $count++;
        }

        Notification::make()
            ->title($count === 1 ? '1 cover confirmed' : "{$count} covers confirmed")
            ->success()
            ->send();

        $this->item = null;
        $this->ensureSelection();
    }

    /** Leaves the item exactly as it is, and moves on. */
    public function skip(int $id): void
    {
        $this->next();
    }

    /* --------------------------------------------------------- reading -- */

    /** @return array<string, int> */
    public function getCountsProperty(): array
    {
        return app(ReviewQueue::class)->counts();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MediaItem> */
    public function getQueueProperty()
    {
        return app(ReviewQueue::class)->items($this->job);
    }

    public function getCurrentProperty(): ?MediaItem
    {
        if ($this->item === null) {
            return null;
        }

        return $this->getQueueProperty()->firstWhere('id', $this->item)
            ?? $this->find($this->item);
    }

    /** @return array{question: string, because: string}|null */
    public function getAskProperty(): ?array
    {
        $current = $this->getCurrentProperty();

        return $current === null
            ? null
            : app(ReviewQueue::class)->ask($current, $this->job);
    }

    /** The other copy in a pair, for the side-by-side. */
    public function getOriginalProperty(): ?MediaItem
    {
        return $this->getCurrentProperty()?->duplicateOf;
    }

    /* --------------------------------------------------------- helpers -- */

    /**
     * Picks the first item when nothing is selected, or when the selection has
     * left the queue — which is what happens after every decision.
     */
    private function ensureSelection(): void
    {
        $ids = $this->itemIds();

        if ($ids === []) {
            $this->item = null;

            return;
        }

        if ($this->item === null || ! in_array($this->item, $ids, true)) {
            $this->item = $ids[0];
        }
    }

    /** @return array<int, int> */
    private function itemIds(): array
    {
        return $this->getQueueProperty()->pluck('id')->all();
    }

    /**
     * Unscoped, because every row in this queue is hidden from the library by
     * `ResolvedScope` — which is precisely why it needs a screen of its own.
     */
    private function find(int $id): ?MediaItem
    {
        return MediaItem::withoutGlobalScopes()
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'duplicateOf'])
            ->find($id);
    }

    /** Confirms what happened and advances, which is the queue's whole rhythm. */
    private function after(MediaItem $item, string $message): void
    {
        Notification::make()
            ->title($message)
            ->body($item->title)
            ->success()
            ->send();

        $this->next();
        $this->ensureSelection();
    }
}

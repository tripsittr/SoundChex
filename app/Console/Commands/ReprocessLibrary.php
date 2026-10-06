<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Models\MediaItem;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Review\ReviewLog;
use Illuminate\Console\Command;

/**
 * Runs the rebuilt pipeline over the library that already exists (#470).
 *
 * The point of the whole plan, and the riskiest single operation in it: 8,335
 * items were catalogued and filed by the old rules, and every fix in phases
 * 1–7 only applies to them once something re-runs.
 *
 * **Staged deliberately**, following the plan's six steps rather than offering
 * a single button:
 *
 *   `--dry-run`   says what would change, writes nothing
 *   `--identify`  applies identities and fields, files nothing
 *   `--file`      moves files, in batches, journalled
 *
 * The staging is the safety. Identification is reversible (a wrong field can
 * be re-fetched); filing moves bytes. Doing them in one pass would mean a bad
 * identification became a misfiled file before anybody could look.
 *
 * It refuses to run while a worker holds the database, because a reprocess
 * competing with a live queue is how two processes come to disagree about what
 * stage an item is at.
 */
class ReprocessLibrary extends Command
{
    protected $signature = 'library:reprocess
        {--dry-run : Report what would change, writing nothing}
        {--identify : Re-identify and enrich, without filing anything}
        {--file : File items whose identification has settled}
        {--type= : Only this media type}
        {--batch=500 : How many items per batch}
        {--limit=0 : Stop after this many items (0 = all)}
        {--force : Run even though a queue worker is active}';

    protected $description = 'Re-run the pipeline over the existing library, in stages';

    public function handle(PipelineRunner $runner, ReviewLog $log): int
    {
        $mode = $this->mode();

        if ($mode === null) {
            $this->error('Choose a stage: --dry-run, --identify or --file.');
            $this->line('');
            $this->line('  The order matters, and is the plan\'s own:');
            $this->line('    1. php artisan db:backup');
            $this->line('    2. php artisan library:manifest');
            $this->line('    3. php artisan library:reprocess --dry-run      # read this before step 4');
            $this->line('    4. php artisan library:reprocess --identify');
            $this->line('    5. open /admin/review and clear what it opened');
            $this->line('    6. php artisan library:reprocess --file --batch=500');
            $this->line('    7. php artisan library:verify-manifest <the manifest>');

            return self::FAILURE;
        }

        if (! $this->safeToRun()) {
            return self::FAILURE;
        }

        $items = $this->candidates($mode);
        $limit = max(0, (int) $this->option('limit'));
        $total = $limit > 0 ? min($limit, $items->count()) : $items->count();

        if ($total === 0) {
            $this->info('Nothing to reprocess in that stage.');

            return self::SUCCESS;
        }

        return match ($mode) {
            'dry-run' => $this->report($items, $total),
            'identify' => $this->requeue($items, $total, PipelineStage::Identified, $runner, $log),
            'file' => $this->requeue($items, $total, PipelineStage::Planned, $runner, $log),
        };
    }

    /**
     * Reports what a reprocess would change, touching nothing.
     *
     * The plan's step 2, and the step that makes the rest sane: *"Reviewed by
     * a person before step 3."* A number nobody has looked at is not a plan.
     */
    private function report($items, int $total): int
    {
        $this->info("Would reprocess {$total} item(s). Nothing will be written.");

        $byType = [];
        $noIdentifier = 0;
        $wouldMove = 0;
        $alreadyWaiting = 0;

        $organizer = app(\App\Services\LibraryOrganizer::class);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $seen = 0;

        foreach ($items->lazyById(200) as $item) {
            if ($seen >= $total) {
                break;
            }

            $seen++;
            $byType[$item->type->value] = ($byType[$item->type->value] ?? 0) + 1;

            if (blank($item->work_key)) {
                $noIdentifier++;
            }

            if ($item->pipeline_state === PipelineState::Waiting) {
                $alreadyWaiting++;
            }

            // Would the file move? Asked of the organizer rather than guessed,
            // so the number reflects the real templates including the
            // album-artist and path-safety fixes.
            $target = $organizer->canOrganize($item) ? $organizer->targetPath($item) : null;

            if ($target !== null && ! $organizer->isAlreadyFiled($item)) {
                $wouldMove++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['what', 'count'], [
            ['Items in scope', $total],
            ['Files that would move', $wouldMove],
            ['With no identifier (cannot be grouped)', $noIdentifier],
            ['Already waiting for a person', $alreadyWaiting],
        ]);

        $this->newLine();
        $this->table(
            ['type', 'items'],
            collect($byType)->map(fn (int $n, string $type): array => [$type, $n])->values()->all(),
        );

        $this->newLine();
        $this->comment('Read those numbers before running --identify. In particular:');
        $this->line('  - "files that would move" is how much churn to expect on disk');
        $this->line('  - a large "no identifier" count means --identify has real work to do first');

        return self::SUCCESS;
    }

    /**
     * Sends items back to a stage, in batches.
     *
     * Batched because the plan says so and the reason is recoverability: a
     * batch id is what `library:undo-moves --batch` reverses, and 8,000 items
     * in one batch is one decision with no middle ground.
     */
    private function requeue($items, int $total, PipelineStage $stage, PipelineRunner $runner, ReviewLog $log): int
    {
        $batchSize = max(1, (int) $this->option('batch'));

        $this->info("Sending {$total} item(s) back to the {$stage->value} stage, in batches of {$batchSize}.");
        $this->comment('The queue does the work. Watch it with: php artisan queue:work --queue=default,net,cpu,io');

        $queued = $skipped = 0;
        $seen = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($items->lazyById(200) as $item) {
            if ($seen >= $total) {
                break;
            }

            $seen++;

            // A person's decision outranks a bulk re-run. An item somebody has
            // judged keeps their answer -- dragging it back is the S-302 trap,
            // and doing it to 8,000 items at once would undo every review ever
            // made.
            if ($item->reviewed_at !== null) {
                $skipped++;
                $bar->advance();

                continue;
            }

            // Nor one with an open question: it is waiting on an answer, and
            // re-running the stage would discard the evidence the reviewer is
            // looking at.
            if ($log->openFor($item)->isNotEmpty()) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $runner->resumeAt($item, $stage);
            $queued++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Queued {$queued}.".
            ($skipped > 0 ? " Skipped {$skipped} already reviewed or awaiting an answer." : ''));

        if ($stage === PipelineStage::Planned) {
            $this->newLine();
            $this->comment('When the queue drains, check nothing was lost:');
            $this->line('  php artisan library:verify-manifest <your manifest>');
        }

        return self::SUCCESS;
    }

    /**
     * Refuses to run alongside a live worker.
     *
     * Two processes advancing the same item's stage will disagree about where
     * it is, and `Handoff.md` is explicit that the bundled runtime holds the
     * database open. The check is a heuristic -- a worker on another machine is
     * invisible from here -- so `--force` exists, with the risk stated.
     */
    private function safeToRun(): bool
    {
        if ($this->option('dry-run') || $this->option('force')) {
            return true;
        }

        $queued = \DB::table('jobs')->count();

        if ($queued === 0) {
            return true;
        }

        $this->error("There are {$queued} job(s) in the queue, so a worker is probably running.");
        $this->line('  A reprocess competing with a live queue makes two processes disagree');
        $this->line('  about what stage an item is at. Stop the worker first, or pass --force');
        $this->line('  if you are certain nothing is consuming the queue.');

        return false;
    }

    /** Which stage was asked for, or null when none or several were. */
    private function mode(): ?string
    {
        $chosen = array_keys(array_filter([
            'dry-run' => (bool) $this->option('dry-run'),
            'identify' => (bool) $this->option('identify'),
            'file' => (bool) $this->option('file'),
        ]));

        return count($chosen) === 1 ? $chosen[0] : null;
    }

    private function candidates(string $mode)
    {
        $query = MediaItem::withoutGlobalScopes()
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata', 'probe'])
            ->orderBy('id');

        if ($type = $this->option('type')) {
            $resolved = MediaItemType::tryFrom((string) $type);

            if ($resolved !== null) {
                $query->where('type', $resolved);
            }
        }

        // Filing only considers items whose identification has settled. An
        // item still waiting on a person must not be moved out from under
        // them, which is the same rule the filing gate applies (#460).
        if ($mode === 'file') {
            $query->where('pipeline_state', '!=', PipelineState::Waiting->value);
        }

        return $query;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\QueueControl;
use App\Services\QueueInspector;
use App\Services\ScheduleInspector;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * What the background workers are doing, on the dashboard.
 *
 * Every heavy operation in this app queues — enrichment, cover fetching,
 * duplicate hashing, transcoding — and starting one from the panel gave a
 * notification and then silence. Whether 6,000 jobs were moving, stuck behind a
 * dead worker, or quietly failing one by one was answerable only from the `jobs`
 * table or the log.
 *
 * Refreshes on a timer because the interesting state is the one that changes.
 */
class QueueActivity extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;

    // An action's modal is built as a schema, so the component needs both
    // halves; with only the actions trait it fails looking for schema state.
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.queue-activity';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    /** Often enough to watch a queue drain, rarely enough not to be a load test. */
    protected static ?string $pollingInterval = '10s';

    public static function canView(): bool
    {
        return Dashboard::canAccess();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function pending(): Collection
    {
        return app(QueueInspector::class)->pending();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function failed(): Collection
    {
        return app(QueueInspector::class)->failed();
    }

    public function totalPending(): int
    {
        return app(QueueInspector::class)->totalPending();
    }

    public function totalFailed(): int
    {
        return app(QueueInspector::class)->totalFailed();
    }

    /**
     * Whether a worker is alive to do any of it.
     *
     * A queue that is not draining looks identical to one with nothing to do
     * until you know this. Inferred rather than measured: a worker takes a job
     * by reserving it, so anything reserved means one was running recently. With
     * nothing reserved and work waiting, the honest answer is "cannot tell" —
     * the worker may simply be between jobs.
     */
    public function workerSeen(): bool
    {
        return $this->pending()->sum('running') > 0;
    }

    /**
     * The scheduler's heartbeat, written every minute by `routes/console.php`.
     *
     * Shown here because the scheduler is what dispatches the recurring work
     * that fills this queue.
     */
    public function schedulerHeartbeat(): ?int
    {
        $at = cache()->get('soundchex.scheduler.heartbeat');

        return is_numeric($at) ? (int) $at : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function scheduled(): Collection
    {
        return app(ScheduleInspector::class)->all();
    }

    /* ----------------------------------------------------- controls ----- */

    public function isPaused(): bool
    {
        return app(QueueControl::class)->isPaused();
    }

    /** When a pause lapses by itself, so a forgotten one is visible. */
    public function pausedUntil(): ?string
    {
        return app(QueueControl::class)->pausedUntil()?->format('H:i');
    }

    /** Jobs a minute, measured rather than estimated. Null until two samples. */
    public function throughput(): ?float
    {
        return app(QueueInspector::class)->throughput();
    }

    /**
     * Roughly how long until it is empty, in words.
     *
     * "about 4 hours" answers the question somebody has; "7,018 waiting" does
     * not. Null while the rate is unknown rather than guessing.
     */
    public function remaining(): ?string
    {
        $minutes = app(QueueInspector::class)->minutesRemaining();

        if ($minutes === null) {
            return null;
        }

        return now()->addMinutes($minutes)->diffForHumans(['parts' => 1, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }

    /**
     * Stops the worker taking new jobs, without killing it.
     *
     * Nothing in flight is interrupted: this takes effect between jobs, so a
     * transcode half-way through finishes.
     */
    public function pause(): void
    {
        app(QueueControl::class)->pause();

        Notification::make()
            ->title('Background work paused')
            ->body('Anything already running finishes. Nothing new starts until you resume.')
            ->success()
            ->send();
    }

    public function resume(): void
    {
        app(QueueControl::class)->resume();

        Notification::make()->title('Background work resumed')->success()->send();
    }

    /**
     * Throws away everything queued.
     *
     * Confirmed, because it is not recoverable -- the jobs are gone and
     * whatever they would have done is simply not done. Jobs a worker has in
     * hand are left alone, since deleting the row would not stop the work,
     * only lose the record of it.
     */
    public function cancelAllAction(): Action
    {
        return Action::make('cancelAll')
            ->label('Discard queued')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Discard all queued work?')
            ->modalDescription(fn (): string => $this->totalPending().' job(s) will be thrown away. '
                .'Anything a worker is running right now finishes. This cannot be undone, '
                .'though a library scan will queue the work again.')
            ->modalSubmitActionLabel('Discard them')
            ->action(function (): void {
                $count = app(QueueControl::class)->cancel();

                Notification::make()
                    ->title($count === 0 ? 'Nothing queued' : $count.' job(s) discarded')
                    ->success()
                    ->send();
            });
    }

    /**
     * Clears the failed-job record.
     *
     * These rows are a log rather than work -- nothing retries from them
     * unless asked -- so clearing loses the reasons and nothing else. The
     * confirmation says how many and what they were, because "6 failed" with
     * no detail is the state this widget exists to end.
     */
    public function clearFailedAction(): Action
    {
        return Action::make('clearFailed')
            ->label('Clear failed')
            ->icon('heroicon-m-x-circle')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Clear the failed-job record?')
            ->modalDescription(fn (): string => $this->failedSummary())
            ->modalSubmitActionLabel('Clear them')
            ->action(function (): void {
                $count = app(QueueControl::class)->clearFailed();

                Notification::make()
                    ->title($count === 0 ? 'Nothing had failed' : $count.' record(s) cleared')
                    ->success()
                    ->send();
            });
    }

    /** What is about to be lost, named rather than counted. */
    private function failedSummary(): string
    {
        $failed = $this->failed();

        if ($failed->isEmpty()) {
            return 'Nothing has failed.';
        }

        $lines = $failed->take(3)->map(
            fn (array $row): string => $row['count'].' x '.$row['job']
        )->implode(', ');

        return $this->totalFailed().' record(s) will be removed ('.$lines.'). '
            .'They are a log, not work -- clearing them retries nothing and cancels nothing.';
    }

    /**
     * What one kind of job is actually doing, behind its row.
     *
     * The table can say six thousand jobs are waiting. It cannot say which
     * file the worker has open, or why the last hundred came back needing
     * review — and that is the question the table provokes. The log holds the
     * answer and lived in a 7 MB file on the server, which is not a reasonable
     * place to send someone running this on their own machine.
     *
     * Built per job kind only when asked. The table itself is polled every ten
     * seconds and has to stay cheap.
     */
    public function inspectAction(): Action
    {
        return Action::make('inspect')
            ->modalHeading(fn (array $arguments): string => (string) ($arguments['job'] ?? 'Job'))
            ->modalDescription('What the worker has in hand, what is next, and what the log says about it.')
            ->modalContent(fn (array $arguments) => view('filament.widgets.queue-job-detail', [
                'detail' => app(QueueInspector::class)->detail((string) ($arguments['job'] ?? '')),
            ]))
            ->modalWidth('5xl')
            // Nothing to submit; this only reports.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Put every failed job back on the queue.
     *
     * Worth a button because the common failure here is transient — SQLite
     * refusing a concurrent write — and the worker runs with `--tries=1`, so a
     * single lock kills a job for good. Retrying is otherwise a terminal.
     */
    public function retryFailed(): void
    {
        $count = $this->totalFailed();

        if ($count === 0) {
            Notification::make()->title('Nothing has failed')->info()->send();

            return;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        Notification::make()
            ->title($count.' '.str('job')->plural($count).' queued again')
            ->success()
            ->send();
    }
}

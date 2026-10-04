<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\QueueInspector;
use App\Services\ScheduleInspector;
use Filament\Notifications\Notification;
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
class QueueActivity extends Widget
{
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

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\ScheduleInspector;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The scheduler's work, on the dashboard.
 *
 * The schedule lives in `routes/console.php` and was invisible from the panel:
 * a scan that stopped running, or an hourly health check whose command began
 * failing, looked the same as one with nothing to do. This says what is meant
 * to run, when it next will, and when it last did.
 *
 * A plain table rather than a Filament table builder: these rows come from the
 * scheduler, not a query, and the table builder's sorting, filtering and
 * pagination would all need an Eloquent source to work against.
 */
class ScheduledJobs extends Widget
{
    protected string $view = 'filament.widgets.scheduled-jobs';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    /**
     * Widgets render independently of the page hosting them, so this repeats
     * the gate rather than relying on it.
     */
    public static function canView(): bool
    {
        return Dashboard::canAccess();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function tasks(): Collection
    {
        return app(ScheduleInspector::class)->all();
    }

    /**
     * Whether the scheduler itself is alive.
     *
     * `routes/console.php` writes this heartbeat every minute with a ten-minute
     * life, so a missing one means the scheduler is not running — and then every
     * "next run" in the table below is a statement of intent rather than a
     * prediction. Worth saying plainly above the table.
     */
    public function heartbeat(): ?int
    {
        $at = cache()->get('soundchex.scheduler.heartbeat');

        return is_numeric($at) ? (int) $at : null;
    }
}

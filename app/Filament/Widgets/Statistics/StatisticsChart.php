<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets\Statistics;

use App\Filament\Pages\MusicStatisticsPage;
use App\Enums\MediaItemType;
use App\Services\LibraryStatistics;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Shared base for every chart on the statistics page.
 *
 * The page owns the date range and the profile filter, and passes both in as
 * mount parameters — so a chart cannot disagree with the table beside it about
 * what "last 30 days" covers. That was the whole reason the figures were
 * gathered in one place to begin with.
 *
 * Chart.js through Filament's own widget rather than a second charting
 * library: it ships with the panel, it is already used by `GenreSplit`, and it
 * follows the theme without being told to.
 */
abstract class StatisticsChart extends ChartWidget
{
    /** Passed down from the page, so every chart shares one window. */
    public string $range = '30';

    public string $profileId = '';

    /** Which media type the page's open tab is showing. */
    public string $type = 'music';

    /**
     * Repeated rather than inherited from the page.
     *
     * A widget renders independently of whatever hosts it — Livewire will
     * happily resolve one by name — so relying on the page's gate would leave
     * listening history readable by anyone who guessed the component.
     */
    public static function canView(): bool
    {
        return MusicStatisticsPage::canAccess();
    }

    protected ?string $maxHeight = '260px';

    protected function stats(): LibraryStatistics
    {
        return app(LibraryStatistics::class)->for($this->mediaType());
    }

    protected function mediaType(): MediaItemType
    {
        return MediaItemType::tryFrom($this->type) ?? MediaItemType::Music;
    }

    /** What this type's groupings are called — "Artist", "Director", … */
    protected function labels(): array
    {
        return $this->stats()->labels();
    }

    /** The window start, or null for all time. */
    protected function from(): ?Carbon
    {
        return $this->range === 'all'
            ? null
            : Carbon::today()->subDays((int) $this->range);
    }

    protected function profile(): ?int
    {
        return $this->profileId === '' ? null : (int) $this->profileId;
    }

    /** Nothing to draw, said in the shape Chart.js expects. */
    protected function empty(): array
    {
        return ['datasets' => [], 'labels' => []];
    }

    /**
     * The window, in words.
     *
     * Every chart says what period it covers and whose listening it is. A
     * chart that does not is a set of bars someone has to guess the meaning
     * of — and two charts on one screen showing different windows without
     * saying so is worse than either alone.
     */
    protected function window(): string
    {
        $period = match ($this->range) {
            'all' => 'All time',
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last year',
            default => 'Last ' . $this->range . ' days',
        };

        $who = $this->profileId === ''
            ? 'everyone'
            : (\App\Models\Profile::find((int) $this->profileId)?->name ?? 'everyone');

        return $period . ' · ' . $who;
    }

    /**
     * The window plus what this chart counts.
     *
     * Stated per chart because the unit differs and the difference matters: a
     * play is a session, listening time is seconds, and storage is estimated.
     * Reading the wrong one for the other is the mistake a statistics page
     * invites when it only labels the axis.
     */
    public function getDescription(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return $this->window() . ' · ' . $this->measures();
    }

    /** What this chart counts, in a few words. */
    abstract protected function measures(): string;
}

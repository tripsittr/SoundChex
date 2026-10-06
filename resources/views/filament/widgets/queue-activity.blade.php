@php
    $pending = $this->pending();
    $failed = $this->failed();
    $totalPending = $this->totalPending();
    $totalFailed = $this->totalFailed();
    $workerSeen = $this->workerSeen();
    $heartbeat = $this->schedulerHeartbeat();
    $scheduled = $this->scheduled();

    $schedulerAlive = $heartbeat !== null && $heartbeat > now()->subMinutes(10)->timestamp;

    $paused = $this->isPaused();
    $rate = $this->throughput();
    $remaining = $this->remaining();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Background work</x-slot>

        <x-slot name="description">
            @if ($totalPending === 0 && $totalFailed === 0)
                The queue is empty.
            @else
                {{-- The failed count is interpolated, not an inline @if: Blade only
                     recognises a directive at a non-word boundary, so an @if glued
                     to the preceding word is left as literal text while its @endif
                     still compiles, and the page dies on a stray endif. --}}
                {{ number_format($totalPending) }} waiting{{ $totalFailed > 0 ? ', '.number_format($totalFailed).' failed' : '' }}.

                @if ($paused)
                    {{-- Said first and plainly: a paused queue looks exactly
                         like a stuck one, and that ambiguity is the whole
                         reason somebody opens this widget. --}}
                    <span class="font-medium text-warning-600 dark:text-warning-400">
                        Paused{{ $this->pausedUntil() ? ' — lapses at '.$this->pausedUntil() : '' }}.
                        Anything already running finishes.
                    </span>
                @elseif ($rate !== null && $rate > 0)
                    {{-- Measured, not estimated. A rate-limited source like
                         MusicBrainz at one request a second is nothing a
                         static guess would capture. --}}
                    <span class="text-gray-500 dark:text-gray-400">
                        About {{ $rate }} a minute{{ $remaining ? ', done in '.$remaining : '' }}.
                    </span>
                @endif

                @if ($totalPending > 0 && ! $workerSeen && ! $paused)
                    {{-- Nothing reserved. Could be between jobs, could be a dead
                         worker; say which it might be rather than asserting. --}}
                    <span class="text-warning-600 dark:text-warning-400">
                        Nothing is being worked on right now — the worker may be idle between jobs, or stopped.
                    </span>
                @endif
            @endif
        </x-slot>

        {{-- `afterHeader`, not `headerEnd`: the section component has no such
             slot, so this whole block -- including the Retry button, which
             predates these controls -- was silently dropped and never rendered
             once. --}}
        <x-slot name="afterHeader">
            <div class="flex flex-wrap items-center gap-2">
                @if ($totalFailed > 0)
                    <x-filament::button wire:click="retryFailed" size="sm" color="warning">
                        Retry {{ number_format($totalFailed) }} failed
                    </x-filament::button>

                    {{-- Clearing is distinct from retrying: these rows are a
                         log, so clearing them loses the reasons and cancels
                         nothing. The confirmation says exactly that. --}}
                    {{ $this->clearFailedAction }}
                @endif

                @if ($paused)
                    <x-filament::button wire:click="resume" size="sm" color="success">
                        Resume
                    </x-filament::button>
                @elseif ($totalPending > 0)
                    {{-- Pause, not stop: the worker keeps running and simply
                         declines the next job, so nothing in flight is cut
                         off. --}}
                    <x-filament::button wire:click="pause" size="sm" color="gray">
                        Pause
                    </x-filament::button>
                @endif

                @if ($totalPending > 0)
                    {{ $this->cancelAllAction }}
                @endif

                {{-- Always offered, not only while work is queued: somebody
                     tuning this usually does it *before* starting a big scan,
                     not in the middle of one. --}}
                {{ $this->setConcurrencyAction }}
            </div>
        </x-slot>

        @if ($pending->isEmpty() && $failed->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing queued. Enrichment, covers and duplicate searches appear here while they run.
            </p>
        @endif

        @if ($pending->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Job</th>
                            <th class="py-2 pr-4 font-medium text-right">Waiting</th>
                            <th class="py-2 pr-4 font-medium text-right">Running</th>
                            <th class="py-2 pr-4 font-medium">Oldest</th>
                            <th class="py-2 pr-4 font-medium text-right">Retried</th>
                            <th class="py-2 pr-4 font-medium text-right">Share</th>
                            <th class="py-2 font-medium text-right"><span class="sr-only">Controls</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($pending as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium">
                                    {{-- The row's own name opens it: the detail is about this job,
                                         so there is nothing else for a separate button to mean. --}}
                                    <button
                                        type="button"
                                        wire:click="mountAction('inspect', { job: @js($row['job']) })"
                                        class="text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $row['job'] }}
                                    </button>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['queued']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    @if ($row['running'] > 0)
                                        <span class="text-success-600 dark:text-success-400">{{ $row['running'] }}</span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">
                                    {{ $row['oldest']?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    @if ($row['attempted'] > 0)
                                        <span class="text-warning-600 dark:text-warning-400">{{ $row['attempted'] }}</span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>

                                {{-- Which job the backlog actually is. With one
                                     kind at 6,398 of 6,404 the number alone
                                     reads as "the queue is busy", where the
                                     share says "it is this one". --}}
                                <td class="py-2 pr-4 text-right">
                                    @php $share = $totalPending > 0 ? (int) round($row['queued'] / $totalPending * 100) : 0; @endphp
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-primary-500" style="width: {{ $share }}%"></div>
                                        </div>
                                        <span class="w-9 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">{{ $share }}%</span>
                                    </div>
                                </td>

                                {{-- Per row, because the reason to pause is
                                     almost always one job kind: stopping
                                     everything also stops the cover fetches
                                     and the duplicate scan, which were not the
                                     problem. --}}
                                <td class="py-2 text-right">
                                    @php $jobPaused = $this->isJobPaused($row['job']); @endphp
                                    <x-filament::button
                                        size="xs"
                                        :color="$jobPaused ? 'success' : 'gray'"
                                        wire:click="toggleJob(@js($row['job']))"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleJob"
                                    >
                                        {{ $jobPaused ? 'Resume' : 'Pause' }}
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($failed->isNotEmpty())
            <p class="mt-6 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Failed</p>

            <div class="mt-1 overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($failed as $row)
                            <tr>
                                <td class="py-2 pr-4 align-top font-medium">
                                    <button
                                        type="button"
                                        wire:click="mountAction('inspect', { job: @js($row['job']) })"
                                        class="text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $row['job'] }}
                                    </button>
                                </td>
                                <td class="py-2 pr-4 align-top text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</td>
                                <td class="py-2 pr-4 align-top text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $row['count'] }}</td>
                                <td class="py-2 align-top text-gray-500 dark:text-gray-400">{{ $row['last']?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- The scheduler fills this queue, so its state belongs beside it. --}}
        <p class="mt-6 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Recurring work
        </p>

        <p class="mt-1 text-sm">
            @if ($schedulerAlive)
                <span class="text-gray-500 dark:text-gray-400">
                    The scheduler answered {{ \Illuminate\Support\Carbon::createFromTimestamp($heartbeat)->diffForHumans() }}.
                </span>
            @else
                <span class="text-danger-600 dark:text-danger-400">
                    The scheduler has not checked in for over ten minutes, so none of the recurring work below is running.
                </span>
            @endif
        </p>

        @if ($scheduled->isNotEmpty())
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Task</th>
                            <th class="py-2 pr-4 font-medium">Runs</th>
                            <th class="py-2 pr-4 font-medium">Next</th>
                            <th class="py-2 pr-4 font-medium">Last</th>
                            <th class="py-2 font-medium">Outcome</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($scheduled as $task)
                            <tr>
                                <td class="py-2 pr-4 align-top text-gray-900 dark:text-white">{{ $task['name'] }}</td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-300">{{ $task['frequency'] }}</td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-300">
                                    {{ $task['next_run']?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-300">
                                    {{ $task['last_run']?->diffForHumans() ?? 'Not since last restart' }}
                                </td>
                                <td class="py-2 align-top">
                                    @switch($task['last_outcome'])
                                        @case('ok')
                                            <x-filament::badge color="success">Ran</x-filament::badge>
                                            @break
                                        @case('failed')
                                            <x-filament::badge color="danger">Failed</x-filament::badge>
                                            @break
                                        @case('skipped')
                                            <x-filament::badge color="warning">Skipped</x-filament::badge>
                                            @break
                                        @default
                                            <span class="text-gray-400">—</span>
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Where a mounted action's modal renders. Nothing opens without it. --}}
    <x-filament-actions::modals />
</x-filament-widgets::widget>

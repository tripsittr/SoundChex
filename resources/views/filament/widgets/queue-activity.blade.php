@php
    $pending = $this->pending();
    $failed = $this->failed();
    $totalPending = $this->totalPending();
    $totalFailed = $this->totalFailed();
    $workerSeen = $this->workerSeen();
    $heartbeat = $this->schedulerHeartbeat();
    $scheduled = $this->scheduled();

    $schedulerAlive = $heartbeat !== null && $heartbeat > now()->subMinutes(10)->timestamp;
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Background work</x-slot>

        <x-slot name="description">
            @if ($totalPending === 0 && $totalFailed === 0)
                The queue is empty.
            @else
                {{ number_format($totalPending) }} waiting@if ($totalFailed > 0), {{ number_format($totalFailed) }} failed @endif.
                @if ($totalPending > 0 && ! $workerSeen)
                    {{-- Nothing reserved. Could be between jobs, could be a dead
                         worker; say which it might be rather than asserting. --}}
                    <span class="text-warning-600 dark:text-warning-400">
                        Nothing is being worked on right now — the worker may be idle between jobs, or stopped.
                    </span>
                @endif
            @endif
        </x-slot>

        @if ($totalFailed > 0)
            <x-slot name="headerEnd">
                <x-filament::button wire:click="retryFailed" size="sm" color="warning">
                    Retry {{ number_format($totalFailed) }} failed
                </x-filament::button>
            </x-slot>
        @endif

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
                            <th class="py-2 font-medium text-right">Retried</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($pending as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['job'] }}</td>
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
                                <td class="py-2 text-right tabular-nums">
                                    @if ($row['attempted'] > 0)
                                        <span class="text-warning-600 dark:text-warning-400">{{ $row['attempted'] }}</span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
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
                                <td class="py-2 pr-4 align-top font-medium text-gray-900 dark:text-white">{{ $row['job'] }}</td>
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
</x-filament-widgets::widget>

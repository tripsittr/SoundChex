@php
    $tasks = $this->tasks();
    $heartbeat = $this->heartbeat();

    // Ten minutes is the life the heartbeat is written with, so anything older
    // means nobody has written one since.
    $schedulerAlive = $heartbeat !== null && $heartbeat > now()->subMinutes(10)->timestamp;
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Scheduled jobs</x-slot>

        <x-slot name="description">
            @if ($schedulerAlive)
                The scheduler answered {{ \Illuminate\Support\Carbon::createFromTimestamp($heartbeat)->diffForHumans() }}.
            @else
                <span class="text-danger-600 dark:text-danger-400">
                    The scheduler has not checked in for over ten minutes, so nothing below is running.
                </span>
            @endif
        </x-slot>

        @if ($tasks->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing is scheduled.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Task</th>
                            <th class="py-2 pr-4 font-medium">Runs</th>
                            <th class="py-2 pr-4 font-medium">Next</th>
                            <th class="py-2 pr-4 font-medium">Last run</th>
                            <th class="py-2 font-medium">Outcome</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($tasks as $task)
                            <tr>
                                <td class="py-2 pr-4 align-top">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $task['name'] }}</span>
                                    @if ($task['background'])
                                        <span class="ml-1 text-xs text-gray-400">background</span>
                                    @endif
                                </td>

                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-300">
                                    {{ $task['frequency'] }}
                                    <span class="block font-mono text-xs text-gray-400">{{ $task['expression'] }}</span>
                                </td>

                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-300">
                                    @if ($task['next_run'])
                                        {{ $task['next_run']->format('D H:i') }}
                                        <span class="block text-xs text-gray-400">{{ $task['next_run']->diffForHumans() }}</span>
                                    @else
                                        <span class="text-gray-400">&mdash;</span>
                                    @endif
                                </td>

                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-300">
                                    @if ($task['last_run'])
                                        {{ $task['last_run']->diffForHumans() }}
                                        @if ($task['last_duration_ms'] !== null)
                                            <span class="block text-xs text-gray-400">
                                                took {{ $task['last_duration_ms'] < 1000
                                                    ? $task['last_duration_ms'] . 'ms'
                                                    : round($task['last_duration_ms'] / 1000, 1) . 's' }}
                                            </span>
                                        @endif
                                    @else
                                        {{-- Never, or not since the cache was last cleared. Saying
                                             "never" would be a stronger claim than the data supports. --}}
                                        <span class="text-gray-400">Not since last restart</span>
                                    @endif
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
                                            {{-- withoutOverlapping held it back: the previous run was
                                                 still going. Normal, not a fault. --}}
                                            <x-filament::badge color="warning">Skipped</x-filament::badge>
                                            @break
                                        @default
                                            <span class="text-gray-400">&mdash;</span>
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

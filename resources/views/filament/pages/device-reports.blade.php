<x-filament-panels::page>
    @if ($reports === [])
        <x-filament::section>
            <x-slot name="heading">Nothing reported</x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Devices send a report when something goes wrong &mdash; a navigation
                that had to fall back, an address switch, or an error. An empty list
                means nothing has gone wrong since the last clear-out, which is the
                result worth hoping for.
            </p>
        </x-filament::section>
    @else
        @foreach ($reports as $report)
            <x-filament::section collapsible :collapsed="! $loop->first">
                <x-slot name="heading">
                    {{ $report['platform'] }} &middot; {{ $report['when'] }}
                </x-slot>

                <x-slot name="description">
                    device {{ $report['device'] }}
                    @if ($report['build']) &middot; build {{ $report['build'] }} @endif
                    @if ($report['origin']) &middot; {{ $report['origin'] }} @endif
                </x-slot>

                <div class="space-y-1 font-mono text-xs">
                    @foreach ($report['events'] as $event)
                        <div class="flex flex-wrap gap-x-3 border-b border-gray-100 py-1 last:border-0 dark:border-white/5">
                            <span class="text-gray-400 dark:text-gray-500">{{ $event['at'] ?? '?' }}ms</span>

                            {{-- Colour by whether it describes a failure: a list
                                 where everything looks the same is a list nobody
                                 reads. --}}
                            <span @class([
                                'font-medium',
                                'text-danger-600 dark:text-danger-400' => in_array(
                                    $event['kind'] ?? '', ['error', 'rejection', 'served-offline-page'], true,
                                ),
                                'text-warning-600 dark:text-warning-400' => in_array(
                                    $event['kind'] ?? '', ['navigation-retry', 'switching-address'], true,
                                ),
                                'text-gray-600 dark:text-gray-300' => ! in_array(
                                    $event['kind'] ?? '',
                                    ['error', 'rejection', 'served-offline-page', 'navigation-retry', 'switching-address'],
                                    true,
                                ),
                            ])>{{ $event['kind'] ?? 'unknown' }}</span>

                            <span class="text-gray-500 dark:text-gray-400">{{ $event['path'] ?? '' }}</span>

                            @if (filled($event['detail'] ?? null))
                                <span class="w-full break-all text-gray-400 dark:text-gray-500">
                                    {{ json_encode($event['detail']) }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>

<x-filament-panels::page>
    {{-- Filters. Reports have been arriving for weeks and the only way to read
         them was a tinker script, which in practice meant they were read when
         someone already suspected something rather than when it happened. --}}
    <x-filament::section>
        <x-slot name="heading">Filter</x-slot>

        <div class="grid gap-3 sm:grid-cols-4">
            <label class="block">
                <span class="text-xs uppercase tracking-wide opacity-60">Device</span>
                <select wire:model.live="deviceFilter" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">All devices</option>
                    @foreach ($this->devices() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-wide opacity-60">Type</span>
                <select wire:model.live="kindFilter" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">Any</option>
                    <option value="phone">Phone</option>
                    <option value="tablet">Tablet</option>
                    <option value="desktop">Desktop</option>
                    <option value="unknown">Unknown</option>
                </select>
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-wide opacity-60">Log type</span>
                <select wire:model.live="logFilter" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">Anything</option>
                    <option value=":failed">Failures</option>
                    <option value="&quot;error&quot;">Errors</option>
                    <option value="rejection">Unhandled rejections</option>
                    <option value="download:">Downloads</option>
                    <option value="switching-address">Address switches</option>
                    <option value="served-offline-page">Served offline</option>
                    <option value="reloading-for-build">Build reloads</option>
                </select>
            </label>

            <label class="flex items-end gap-2 pb-1">
                <input type="checkbox" wire:model.live="failuresOnly" class="rounded border-gray-300 dark:border-white/10">
                <span class="text-sm">Only failures</span>
            </label>
        </div>
    </x-filament::section>

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
                    {{ $report['name'] }} &middot; {{ $report['when'] }}
                </x-slot>

                <x-slot name="description">
                    {{ $report['platform'] }}
                    @if ($report['ip']) &middot; {{ $report['ip'] }} @endif
                    @if ($report['at']) &middot; {{ $report['at'] }} @endif
                    <br>
                    @if ($report['build']) build {{ $report['build'] }} @endif
                    {{-- The shell is compiled into the binary and cannot update
                         itself, so it is worth seeing beside the served build. --}}
                    @if ($report['shell']) &middot; shell {{ $report['shell'] }} @endif
                    @if ($report['app_version']) &middot; app {{ $report['app_version'] }} @endif
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

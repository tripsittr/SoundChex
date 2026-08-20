<x-filament-panels::page>
    @if (! $supported)
        <x-filament::section>
            <x-slot name="heading">Not available on this platform</x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Service management uses launchd, which is macOS only. On Linux the
                equivalent is a systemd user unit; on Windows, a scheduled task.
                The processes still need to be running &mdash; this page just
                cannot manage them for you here.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Background services</x-slot>
            <x-slot name="description">
                Kept alive by launchd, so they restart themselves and survive a
                reboot. A process started by hand in a terminal does neither.
            </x-slot>

            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($services as $key => $service)
                    <div class="flex flex-wrap items-center justify-between gap-4 py-4 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                {{-- Colour and text both: a dot alone is unreadable
                                     to anyone who cannot distinguish them. --}}
                                <span @class([
                                    'size-2 rounded-full',
                                    'bg-success-500' => $service['running'],
                                    'bg-danger-500' => ! $service['running'] && $service['installed'],
                                    'bg-warning-500' => ! $service['installed'],
                                ])></span>

                                <span class="font-medium text-sm text-gray-950 dark:text-white">
                                    {{ $service['name'] }}
                                </span>

                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $service['running'] ? 'Running' : ($service['installed'] ? 'Stopped' : 'Not installed') }}
                                </span>
                            </div>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $service['hint'] }}
                            </p>

                            <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                {{ $service['detail'] }}
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-2">
                            @if (! $service['installed'])
                                <x-filament::button size="sm" wire:click="installService('{{ $key }}')">
                                    Install
                                </x-filament::button>
                            @elseif ($service['running'])
                                <x-filament::button size="sm" color="gray" wire:click="stopService('{{ $key }}')">
                                    Stop
                                </x-filament::button>
                            @else
                                <x-filament::button size="sm" wire:click="startService('{{ $key }}')">
                                    Start
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Why this exists</x-slot>

            <div class="prose prose-sm dark:prose-invert max-w-none text-gray-500 dark:text-gray-400">
                <p>
                    Each of these has stopped silently at some point. The web server
                    dying shows on a phone as a white screen with no explanation
                    anywhere. The queue worker stopping is subtler &mdash; the library
                    keeps working while quietly never finishing anything it starts.
                    Without the scheduler, watched folders are never scanned and no
                    backup is ever taken.
                </p>
                <p>
                    Installing them registers a launchd agent per service, which
                    restarts the process if it exits and starts it again after a
                    reboot.
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

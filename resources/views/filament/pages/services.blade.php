<x-filament-panels::page>
    {{-- PHP runtime health: a wrong build disables features silently, so name
         what is missing here rather than let it fail deep in a job. --}}
    <x-filament::section>
        <x-slot name="heading">PHP runtime</x-slot>
        <x-slot name="description">
            The extensions SoundChex needs. A missing one breaks a feature with
            no warning elsewhere &mdash; the bundled server runtime ships them all.
        </x-slot>

        @if ($runtimeHealthy)
            <div class="flex items-center gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-success-500"></span>
                <span class="text-gray-700 dark:text-gray-200">All required extensions present.</span>
            </div>
        @else
            <div class="flex items-center gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-danger-500"></span>
                <span class="font-medium text-danger-600 dark:text-danger-400">
                    Missing required extensions &mdash; features will fail silently.
                </span>
            </div>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($missingRequiredExtensions as $ext => $why)
                    <li class="text-gray-700 dark:text-gray-200">
                        <code class="text-danger-600 dark:text-danger-400">{{ $ext }}</code>
                        <span class="text-gray-500 dark:text-gray-400">&mdash; {{ $why }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if (! empty($missingRecommendedExtensions))
            <p class="mt-4 text-xs font-medium text-gray-500 dark:text-gray-400">Recommended (the app still runs without these):</p>
            <ul class="mt-1 space-y-1 text-xs">
                @foreach ($missingRecommendedExtensions as $ext => $why)
                    <li class="text-gray-500 dark:text-gray-400">
                        <code>{{ $ext }}</code> &mdash; {{ $why }}
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

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
                @if ($checkedAt)
                    <span class="text-gray-400 dark:text-gray-500">Checked at {{ $checkedAt }}.</span>
                @endif
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

                        <div class="flex shrink-0 flex-wrap gap-2">
                            @if (! $service['installed'])
                                <x-filament::button size="sm" wire:click="installService('{{ $key }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="installService('{{ $key }}')">
                                    <span wire:loading.remove wire:target="installService('{{ $key }}')">Install</span>
                                    <span wire:loading wire:target="installService('{{ $key }}')">Installing…</span>
                                </x-filament::button>
                            @else
                                {{-- Both offered whatever the state: a service
                                     that reports "running" while nothing answers
                                     needs stopping before it can be started, and
                                     hiding the button makes that impossible. --}}
                                <x-filament::button size="sm" wire:click="startService('{{ $key }}')"
                                                    :disabled="$service['running']"
                                                    wire:loading.attr="disabled"
                                                    wire:target="startService('{{ $key }}')">
                                    <span wire:loading.remove wire:target="startService('{{ $key }}')">Start</span>
                                    <span wire:loading wire:target="startService('{{ $key }}')">Starting…</span>
                                </x-filament::button>

                                <x-filament::button size="sm" color="gray" wire:click="stopService('{{ $key }}')"
                                                    :disabled="! $service['running']"
                                                    wire:loading.attr="disabled"
                                                    wire:target="stopService('{{ $key }}')">
                                    <span wire:loading.remove wire:target="stopService('{{ $key }}')">Stop</span>
                                    <span wire:loading wire:target="stopService('{{ $key }}')">Stopping…</span>
                                </x-filament::button>
                            @endif

                            <x-filament::button size="sm" color="gray" outlined
                                                wire:click="toggleLog('{{ $key }}')">
                                {{ $showingLog === $key ? 'Hide log' : 'Log' }}
                            </x-filament::button>
                        </div>
                    </div>

                    @if ($showingLog === $key)
                        {{-- A service that will not start says why here, and
                             without this the only way to read it is a terminal —
                             which is what this page exists to avoid. --}}
                        <pre class="mb-4 max-h-64 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-relaxed text-gray-300 dark:bg-black">{{ $logContents }}</pre>
                    @endif
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

<x-filament-panels::page>

    {{-- The trust note, stated plainly and up front (S-264). No plugin is
         sandboxed; enabling one runs its code with the server's full access. --}}
    <x-filament::section>
        <x-slot name="heading">Before you enable a plugin</x-slot>
        <x-slot name="description">
            A plugin is code that runs as part of SoundChex, with the same access to your
            library and settings the server has. Only enable plugins you trust — from an
            author and a source you recognise. Plugins arrive disabled; turning one on is
            your decision.
        </x-slot>

        @php($pluginsPath = $this->pluginsPathForDisplay())

        @if ($pluginsPath !== null)
            <p class="text-sm opacity-70">
                Installed plugins live in the plugins folder on this server:
                <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-white/10">{{ $pluginsPath }}</code>.
                Use <strong>Open Plugins Folder</strong> above to reveal it, add a plugin
                folder, then press <strong>Re-scan</strong>.
            </p>
        @else
            <p class="text-sm opacity-70">
                Installed plugins live in a folder on the server itself. Adding one by
                hand, or opening that folder, is done from the machine running SoundChex —
                not from here. From this remote session you can still browse, install and
                enable plugins below.
            </p>
        @endif
    </x-filament::section>

    {{-- Browse & install from repositories — the Emby-style catalog. --}}
    <x-filament::section>
        <x-slot name="heading">Browse plugins</x-slot>
        <x-slot name="description">
            Install from a repository — a catalogue of plugins at a URL. The official one is
            trusted; anything you add is at your own risk, and every install is checked for
            integrity and compatibility before it lands.
        </x-slot>

        <div class="space-y-4">
            {{-- Repositories --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($this->repositories() as $repo)
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs',
                        'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $repo->official,
                        'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => ! $repo->official,
                    ])>
                        {{ $repo->name }}
                        @if ($repo->official)
                            <span class="opacity-60">official</span>
                        @else
                            <button type="button" wire:click="removeRepository({{ $repo->id }})" class="opacity-50 hover:opacity-100">&times;</button>
                        @endif
                    </span>
                @endforeach
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-0 flex-1">
                    <x-filament::input.wrapper>
                        <x-filament::input type="url" wire:model="newRepositoryUrl" placeholder="https://…/manifest.json" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button color="gray" wire:click="addRepository">Add repository</x-filament::button>
                <x-filament::button wire:click="browse">Refresh catalogue</x-filament::button>
            </div>

            @if ($catalogLoaded)
                @if ($catalog === [])
                    <p class="text-sm opacity-60">Nothing new to install from the configured repositories.</p>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($catalog as $entry)
                            <div class="flex items-start justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ $entry['name'] }}</span>
                                        @if ($entry['version'])
                                            <span class="font-mono text-xs opacity-60">{{ $entry['version'] }}</span>
                                        @endif
                                    </div>
                                    @if ($entry['description'])
                                        <p class="mt-0.5 truncate text-xs opacity-70">{{ $entry['description'] }}</p>
                                    @endif
                                    <p class="mt-0.5 text-xs opacity-50">{{ $entry['repository'] }}</p>
                                </div>
                                @if ($entry['installable'])
                                    <x-filament::button
                                        size="sm"
                                        wire:click="install('{{ $entry['id'] }}')"
                                        wire:confirm="Install {{ $entry['name'] }}? It downloads and installs the plugin, disabled. You enable it after reviewing it.">
                                        Install
                                    </x-filament::button>
                                @else
                                    <x-filament::badge color="gray" size="sm">Needs newer server</x-filament::badge>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>

    @if ($plugins === [])
        <x-filament::section>
            <x-slot name="heading">No plugins installed</x-slot>

            <p class="text-sm opacity-70">
                Nothing is in the plugins directory yet. Building one? The example under
                <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-white/10">plugins/examples/year-tagger</code>
                is a complete, minimal plugin to copy.
            </p>
        </x-filament::section>
    @else
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($plugins as $plugin)
                <x-filament::section>
                    {{-- Heading row: name + version on the left, status badge on the right --}}
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            {{ $plugin['name'] }}
                            <span class="text-xs font-normal text-gray-400 dark:text-gray-500">v{{ $plugin['version'] }}</span>
                        </span>
                    </x-slot>

                    @if ($plugin['author'])
                        <x-slot name="description">by {{ $plugin['author'] }}</x-slot>
                    @endif

                    <x-slot name="afterHeader">
                        @if ($plugin['enabled'])
                            <x-filament::badge color="success">Enabled</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Disabled</x-filament::badge>
                        @endif
                    </x-slot>

                    <div class="space-y-4">
                        @if ($plugin['description'])
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $plugin['description'] }}</p>
                        @endif

                        @if (! empty($plugin['provides']) || $plugin['license'])
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($plugin['provides'] as $seam)
                                    <x-filament::badge color="gray">{{ $seam }}</x-filament::badge>
                                @endforeach
                                @if ($plugin['license'])
                                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">{{ $plugin['license'] }}</span>
                                @endif
                            </div>
                        @endif
                    </div>

                    <x-slot name="footer">
                        <div class="flex justify-end">
                            <x-filament::button
                                :color="$plugin['enabled'] ? 'danger' : 'primary'"
                                :outlined="$plugin['enabled']"
                                wire:click="toggle('{{ $plugin['id'] }}')"
                                :wire:confirm="$plugin['enabled'] ? false : 'Enable ' . $plugin['name'] . '? It runs as part of SoundChex, with full access. Only enable a plugin you trust.'"
                            >
                                {{ $plugin['enabled'] ? 'Disable' : 'Enable' }}
                            </x-filament::button>
                        </div>
                    </x-slot>
                </x-filament::section>
            @endforeach
        </div>
    @endif

</x-filament-panels::page>

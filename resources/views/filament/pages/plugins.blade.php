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

        <p class="text-sm opacity-70">
            Installed plugins live in
            <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-white/10">{{ config('soundchex.plugins.path') }}</code>.
            Add a plugin folder there and press <strong>Re-scan</strong>.
        </p>
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
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($plugins as $plugin)
                <div @class([
                    'flex flex-col gap-3 rounded-xl border p-5 transition',
                    'border-primary-500/40 bg-primary-50/30 dark:bg-primary-500/5' => $plugin['enabled'],
                    'border-gray-200 dark:border-white/10' => ! $plugin['enabled'],
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ $plugin['name'] }}</span>
                                <span class="font-mono text-xs opacity-60">{{ $plugin['version'] }}</span>
                                @if ($plugin['enabled'])
                                    <x-filament::badge color="success" size="sm">Enabled</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="sm">Disabled</x-filament::badge>
                                @endif
                            </div>
                            @if ($plugin['author'])
                                <p class="mt-0.5 text-xs opacity-60">by {{ $plugin['author'] }}</p>
                            @endif
                        </div>

                        <x-filament::button
                            size="sm"
                            :color="$plugin['enabled'] ? 'danger' : 'primary'"
                            :outlined="$plugin['enabled']"
                            wire:click="toggle('{{ $plugin['id'] }}')"
                            :wire:confirm="$plugin['enabled'] ? false : 'Enable ' . $plugin['name'] . '? It runs as part of SoundChex, with full access. Only enable a plugin you trust.'"
                        >
                            {{ $plugin['enabled'] ? 'Disable' : 'Enable' }}
                        </x-filament::button>
                    </div>

                    @if ($plugin['description'])
                        <p class="text-sm opacity-80">{{ $plugin['description'] }}</p>
                    @endif

                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($plugin['provides'] as $seam)
                            <span class="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-xs opacity-70 dark:bg-white/10">{{ $seam }}</span>
                        @endforeach
                        @if ($plugin['license'])
                            <span class="ml-auto text-xs opacity-50">{{ $plugin['license'] }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</x-filament-panels::page>

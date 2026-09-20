<x-filament-panels::page>

    @php
        /** Filtered + grouped on the page object, so ordering and the active
            filter are decisions rather than whatever `groupBy()` returned. */
        $groups = $this->visibleGroups();
        $filters = $this->filterOptions();
    @endphp

    @if (! $this->anyRunning())
        {{-- Not an error. Most installs do not run these, and the useful thing
             to say is what they are and how to start them. --}}
        <x-filament::section>
            <x-slot name="heading">Acquisition apps are not running</x-slot>
            <x-slot name="description">Optional, and separate from SoundChex.</x-slot>

            <div class="space-y-3 text-sm">
                <p>
                    Radarr, Sonarr and Lidarr find new films, television and music.
                    SoundChex catalogues what is already here. They meet at the
                    folder the library scanner watches, so a finished download is
                    picked up on the next scan with nothing needing to tell
                    SoundChex it happened.
                </p>

                <pre class="overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">php artisan arr:setup --start</pre>

                <p class="opacity-60">
                    Nothing downloads until an indexer is added inside each app by
                    hand. None ship with them and none are configured here.
                </p>
            </div>
        </x-filament::section>
    @endif

    {{-- Filter by kind. Chips rather than a select: a handful of categories,
         each worth showing with its connected/total count so the page says at a
         glance what is set up. --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($filters as $option)
            @php $active = $this->filter === $option['value']; @endphp
            <button
                type="button"
                wire:click="setFilter('{{ $option['value'] }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow' => $active,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => ! $active,
                ])
            >
                {{ $option['label'] }}
                <span @class(['text-xs', 'text-white/70' => $active, 'opacity-60' => ! $active])>
                    {{ $option['connected'] }}/{{ $option['total'] }}
                </span>
            </button>
        @endforeach
    </div>

    @foreach ($groups as $group => $rows)
        <x-filament::section>
            <x-slot name="heading">{{ $group }}</x-slot>
            <x-slot name="description">
                {{ collect($rows)->where('connected', true)->count() }} of {{ count($rows) }} connected
            </x-slot>

            {{-- Cards, not a list: each integration is its own tile so the page
                 feels less cramped and a connected one reads as a distinct
                 object rather than a row in a wall of text. --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($rows as $row)
                    <div @class([
                        'flex flex-col justify-between gap-3 rounded-xl border p-4 transition',
                        'border-primary-500/40 bg-primary-50/40 dark:bg-primary-500/5' => $row['connected'],
                        'border-gray-200 dark:border-white/10' => ! $row['connected'],
                    ])>
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-semibold">{{ $row['label'] }}</span>

                                @if ($row['connected'])
                                    <x-filament::badge color="success" size="sm">Connected</x-filament::badge>
                                @endif

                                @if (count($row['warnings']) > 0)
                                    {{-- An app that is up but cannot reach its
                                         download client looks healthy and
                                         quietly does nothing. --}}
                                    <x-filament::badge color="warning" size="sm">
                                        {{ count($row['warnings']) }} warning{{ count($row['warnings']) === 1 ? '' : 's' }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            <p class="truncate text-sm opacity-60">{{ $row['detail'] }}</p>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($row['connected'] || $row['unlinkable'])
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    wire:click="edit('{{ $row['key'] }}')">
                                    Manage
                                </x-filament::button>

                                <x-filament::button
                                    size="sm"
                                    color="danger"
                                    outlined
                                    wire:click="unlink('{{ $row['key'] }}')"
                                    wire:confirm="Forget this key? Nothing is uninstalled — this install just stops talking to it.">
                                    Unlink
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    size="sm"
                                    wire:click="edit('{{ $row['key'] }}')">
                                    Set up
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach

    {{-- One modal for every row: the question is the same in each case, and a
         modal per integration would be fifteen copies of one form. --}}
    @php $editing = $this->editingRow(); @endphp

    <x-filament::modal id="integration" width="lg">

        @if ($editing)
            <x-slot name="heading">{{ $editing['label'] }}</x-slot>

            <x-slot name="description">
                {{ $editing['detail'] }}
            </x-slot>

            <div class="space-y-4">
                @if (count($editing['warnings']) > 0)
                    <div class="space-y-1 rounded-lg bg-warning-50 p-3 text-xs dark:bg-warning-400/10">
                        @foreach ($editing['warnings'] as $warning)
                            <p class="text-warning-700 dark:text-warning-400">{{ $warning }}</p>
                        @endforeach
                    </div>
                @endif

                @if ($editing['group'] === 'Acquisition')
                    {{-- The address, because loopback is only right for our own
                         compose stack. These apps are also installed natively,
                         on a Synology or unRAID box, inside someone else's
                         Docker stack, or on a different machine entirely — none
                         of which answer on 127.0.0.1 from here. --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="integration-url">
                            Address
                        </label>

                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="integration-url"
                                type="url"
                                wire:model="editingUrl"
                                autocomplete="off"
                                placeholder="http://127.0.0.1:8686" />
                        </x-filament::input.wrapper>

                        <p class="mt-2 text-xs opacity-60">
                            Where {{ $editing['label'] }} is reachable from this
                            server. Another machine on the network works — use
                            its address rather than localhost.
                        </p>
                    </div>
                @endif

                <div>
                    <label class="mb-1 block text-sm font-medium" for="integration-key">
                        API key
                    </label>

                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="integration-key"
                            type="password"
                            wire:model="editingKey"
                            wire:keydown.enter="saveModal"
                            autocomplete="off"
                            :placeholder="$editing['connected'] ? 'Enter a new key to replace the stored one' : 'Paste the key'" />
                    </x-filament::input.wrapper>

                    <p class="mt-2 text-xs opacity-60">
                        @if ($editing['group'] === 'Acquisition')
                            Found in {{ $editing['label'] }}'s own Settings &rarr; General.
                        @else
                            Issued by {{ $editing['label'] }}. Used only to enrich the catalogue.
                        @endif
                        Stored encrypted, and never shown again — replace it rather than edit it.
                    </p>
                </div>

                @if ($editing['connected_url'])
                    {{-- No inline handler. Livewire morphs this modal's DOM
                         and strips `onclick`, so every attempt that put the
                         behaviour in an attribute never ran at all — which is
                         why a dead click produced no error and no alert.

                         A marked-up link plus a delegated listener on
                         `document`, which Livewire cannot touch. --}}
                    <a href="{{ $editing['connected_url'] }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       data-open-external="{{ $editing['connected_url'] }}"
                       class="fi-link fi-size-sm inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                        Open {{ $editing['label'] }} &rarr;
                    </a>
                @endif
            </div>

            <x-slot name="footerActions">
                <x-filament::button wire:click="saveModal">
                    {{ $editing['connected'] ? 'Replace key' : 'Connect' }}
                </x-filament::button>

                <x-filament::button color="gray" wire:click="closeModal">
                    Cancel
                </x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>

</x-filament-panels::page>

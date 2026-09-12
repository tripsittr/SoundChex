<x-filament-panels::page>

    @php
        /** Grouped and ordered on the page object, so the order is a decision
            rather than whatever `groupBy()` happened to return. */
        $groups = $this->groupedRows();
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

    @foreach ($groups as $group => $rows)
        <x-filament::section>
            <x-slot name="heading">{{ $group }}</x-slot>
            <x-slot name="description">
                {{ collect($rows)->where('connected', true)->count() }} of {{ count($rows) }} connected
            </x-slot>

            <ul role="list" class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rows as $row)
                    <li class="flex items-center justify-between gap-4 py-3">

                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-medium">{{ $row['label'] }}</span>

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

                            <p class="mt-0.5 truncate text-sm opacity-60">{{ $row['detail'] }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
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

                    </li>
                @endforeach
            </ul>
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
                    <p class="text-sm">
                        <x-filament::link :href="$editing['connected_url']" target="_blank" rel="noopener">
                            Open {{ $editing['label'] }}
                        </x-filament::link>
                    </p>
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

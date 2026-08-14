<x-filament-panels::page>

    {{-- Filament's own section/table components rather than hand-rolled
         Tailwind: a page built out of raw utility classes does not inherit the
         panel's spacing, borders or dark-mode colours, and reads as something
         bolted on. --}}

    <x-filament::section>
        <x-slot name="heading">How devices reach this server</x-slot>
        <x-slot name="description">
            A self-hosted library is usually reachable several ways at once, and
            they are not equally quick — a public tunnel routes every request
            through a relay, while a LAN or VPN address goes straight there.
            Apps use whichever answers first.
        </x-slot>

        @if ($results === [])
            <div class="py-8 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No addresses configured yet.
                </p>
                <x-filament::button wire:click="redetect" size="sm" class="mt-3">
                    Detect this machine's addresses
                </x-filament::button>
            </div>
        @else
            <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:divide-white/10 dark:ring-white/10">
                @foreach ($results as $index => $row)
                    @php
                        $isFastest = $index === 0 && $row['reachable'];
                    @endphp

                    <div @class([
                        'flex flex-wrap items-center gap-3 px-4 py-3',
                        'bg-primary-50 dark:bg-primary-500/5' => $isFastest,
                    ])>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-sm text-gray-950 dark:text-white">
                                {{ $row['address'] }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $this->describe($row['address']) }}
                            </p>
                        </div>

                        @if ($isFastest)
                            <x-filament::badge color="primary">Fastest</x-filament::badge>
                        @endif

                        @if ($row['reachable'])
                            <x-filament::badge :color="$row['ms'] < 100 ? 'success' : ($row['ms'] < 500 ? 'warning' : 'danger')">
                                {{ $row['ms'] }} ms
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray">No answer</x-filament::badge>
                        @endif

                        <x-filament::icon-button
                            icon="heroicon-m-trash"
                            color="gray"
                            size="sm"
                            label="Remove {{ $row['address'] }}"
                            wire:click="removeAddress('{{ $row['address'] }}')" />
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Measured from this machine, so these show whether an address
                answers at all — not what a phone on mobile data would see. An
                address unreachable from here is unreachable for everyone.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Add an address</x-slot>
        <x-slot name="description">
            A public tunnel URL cannot be detected automatically, because it is
            configured outside this app.
        </x-slot>

        <div class="flex flex-col gap-3 sm:flex-row">
            <x-filament::input.wrapper class="flex-1">
                <x-filament::input
                    type="text"
                    wire:model="newAddress"
                    wire:keydown.enter="addAddress"
                    placeholder="http://192.168.1.10:8000" />
            </x-filament::input.wrapper>

            <x-filament::button wire:click="addAddress">Add</x-filament::button>
        </div>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            A LAN or VPN address may use http — those hosts have no certificate
            and need none. Public addresses must use https.
        </p>
    </x-filament::section>

</x-filament-panels::page>

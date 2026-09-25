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
            {{-- fi-ta-ctn is Filament's *table* container and lays its children out
                 horizontally, so the address rows sat side by side and everything
                 past the first ran off the screen. A plain divided stack is what
                 this needs. --}}
            <div class="divide-y divide-gray-200 overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:divide-white/10 dark:ring-white/10">
                @foreach ($results as $index => $row)
                    @php
                        $isFastest = $index === 0 && $row['reachable'];
                    @endphp

                    {{-- Stacked on a phone, one line on a desktop. The address,
                         its badges and the delete button cannot share a row at
                         390px — they were pushing past the screen edge. --}}
                    <div @class([
                        'px-4 py-3',
                        'bg-primary-50 dark:bg-primary-500/5' => $isFastest,
                    ])>
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                {{-- break-all, not truncate: an address is the
                                     one thing on this page worth reading in
                                     full, and half of one is useless. --}}
                                <p class="break-all font-mono text-sm text-gray-950 dark:text-white">
                                    {{ $row['address'] }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $this->describe($row['address']) }}
                                </p>
                            </div>

                            <x-filament::icon-button
                                icon="heroicon-m-trash"
                                color="gray"
                                size="sm"
                                class="shrink-0"
                                label="Remove {{ $row['address'] }}"
                                wire:click="removeAddress('{{ $row['address'] }}')" />
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
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
                        </div>
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

    {{-- DLNA (S-7). Unlike AirPlay there is no per-device picker: the server
         either advertises itself to the whole network or it does not, so the
         choice here is the only control there is. --}}
    <x-filament::section>
        <x-slot name="heading">Share on the local network (DLNA)</x-slot>
        <x-slot name="description">
            Lets TVs, games consoles and network receivers browse the library
            without a SoundChex app. They find it on their own — there is no
            pairing and no password, because the protocol has none.
        </x-slot>

        <div class="space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="dlnaEnabled"
                       class="mt-1 rounded border-gray-300 text-primary-600 dark:border-white/20 dark:bg-white/5">
                <span>
                    <span class="block text-sm font-medium text-gray-950 dark:text-white">
                        Advertise this server
                    </span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">
                        Anything on this Wi-Fi will see it. Never reachable from
                        the internet — discovery does not leave the network, and
                        the server refuses non-local requests.
                    </span>
                </span>
            </label>

            <div class="space-y-1.5">
                <label class="text-sm font-medium text-gray-950 dark:text-white">Show the library as</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="dlnaProfile">
                        <option value="">Choose a profile…</option>
                        @foreach ($this->profileOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    A television cannot say who is watching, so one profile
                    stands for every device. Point it at a rating-capped profile
                    and the cap holds on the living-room TV.
                </p>
            </div>

            <div class="space-y-1.5">
                <label class="text-sm font-medium text-gray-950 dark:text-white">Name shown on devices</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="dlnaName" maxlength="60" placeholder="SoundChex" />
                </x-filament::input.wrapper>
            </div>

            <x-filament::button wire:click="saveDlna">Save</x-filament::button>
        </div>
    </x-filament::section>

</x-filament-panels::page>

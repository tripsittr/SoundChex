<x-filament-panels::page>

    {{-- Signed-in devices: one access token each, naming a device and profile. --}}
    <x-filament::section>
        <x-slot name="heading">Signed-in devices</x-slot>
        <x-slot name="description">
            {{ count($logins) }} {{ \Illuminate\Support\Str::plural('device', count($logins)) }} with a live token.
            A dot means it acted in the last 15 minutes.
        </x-slot>

        @if ($logins === [])
            <p class="text-sm opacity-60">No devices are signed in.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left opacity-60">
                        <tr>
                            <th class="py-2 pr-4">Device</th>
                            <th class="py-2 pr-4">Profile</th>
                            <th class="py-2 pr-4">Signed in</th>
                            <th class="py-2 pr-4">Last active</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logins as $login)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4">
                                    <span @class([
                                        'inline-block h-2 w-2 rounded-full align-middle',
                                        'bg-success-500' => $login['active'],
                                        'bg-gray-300 dark:bg-white/20' => ! $login['active'],
                                    ])></span>
                                    <span class="ml-2 align-middle font-medium">{{ $login['device'] }}</span>
                                </td>
                                <td class="py-2 pr-4">{{ $login['profile'] }}</td>
                                <td class="py-2 pr-4 opacity-70">{{ $login['created'] }}</td>
                                <td class="py-2 pr-4 opacity-70">{{ $login['last_used'] }}</td>
                                <td class="py-2 pr-4 text-right">
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        outlined
                                        wire:click="revoke({{ $login['id'] }})"
                                        wire:confirm="Sign this device out? It will need to log in again.">
                                        Sign out
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- What each profile is playing now or played most recently. --}}
    <x-filament::section>
        <x-slot name="heading">Listening</x-slot>
        <x-slot name="description">
            The latest play per profile in the last day. A dot means it is playing now.
        </x-slot>

        @if ($listening === [])
            <p class="text-sm opacity-60">Nothing has played recently.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left opacity-60">
                        <tr>
                            <th class="py-2 pr-4">Profile</th>
                            <th class="py-2 pr-4">Item</th>
                            <th class="py-2 pr-4">Position</th>
                            <th class="py-2 pr-4">From</th>
                            <th class="py-2 pr-4">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($listening as $play)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4">
                                    <span @class([
                                        'inline-block h-2 w-2 rounded-full align-middle',
                                        'bg-success-500' => $play['active'],
                                        'bg-gray-300 dark:bg-white/20' => ! $play['active'],
                                    ])></span>
                                    <span class="ml-2 align-middle font-medium">{{ $play['profile'] }}</span>
                                </td>
                                <td class="py-2 pr-4">{{ $play['title'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $play['position'] }}</td>
                                <td class="py-2 pr-4 opacity-70">{{ $play['source'] }}</td>
                                <td class="py-2 pr-4 opacity-70">{{ $play['when'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

</x-filament-panels::page>

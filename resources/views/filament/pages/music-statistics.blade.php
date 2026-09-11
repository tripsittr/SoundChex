@php
    /** Seconds as something readable: hours past an hour, minutes below it. */
    $duration = function (?int $seconds): string {
        $seconds = (int) $seconds;

        if ($seconds <= 0) return '—';
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';

        return number_format($seconds / 3600, 1) . 'h';
    };

    $bytes = function (?int $value): string {
        $value = (int) $value;

        if ($value <= 0) return '—';
        if ($value < 1073741824) return number_format($value / 1048576, 0) . ' MB';

        return number_format($value / 1073741824, 2) . ' GB';
    };

    $d = $this->data;
    $labels = $d['labels'];

    /** Passed to every chart, so none can disagree about what is shown. */
    $window = ['range' => $this->range, 'profileId' => $this->profile, 'type' => $this->type];

    /** Changing any filter remounts every chart, rather than some of them. */
    $key = $this->type . '-' . $this->range . '-' . $this->profile;
@endphp

<x-filament-panels::page>

    {{-- Media type. A tab group rather than a fourth dropdown: it is the
         coarsest filter on the page and switching it changes what every other
         control means — an artist becomes a director. --}}
    <x-filament::tabs>
        @foreach ($this->tabs() as $value => $tab)
            <x-filament::tabs.item
                :active="$this->type === $value"
                wire:click="$set('type', '{{ $value }}')"
                :badge="$tab['count'] > 0 ? number_format($tab['count']) : null">
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{-- Window and whose listening, both driving every figure below. --}}
    <x-filament::section>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-filament::input.wrapper>
                <x-slot name="prefix">Period</x-slot>
                <x-filament::input.select wire:model.live="range">
                    @foreach (\App\Filament\Pages\MusicStatisticsPage::RANGES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-slot name="prefix">Profile</x-slot>
                <x-filament::input.select wire:model.live="profile">
                    <option value="">Everyone</option>
                    @foreach ($d['profiles'] as $profile)
                        <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        {{-- The headline figures. A real grid with its own cells rather than an
             inline run of text — eight numbers separated only by whitespace
             read as one long string. --}}
        <div class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-gray-200 sm:grid-cols-4 dark:bg-white/10">
            @foreach ([
                ['Library', $d['library']['seconds'] > 0 ? number_format($d['library']['seconds'] / 3600, 1) . 'h' : '—'],
                [\Illuminate\Support\Str::plural($labels['item']), number_format($d['library']['items'])],
                [\Illuminate\Support\Str::plural($labels['primary']), number_format($d['library']['primary'])],
                [\Illuminate\Support\Str::plural($labels['secondary'] ?? 'Group'), number_format($d['library']['secondary'])],
                ['Listened', $duration($d['listening']['seconds'])],
                ['Plays', number_format($d['listening']['plays'])],
                ['Completion', $d['listening']['completion_rate'] . '%'],
                ['Longest streak', $d['streaks']['longest_streak'] . 'd'],
            ] as [$label, $value])
                <div class="bg-white px-4 py-3 dark:bg-gray-900">
                    <p class="text-xs uppercase tracking-wide opacity-50">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @if ($d['library']['items'] === 0)
        <x-filament::section>
            <p class="py-10 text-center text-sm opacity-60">
                Nothing of this type in the library yet.
            </p>
        </x-filament::section>
    @else
        {{-- Charts, weighted rather than equal boxes. Discovery is full width
             because a thirty-day time series does not fit in half a screen. --}}
        <div class="grid gap-6 xl:grid-cols-2">
            @livewire(\App\Filament\Widgets\Statistics\TopArtistsChart::class, $window, key('primary-' . $key))
            @livewire(\App\Filament\Widgets\Statistics\TopGenresChart::class, $window, key('genres-' . $key))
        </div>

        @livewire(\App\Filament\Widgets\Statistics\DiscoveryChart::class, $window, key('discovery-' . $key))

        <div class="grid gap-6 xl:grid-cols-2">
            @livewire(\App\Filament\Widgets\Statistics\ListeningClockChart::class, $window, key('clock-' . $key))
            @livewire(\App\Filament\Widgets\Statistics\ListeningDaysChart::class, $window, key('days-' . $key))
            @livewire(\App\Filament\Widgets\Statistics\ReachChart::class, $window, key('reach-' . $key))
            @livewire(\App\Filament\Widgets\Statistics\StorageChart::class, $window, key('storage-' . $key))
        </div>

        {{-- The item list as a real table: artwork, badges and the panel's own
             chrome, which the hand-rolled version could not keep up with. --}}
        @livewire(\App\Filament\Widgets\Statistics\TopItemsTable::class, $window, key('items-table-' . $key))

        {{-- The groupings stay as compact lists — an artist or a genre has no
             artwork of its own, so a table would be chrome around two
             columns. --}}
        <div class="grid gap-6 xl:grid-cols-2">
            @foreach ([
                ['Top ' . \Illuminate\Support\Str::plural(strtolower($labels['primary'])), 'primary', 'grouping'],
                ['Top genres', 'genres', 'genre'],
                ['Top playlists', 'playlists', 'name'],
            ] as [$heading, $listKey, $label])
                @php $rows = $d[$listKey]; @endphp

                <x-filament::section collapsible :collapsed="$listKey !== 'primary'">
                    <x-slot name="heading">{{ $heading }}</x-slot>
                    <x-slot name="description">
                        @if (count($rows) === 0)
                            Nothing in this period.
                        @else
                            {{ number_format(count($rows)) }} by plays.
                        @endif
                    </x-slot>

                    @if (count($rows) === 0)
                        <p class="py-8 text-center text-sm opacity-60">
                            @if ($listKey === 'playlists' && $d['library']['playlists'] === 0)
                                No playlists yet.
                            @else
                                No plays recorded in this period.
                            @endif
                        </p>
                    @else
                        <div class="max-h-96 overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 bg-white text-xs uppercase tracking-wide opacity-50 dark:bg-gray-900">
                                    <tr>
                                        <th class="py-2 pr-3 text-left font-medium">#</th>
                                        <th class="py-2 pr-3 text-left font-medium">{{ $label === 'grouping' ? $labels['primary'] : ucfirst($label) }}</th>
                                        <th class="py-2 pr-3 text-right font-medium">Plays</th>
                                        <th class="py-2 text-right font-medium">Time</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($rows as $i => $row)
                                        <tr>
                                            <td class="py-2 pr-3 tabular-nums opacity-40">{{ $i + 1 }}</td>
                                            <td class="max-w-0 truncate py-2 pr-3 font-medium" title="{{ $row->{$label} }}">{{ $row->{$label} ?: '—' }}</td>

                                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row->plays) }}</td>
                                            <td class="py-2 text-right tabular-nums opacity-60">{{ $duration((int) $row->seconds) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>

        {{-- Storage detail and per-profile listening. --}}
        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">Storage by genre</x-slot>
                <x-slot name="description">
                    Estimated from {{ number_format($d['storage']['sampled']) }} sampled files
                    averaging {{ $bytes($d['storage']['average']) }}.
                </x-slot>

                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($d['storage']['genres'] as $row)
                            <tr>
                                <td class="max-w-0 truncate py-2 pr-3">{{ $row->genre ?: '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums opacity-60">{{ number_format($row->tracks) }}</td>
                                <td class="py-2 text-right tabular-nums">~{{ $bytes((int) $row->bytes) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section collapsible collapsed>
                <x-slot name="heading">By profile</x-slot>
                <x-slot name="description">All time. A household shares one login, so this is per person.</x-slot>

                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide opacity-50">
                        <tr>
                            <th class="py-2 pr-3 text-left font-medium">Profile</th>
                            <th class="py-2 pr-3 text-right font-medium">Plays</th>
                            <th class="py-2 text-right font-medium">Listening</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($d['profiles'] as $profile)
                            <tr>
                                <td class="py-2 pr-3">{{ $profile->name }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($profile->plays) }}</td>
                                <td class="py-2 text-right tabular-nums opacity-60">{{ $duration((int) $profile->seconds) }}</td>
                            </tr>
                        @endforeach
                        @if ($d['unattributed'] > 0)
                            <tr class="opacity-50">
                                <td class="py-2 pr-3 italic">Unattributed</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($d['unattributed']) }}</td>
                                <td class="py-2 text-right">—</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </x-filament::section>
        </div>
    @endif

</x-filament-panels::page>

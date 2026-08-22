@php
    $sections = [
        'all'   => 'All',
        'movie' => 'Movies',
        'show'  => 'Shows',
    ];

    // Keep active filters when switching sections, so narrowing to Movies
    // doesn't silently discard a genre or service the user already chose.
    $carry = array_filter($filters);
@endphp

<x-media.layout :counts="$counts" title="Watch">

    @if ($hero)
        <x-media.hero :item="$hero" eyebrow="Recently added" />
    @endif

    <div class="relative z-10 {{ $hero ? '-mt-8 sm:-mt-16' : 'pt-28 sm:pt-32' }}">

        {{-- Movies / Shows sub-nav --}}
        <div class="mb-2 flex items-center gap-2 px-4 sm:px-8">
            @foreach ($sections as $key => $label)
                @php
                    $isActive = $section === $key;
                    $query = $key === 'all' ? $carry : $carry + ['section' => $key];
                @endphp

                <a href="{{ route('media.watch.index', $query) }}"
                   @if($isActive) aria-current="page" @endif
                   class="rounded-full px-4 py-1.5 text-sm font-medium transition
                          {{ $isActive
                              ? 'bg-ink-100 text-base-900'
                              : 'bg-base-700/70 text-ink-300 hover:bg-base-600 hover:text-ink-100' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        {{-- Browse by Service --}}
        @if ($services !== [])
            <section class="px-4 py-4 sm:px-8" aria-label="Browse by service">
                <div class="mb-2 flex items-baseline justify-between gap-4">
                    <h2 class="text-base font-bold tracking-tight sm:text-lg">Browse by Service</h2>

                    @if (filled($filters['service'] ?? null))
                        <a href="{{ route('media.watch.index', array_diff_key($carry, ['service' => ''])) }}"
                           class="text-xs font-medium text-ink-500 transition hover:text-ink-100">
                            Clear
                        </a>
                    @endif
                </div>

                <div class="rail">
                    @foreach ($services as $service)
                        @php $isActive = ($filters['service'] ?? null) === $service['slug']; @endphp

                        <a href="{{ route('media.watch.index', $carry + ['service' => $service['slug']]) }}"
                           class="group relative flex aspect-video flex-col justify-end overflow-hidden rounded-lg p-3
                                  transition hover:brightness-110
                                  {{ $isActive ? 'ring-2 ring-accent' : '' }}"
                           style="background: {{ $service['color'] }}">
                            {{-- A scrim keeps the label legible on the bright
                                 brand colours as well as the near-black ones. --}}
                            <span class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></span>

                            <span class="relative text-sm font-bold leading-tight text-white drop-shadow">
                                {{ $service['name'] }}
                            </span>
                            <span class="relative text-xs text-white/70">{{ $service['count'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Continue Watching --}}
        @if ($continue->isNotEmpty())
            <x-media.rail title="Continue Watching" :items="$continue" />
        @endif

        @foreach ($rows as $row)
            <x-media.rail :title="$row['title']" :items="$row['items']" />
        @endforeach

        {{-- Full grid --}}
        <section class="px-4 pt-8 sm:px-8" aria-label="All titles">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
                <h2 class="text-lg font-bold tracking-tight sm:text-xl">
                    {{ $isFiltered ? 'Results' : 'Everything' }}
                    <span class="ml-1 text-sm font-normal text-ink-500">{{ $items->total() }}</span>
                </h2>

                <form method="GET" class="flex flex-wrap items-center gap-2">
                    @if ($section !== 'all')
                        <input type="hidden" name="section" value="{{ $section }}">
                    @endif

                    @if (filled($filters['service'] ?? null))
                        <input type="hidden" name="service" value="{{ $filters['service'] }}">
                    @endif

                    <label for="filter-search" class="sr-only">Search</label>
                    <input id="filter-search" type="search" name="search"
                           value="{{ $filters['search'] ?? '' }}"
                           placeholder="Filter by title…"
                           class="rounded-md border border-base-500/70 bg-base-800 px-3 py-1.5 text-sm text-ink-100
                                  placeholder:text-ink-500 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">

                    @if ($genres)
                        <label for="filter-genre" class="sr-only">Genre</label>
                        <select id="filter-genre" name="genre"
                                class="rounded-md border border-base-500/70 bg-base-800 px-3 py-1.5 text-sm text-ink-100
                                       focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                            <option value="">All genres</option>
                            @foreach ($genres as $genre)
                                <option value="{{ $genre }}" @selected(($filters['genre'] ?? null) === $genre)>
                                    {{ $genre }}
                                </option>
                            @endforeach
                        </select>
                    @endif

                    <label class="flex cursor-pointer items-center gap-1.5 rounded-md border border-base-500/70 bg-base-800 px-3 py-1.5 text-sm text-ink-300">
                        <input type="checkbox" name="owned" value="1" @checked(($filters['owned'] ?? null) === '1')
                               class="rounded border-base-500 bg-base-700 text-accent focus:ring-accent">
                        Owned
                    </label>

                    <button type="submit"
                            class="rounded-md bg-accent px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-hot">
                        Apply
                    </button>

                    @if ($isFiltered)
                        <a href="{{ route('media.watch.index', $section === 'all' ? [] : ['section' => $section]) }}"
                           class="px-2 py-1.5 text-sm text-ink-500 transition hover:text-ink-100">
                            Clear
                        </a>
                    @endif
                </form>
            </div>

            @if ($items->isEmpty())
                <p class="rounded-lg border border-dashed border-base-500 py-16 text-center text-ink-500">
                    Nothing matches those filters.
                </p>
            @else
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
                    @foreach ($items as $item)
                        <x-media.poster :item="$item" />
                    @endforeach
                </div>

                <div class="mt-8">{{ $items->links() }}</div>
            @endif
        </section>
    </div>

</x-media.layout>

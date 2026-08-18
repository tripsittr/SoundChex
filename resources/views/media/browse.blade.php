<x-media.layout :counts="$counts" :title="str($type->label())->plural()->toString()">

    @if ($hero)
        <x-media.hero :item="$hero" :eyebrow="$type->label()" />
    @endif

    {{-- The header is fixed and translucent, so content passes under it. This
         clearance is what stops the first row of chips sitting against the
         logo and search — it was reduced when the hero was removed, which
         brought them too close. --}}
    <div class="relative z-10 {{ $hero ? '-mt-8 sm:-mt-16' : 'pt-32 sm:pt-36' }}">

        {{-- Music leads with its groupings.
             They used to sit below the rails and above the tab bar, where they
             read as a footer rather than as the navigation they are — the one
             control most likely to be wanted first was the last thing on the
             page. --}}
        @if ($type === \App\Enums\MediaItemType::Music)
            {{-- No page title: the header logo and the active tab already say
                 where this is, and a heading here collided with the logo. --}}
            <div class="px-4 sm:px-8">
                <x-media.music-nav />
            </div>
        @endif

        {{-- Genre rails live on the Genres tab for music. Stacking a dozen
             carousels above the songs list pushed it off the screen. --}}
        @foreach ($rows as $row)
            @continue($type === \App\Enums\MediaItemType::Music && str_starts_with($row['key'] ?? '', 'genre-'))

            <x-media.rail
                :title="$row['title']"
                :items="$row['items']"
 />
        @endforeach

        {{-- Full grid + filters --}}
        <section class="px-4 pt-8 sm:px-8" aria-label="All {{ str($type->label())->plural() }}">

            <div class="mb-4 flex items-baseline justify-between gap-4">
                <h2 class="text-lg font-bold tracking-tight sm:text-xl">
                    {{ $isFiltered ? 'Results' : ($type === \App\Enums\MediaItemType::Music ? 'Songs' : 'All ' . str($type->label())->plural()) }}
                    <span class="ml-1 text-sm font-normal text-ink-500">{{ $items->total() }}</span>
                </h2>

                @if ($isFiltered)
                    <a href="{{ route('media.browse', $type->value) }}"
                       class="shrink-0 text-sm text-ink-500 transition hover:text-ink-100">
                        Clear filters
                    </a>
                @endif
            </div>

            {{-- Filters.

                 Previously a single flex-wrap row of five controls with
                 different heights, which wrapped into a ragged stack on a
                 phone and left the checkboxes floating beside a tall select on
                 a desktop.

                 Now a grid: the search field takes the width it needs, the
                 rest share what is left, and every control is the same height.
                 The toggles are on one line of their own so they read as a
                 pair rather than as two more fields. --}}
            {{-- Collapsed by default. Filtering is occasional, and a permanent
                 search box plus two toggles plus an Apply button pushed the
                 library itself most of a screen down. Open when in use, so a
                 filtered page still shows what it is filtered by. --}}
            <details class="browse-filters-wrap" @if ($isFiltered) open @endif>
                <summary class="browse-filters-toggle">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M3 6h18M6 12h12M10 18h4" stroke-linecap="round" />
                    </svg>
                    Filter
                    @if ($isFiltered)<span class="browse-filters-dot" aria-label="filters active"></span>@endif
                </summary>

            <form method="GET" class="browse-filters">
                <div class="browse-filters__row">
                    <label for="filter-search" class="sr-only">Search {{ $type->label() }}</label>
                    <input id="filter-search"
                           type="search"
                           name="search"
                           value="{{ $filters['search'] ?? '' }}"
                           placeholder="Filter by title…"
                           class="browse-filters__field browse-filters__search">

                    @if ($genres)
                        <label for="filter-genre" class="sr-only">Genre</label>
                        <select id="filter-genre" name="genre" class="browse-filters__field">
                            <option value="">All genres</option>
                            @foreach ($genres as $genre)
                                <option value="{{ $genre }}" @selected(($filters['genre'] ?? null) === $genre)>
                                    {{ $genre }}
                                </option>
                            @endforeach
                        </select>
                    @endif

                    <button type="submit" class="browse-filters__apply">Apply</button>
                </div>

                <div class="browse-filters__toggles">
                    <label class="browse-filters__toggle">
                        <input type="checkbox" name="owned" value="1"
                               @checked(($filters['owned'] ?? null) === '1')
                               class="browse-filters__checkbox">
                        Owned
                    </label>

                    <label class="browse-filters__toggle">
                        <input type="checkbox" name="wishlist" value="1"
                               @checked(($filters['wishlist'] ?? null) === '1')
                               class="browse-filters__checkbox">
                        Wishlist
                    </label>
                </div>
            </form>
            </details>

            @if ($items->isEmpty())
                <p class="rounded-lg border border-dashed border-base-500 py-16 text-center text-ink-500">
                    Nothing matches those filters.
                </p>
            @else
                @if ($type === \App\Enums\MediaItemType::Music)
                    {{-- Music lists rather than grids: a poster grid cannot
                         show duration or per-track actions, and every song
                         needs play, download and the overflow menu without
                         hovering a tile to find them. --}}
                    @php
                        $queue = $items->getCollection()
                            ->map(fn ($track) => $track->playerPayload())
                            ->values();
                    @endphp

                    <ol class="divide-y divide-base-700/40">
                        @foreach ($items as $index => $item)
                            <x-media.song-row :item="$item" :queue="$queue" :index="$index" />
                        @endforeach
                    </ol>
                @else
                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
                        @foreach ($items as $item)
                            <x-media.poster :item="$item" />
                        @endforeach
                    </div>
                @endif

                <div class="mt-8">
                    {{ $items->links() }}
                </div>
            @endif
        </section>
    </div>

</x-media.layout>

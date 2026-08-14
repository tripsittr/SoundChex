<x-media.layout :counts="$counts" :title="str($type->label())->plural()->toString()">

    @if ($hero)
        <x-media.hero :item="$hero" :eyebrow="$type->label()" />
    @endif

    <div class="relative z-10 {{ $hero ? '-mt-8 sm:-mt-16' : 'pt-28 sm:pt-32' }}">

        @foreach ($rows as $row)
            <x-media.rail
                :title="$row['title']"
                :items="$row['items']"
 />
        @endforeach

        {{-- Full grid + filters --}}
        <section class="px-4 pt-8 sm:px-8" aria-label="All {{ str($type->label())->plural() }}">

            {{-- Music gets two things the other types do not: albums are how
                 music is actually organised, and shuffling the whole library
                 only makes sense for tracks. --}}
            @if ($type === \App\Enums\MediaItemType::Music)
                <div class="mb-5 flex flex-wrap items-center gap-2">
                    <a href="{{ route('media.albums') }}"
                       class="inline-flex items-center gap-2 rounded-full border border-base-500 px-5 py-2 text-sm font-medium text-ink-200 transition hover:border-ink-500 hover:text-ink-100">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <circle cx="12" cy="12" r="2.5" />
                        </svg>
                        Browse albums
                    </a>

                    <button type="button"
                            data-shuffle-library
                            class="inline-flex items-center gap-2 rounded-full bg-accent px-5 py-2 text-sm font-semibold text-white transition hover:bg-accent-hot disabled:opacity-60">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span data-shuffle-label>Shuffle everything</span>
                    </button>
                </div>
            @endif

            <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
                <h2 class="text-lg font-bold tracking-tight sm:text-xl">
                    {{ $isFiltered ? 'Results' : 'All ' . str($type->label())->plural() }}
                    <span class="ml-1 text-sm font-normal text-ink-500">{{ $items->total() }}</span>
                </h2>

                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <label for="filter-search" class="sr-only">Search {{ $type->label() }}</label>
                    <input id="filter-search"
                           type="search"
                           name="search"
                           value="{{ $filters['search'] ?? '' }}"
                           placeholder="Filter by title…"
                           class="rounded-md border border-base-500/70 bg-base-800 px-3 py-1.5 text-sm text-ink-100
                                  placeholder:text-ink-500 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">

                    @if ($genres)
                        <label for="filter-genre" class="sr-only">Genre</label>
                        <select id="filter-genre"
                                name="genre"
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
                        <input type="checkbox" name="owned" value="1"
                               @checked(($filters['owned'] ?? null) === '1')
                               class="rounded border-base-500 bg-base-700 text-accent focus:ring-accent">
                        Owned
                    </label>

                    <label class="flex cursor-pointer items-center gap-1.5 rounded-md border border-base-500/70 bg-base-800 px-3 py-1.5 text-sm text-ink-300">
                        <input type="checkbox" name="wishlist" value="1"
                               @checked(($filters['wishlist'] ?? null) === '1')
                               class="rounded border-base-500 bg-base-700 text-accent focus:ring-accent">
                        Wishlist
                    </label>

                    <button type="submit"
                            class="rounded-md bg-accent px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-hot">
                        Apply
                    </button>

                    @if ($isFiltered)
                        <a href="{{ route('media.browse', $type->value) }}"
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

                <div class="mt-8">
                    {{ $items->links() }}
                </div>
            @endif
        </section>
    </div>

</x-media.layout>

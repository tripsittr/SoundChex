<x-media.layout :counts="$counts" title="Search">

    <section class="px-4 pt-28 sm:pt-32 sm:px-8">

        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
            @if ($term !== '')
                Results for “{{ $term }}”
            @else
                Search your library
            @endif
        </h1>

        @if ($term !== '')
            <p class="mt-1 text-sm text-ink-500">
                {{ $results->count() }} {{ str('item')->plural($results->count()) }} found
            </p>
        @endif

        @if ($term === '')
            <p class="mt-8 text-ink-300">
                Type in the search box above to find anything in your catalog.
            </p>
        @elseif ($results->isEmpty())
            <div class="mt-12 rounded-lg border border-dashed border-base-500 py-16 text-center">
                <p class="text-ink-300">Nothing matched “{{ $term }}”.</p>
                <p class="mt-1 text-sm text-ink-500">Try a different title, artist, or album.</p>
            </div>
        @else
            <div class="mt-6 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
                @foreach ($results as $item)
                    <x-media.poster :item="$item" />
                @endforeach
            </div>
        @endif
    </section>

</x-media.layout>

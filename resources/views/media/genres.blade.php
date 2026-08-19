<x-media.layout title="Genres">
    <div class="clears-header mx-auto max-w-7xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8">
        <h1 class="mb-4 text-2xl font-bold text-ink-100 sm:text-3xl">Genres</h1>

        <x-media.music-nav />

        @if ($rows === [])
            <p class="py-16 text-center text-ink-500">
                No genres yet. Tracks are grouped here once metadata has been
                fetched for them.
            </p>
        @else
            {{-- Negative margin: the rail component pads itself for a
                 full-bleed page, and this one is already inside a container. --}}
            <div class="-mx-4 sm:-mx-8">
                @foreach ($rows as $row)
                    <x-media.rail :title="$row['title']" :items="$row['items']" />
                @endforeach
            </div>
        @endif
    </div>
</x-media.layout>

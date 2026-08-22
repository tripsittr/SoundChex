<x-media.layout title="Artists">
    <div class="clears-header mx-auto max-w-7xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8">
        <div class="mb-4 flex items-baseline justify-between gap-4">
            <h1 class="text-2xl font-bold text-ink-100 sm:text-3xl">Artists</h1>
            <p class="text-sm text-ink-500">{{ number_format($artists->total()) }}</p>
        </div>

        <x-media.music-nav />

        @if ($artists->isEmpty())
            <p class="py-16 text-center text-ink-500">
                No artists yet. Tracks need an artist tag to be grouped here.
            </p>
        @else
            <ul class="mt-6 grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                @foreach ($artists as $artist)
                    @php
                        $cover = \App\Models\MediaItem::find($artist->sample_item_id)?->coverUrl();
                    @endphp
                    <li>
                        <a href="{{ route('media.artist', ['name' => $artist->artist]) }}" class="group block text-center">
                            {{-- Circular, the convention for a person rather
                                 than a release. --}}
                            <div class="mx-auto aspect-square w-full overflow-hidden rounded-full bg-base-700 shadow-lg shadow-black/30">
                                @if ($cover)
                                    <img src="{{ $cover }}" alt="" loading="lazy"
                                         class="size-full object-cover transition duration-300 group-hover:scale-105">
                                @else
                                    <div class="flex size-full items-center justify-center text-2xl text-ink-600">
                                        {{ str($artist->artist)->substr(0, 1)->upper() }}
                                    </div>
                                @endif
                            </div>
                            <p class="mt-2 truncate text-sm font-medium text-ink-100">{{ $artist->artist }}</p>
                            <p class="truncate text-xs text-ink-500">
                                {{ $artist->track_count }} {{ Str::plural('track', $artist->track_count) }}
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-10">{{ $artists->withQueryString()->links() }}</div>
        @endif
    </div>
</x-media.layout>

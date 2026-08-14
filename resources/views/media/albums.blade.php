<x-media.layout title="Albums">
    <div class="mx-auto max-w-7xl px-4 pb-16 pt-20 sm:px-8">
        <div class="mb-6 flex items-baseline justify-between gap-4">
            <h1 class="text-2xl font-bold text-ink-100 sm:text-3xl">Albums</h1>
            <p class="text-sm text-ink-500">{{ number_format($albums->total()) }} albums</p>
        </div>

        @if ($albums->isEmpty())
            <p class="py-16 text-center text-ink-500">
                No albums yet. Tracks need an album tag to be grouped here.
            </p>
        @else
            <div class="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                @foreach ($albums as $album)
                    @php
                        $sample = \App\Models\MediaItem::find($album->sample_item_id);
                        $cover = $sample?->coverUrl();
                    @endphp
                    <a href="{{ route('media.album', ['artist' => $album->artist, 'album' => $album->album]) }}"
                       class="group block">
                        <div class="aspect-square overflow-hidden rounded-lg bg-base-700 shadow-lg shadow-black/30 transition group-hover:shadow-xl group-hover:shadow-black/50">
                            @if ($cover)
                                <img src="{{ $cover }}" alt=""
                                     loading="lazy"
                                     class="size-full object-cover transition duration-300 group-hover:scale-105">
                            @else
                                <div class="flex size-full items-center justify-center text-3xl text-ink-600">♪</div>
                            @endif
                        </div>
                        <p class="mt-2 truncate text-sm font-medium text-ink-100">{{ $album->album }}</p>
                        <p class="truncate text-xs text-ink-500">
                            {{ $album->artist ?: 'Unknown artist' }}
                            <span class="text-ink-600">·</span>
                            {{ $album->track_count }}
                        </p>
                    </a>
                @endforeach
            </div>

            <div class="mt-10">{{ $albums->withQueryString()->links() }}</div>
        @endif
    </div>
</x-media.layout>

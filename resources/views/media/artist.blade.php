@php
    /**
     * One artist: their albums, then any tracks belonging to none.
     *
     * Singles get the same row treatment as an album's tracks, so playing one
     * queues the rest behind it rather than playing in isolation.
     */
    $queue = $singles->map(fn ($track) => $track->playerPayload())->values();
@endphp

<x-media.layout :title="$artist">
    <div class="clears-header mx-auto max-w-6xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8">

        <p class="text-xs font-medium uppercase tracking-wider text-ink-500">Artist</p>
        <h1 class="mt-1 text-2xl font-bold text-ink-100 sm:text-4xl">{{ $artist }}</h1>
        <p class="mt-2 text-sm text-ink-500">
            {{ $albums->count() }} {{ Str::plural('album', $albums->count()) }}
            @if ($singles->isNotEmpty())
                <span class="text-ink-600">·</span>
                {{ $singles->count() }} {{ Str::plural('single', $singles->count()) }}
            @endif
        </p>

        @if ($albums->isNotEmpty())
            <h2 class="mb-4 mt-10 text-lg font-semibold text-ink-100">Albums</h2>

            <div class="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($albums as $album)
                    @php
                        $sample = \App\Models\MediaItem::find($album->sample_item_id);
                        $cover = $sample?->coverUrl();
                    @endphp
                    <a href="{{ route('media.album', ['artist' => $album->artist, 'album' => $album->album]) }}"
                       class="group block">
                        <div class="aspect-square overflow-hidden rounded-lg bg-base-700 shadow-lg shadow-black/30 transition group-hover:shadow-xl">
                            @if ($cover)
                                <img src="{{ $cover }}" alt="" loading="lazy"
                                     class="size-full object-cover transition duration-300 group-hover:scale-105">
                            @else
                                <div class="flex size-full items-center justify-center text-3xl text-ink-600">♪</div>
                            @endif
                        </div>
                        <p class="mt-2 truncate text-sm font-medium text-ink-100">{{ $album->album }}</p>
                        <p class="truncate text-xs text-ink-500">
                            {{ $album->track_count }} {{ Str::plural('track', $album->track_count) }}
                        </p>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($singles->isNotEmpty())
            <h2 class="mb-2 mt-12 text-lg font-semibold text-ink-100">Singles</h2>

            <ol class="divide-y divide-base-700/60">
                @foreach ($singles as $index => $track)
                    <li data-long-press-menu class="group flex items-center gap-3 py-2.5">
                        <button type="button"
                                data-play="{{ $queue->toJson() }}"
                                data-play-index="{{ $index }}"
                                class="relative flex size-8 shrink-0 items-center justify-center rounded text-sm tabular-nums text-ink-500 transition hover:bg-base-700 hover:text-ink-100"
                                aria-label="Play {{ $track->title }}">
                            <span class="group-hover:opacity-0">{{ $index + 1 }}</span>
                            <svg class="absolute size-4 opacity-0 transition group-hover:opacity-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>

                        <a href="{{ route('media.show', $track) }}"
                           class="min-w-0 flex-1 truncate text-sm text-ink-100 hover:underline">
                            {{ $track->title }}
                        </a>

                        <x-media.track-menu :items="collect([$track])" :label="$track->title" />
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-media.layout>

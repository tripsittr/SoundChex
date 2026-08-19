@php
    /**
     * One playlist. Shares the album view's queue approach: one array built
     * here, every play control indexes into it, so starting at track 5 leaves
     * the rest queued behind it.
     */
    $queue = $tracks->map(fn ($track) => $track->playerPayload())->values();

    $downloadable = $tracks->map(fn ($track) => [
        'id' => $track->id,
        'title' => $track->title,
        'size' => $track->playbackSize() ?? 0,
        'url' => route('media.stream', $track),
    ])->values();
@endphp

<x-media.layout :title="$playlist->name">
    <div class="clears-header mx-auto max-w-4xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8">

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wider text-ink-500">Playlist</p>
                <h1 class="mt-1 text-2xl font-bold text-ink-100 sm:text-3xl">{{ $playlist->name }}</h1>
                <p class="mt-1 text-sm text-ink-500">
                    {{ $tracks->count() }} {{ Str::plural('track', $tracks->count()) }}
                </p>
                @if ($playlist->description)
                    <p class="mt-2 max-w-prose text-sm text-ink-400">{{ $playlist->description }}</p>
                @endif
            </div>

            <form method="POST" action="{{ route('media.playlists.destroy', $playlist) }}"
                  onsubmit="return confirm('Delete this playlist? The tracks themselves are not deleted.')">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-md border border-base-600 px-3 py-1.5 text-xs font-medium text-ink-400 transition hover:border-rose-500/60 hover:text-rose-300">
                    Delete playlist
                </button>
            </form>
        </div>

        @if ($tracks->isNotEmpty())
            <div class="mt-6 flex flex-wrap items-center gap-2">
                <button type="button"
                        data-play="{{ $queue->toJson() }}"
                        data-play-index="0"
                        class="inline-flex items-center gap-2 rounded-full bg-accent px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-hot">
                    <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M8 5v14l11-7z" />
                    </svg>
                    Play
                </button>

                <button type="button"
                        data-play="{{ $queue->toJson() }}"
                        data-play-shuffle="true"
                        class="inline-flex items-center gap-2 rounded-full border border-base-500 px-5 py-2.5 text-sm font-medium text-ink-200 transition hover:border-ink-500 hover:text-ink-100">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Shuffle
                </button>

                <button type="button"
                        data-download-batch
                        data-state="idle"
                        data-tracks="{{ $downloadable->toJson() }}"
                        class="download-btn inline-flex items-center gap-2 rounded-full border border-base-500 px-5 py-2.5 text-sm font-medium text-ink-200 transition hover:border-ink-500 hover:text-ink-100">
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                    </svg>
                    <span data-download-label>Download all</span>
                </button>
            </div>

            <p id="download-status" class="download-status mt-3 hidden"></p>

            {{-- data-reorderable turns on drag-to-reorder; the URL travels
                 with the list so the script needs no route helper. --}}
            <ol class="mt-8 divide-y divide-base-700/60"
                data-reorderable
                data-reorder-url="{{ route('media.playlists.reorder', $playlist) }}">
                @foreach ($tracks as $index => $track)
                    <li data-long-press-menu class="group flex items-center gap-2 bg-base-900 py-2.5"
                        data-track-row="{{ $track->id }}">

                        {{-- Dragging is restricted to the handle so the list
                             can still be scrolled with a finger. --}}
                        <button type="button"
                                data-drag-handle
                                class="flex size-8 shrink-0 cursor-grab touch-none items-center justify-center rounded text-ink-600 transition hover:bg-base-700 hover:text-ink-300 active:cursor-grabbing"
                                aria-label="Reorder {{ $track->title }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 8h16M4 16h16" stroke-linecap="round" />
                            </svg>
                        </button>

                        <button type="button"
                                data-play="{{ $queue->toJson() }}"
                                data-play-index="{{ $index }}"
                                class="relative flex size-8 shrink-0 items-center justify-center rounded text-sm tabular-nums text-ink-500 transition hover:bg-base-700 hover:text-ink-100"
                                aria-label="Play {{ $track->title }}">
                            <span data-track-number class="group-hover:opacity-0">{{ $index + 1 }}</span>
                            <svg class="absolute size-4 opacity-0 transition group-hover:opacity-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('media.show', $track) }}" class="block truncate text-sm text-ink-100 hover:underline">
                                {{ $track->title }}
                            </a>
                            <span class="block truncate text-xs text-ink-500">{{ $track->subtitle() }}</span>
                        </div>

                        {{-- Removing is a form rather than a fetch: it is rare,
                             and a full round trip keeps the list authoritative
                             instead of guessing at the new order. --}}
                        <form method="POST"
                              action="{{ route('media.playlists.items.remove', ['collection' => $playlist, 'item' => $track]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="flex size-8 shrink-0 items-center justify-center rounded text-ink-600 opacity-0 transition hover:bg-base-700 hover:text-rose-300 focus:opacity-100 group-hover:opacity-100"
                                    aria-label="Remove {{ $track->title }} from this playlist">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                                </svg>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ol>
        @else
            <p class="py-16 text-center text-ink-500">
                Nothing here yet. Use “Add to playlist” on any album or track.
            </p>
        @endif
    </div>
</x-media.layout>

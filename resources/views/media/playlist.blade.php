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
    {{-- The queue, once. Every play control below points into it by index
         rather than carrying its own copy. --}}
    <div class="clears-header mx-auto max-w-4xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8"
         data-play-queue="{{ $queue->toJson() }}">

        {{-- Spotify/Apple-style header: a big cover beside the title block, over
             a soft gradient. Editing (rename, description, cover) is behind the
             pencil; deleting is in the same menu. --}}
        <div x-data="{ editing: false }">
            <div class="flex flex-col items-center gap-5 text-center sm:flex-row sm:items-end sm:text-left">
                <x-media.playlist-cover
                    :url="$playlist->artworkUrl()"
                    :mosaic="$mosaic->all()"
                    class="w-44 shrink-0 shadow-2xl sm:w-52" />

                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium uppercase tracking-wider text-ink-500">Playlist</p>
                    <h1 class="mt-1 text-3xl font-bold text-ink-100 sm:text-5xl">{{ $playlist->name }}</h1>
                    @if ($playlist->description)
                        <p class="mt-3 max-w-prose text-sm text-ink-400">{{ $playlist->description }}</p>
                    @endif
                    <p class="mt-3 text-sm text-ink-500">
                        {{ $tracks->count() }} {{ Str::plural('song', $tracks->count()) }}
                    </p>

                    <button type="button" @click="editing = true"
                            class="mt-4 inline-flex items-center gap-1.5 rounded-full border border-base-600 px-3 py-1.5 text-xs font-medium text-ink-300 transition hover:border-ink-500 hover:text-ink-100">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Edit
                    </button>
                </div>
            </div>

            {{-- Edit modal: rename, description, cover upload, and delete. One
                 multipart form to the update route; delete is its own form. --}}
            <div x-show="editing" x-cloak @keydown.escape.window="editing = false"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div @click.outside="editing = false"
                     class="w-full max-w-md rounded-xl border border-base-600 bg-base-800 p-5 shadow-2xl">
                    <h2 class="text-lg font-semibold text-ink-100">Edit playlist</h2>

                    <form method="POST" action="{{ route('media.playlists.update', $playlist) }}"
                          enctype="multipart/form-data" class="mt-4 space-y-4">
                        @csrf
                        @method('PATCH')

                        <div class="flex items-center gap-4"
                             x-data="{ preview: @js($playlist->artworkUrl()) }">
                            <label class="group relative size-24 shrink-0 cursor-pointer overflow-hidden rounded-lg bg-base-700">
                                <template x-if="preview">
                                    <img :src="preview" alt="" class="size-full object-cover">
                                </template>
                                <div class="absolute inset-0 flex items-center justify-center bg-black/40 text-white opacity-0 transition group-hover:opacity-100">
                                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="12" cy="13" r="4" />
                                    </svg>
                                </div>
                                <input type="file" name="cover" accept="image/*" class="hidden"
                                       @change="const f=$event.target.files[0]; if(f) preview=URL.createObjectURL(f)">
                            </label>
                            <p class="text-xs text-ink-500">Tap to choose a cover image (up to 5&nbsp;MB). Optional — a mosaic of the tracks is used otherwise.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-ink-400">Name</label>
                            <input type="text" name="name" required maxlength="120" value="{{ $playlist->name }}"
                                   class="mt-1 w-full rounded-md border border-base-600 bg-base-900 px-3 py-2 text-sm text-ink-100 focus:border-accent focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-400">Description</label>
                            <textarea name="description" maxlength="1000" rows="3"
                                      class="mt-1 w-full rounded-md border border-base-600 bg-base-900 px-3 py-2 text-sm text-ink-100 focus:border-accent focus:outline-none">{{ $playlist->description }}</textarea>
                        </div>

                        <div class="flex items-center justify-between gap-2 pt-1">
                            <button type="submit"
                                    class="rounded-full bg-accent px-5 py-2 text-sm font-semibold text-white transition hover:bg-accent-hot">
                                Save
                            </button>
                            <button type="button" @click="editing = false"
                                    class="rounded-full border border-base-600 px-4 py-2 text-sm text-ink-300 transition hover:text-ink-100">
                                Cancel
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('media.playlists.destroy', $playlist) }}"
                          onsubmit="return confirm('Delete this playlist? The songs stay in your library.')"
                          class="mt-4 border-t border-base-700 pt-4">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="text-sm font-medium text-rose-400 transition hover:text-rose-300">
                            Delete playlist
                        </button>
                    </form>
                </div>
            </div>
        </div>

        @if ($tracks->isNotEmpty())
            <div class="mt-6 flex flex-wrap items-center gap-2">
                <button type="button"
                        data-play-index="0"
                        class="inline-flex items-center gap-2 rounded-full bg-accent px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-accent-hot">
                    <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M8 5v14l11-7z" />
                    </svg>
                    Play
                </button>

                <button type="button"
                        data-play-index="0"
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

            {{-- Filter to just the tracks that are downloaded (S-116). --}}
            <div class="mt-8 flex justify-end">
                @include('media.partials.downloaded-toggle')
            </div>

            {{-- data-reorderable turns on drag-to-reorder; the URL travels
                 with the list so the script needs no route helper. --}}
            <ol class="mt-2 divide-y divide-base-700/60"
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

                        {{-- The row carries data-long-press-menu, and a held
                             finger looked for a menu that was not there. Every
                             other place a song appears has one. --}}
                        <x-media.track-menu :items="collect([$track])" :label="$track->title" />
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

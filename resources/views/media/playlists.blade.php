<x-media.layout title="Playlists">
    <div class="clears-header mx-auto max-w-6xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8">
        <h1 class="text-2xl font-bold text-ink-100 sm:text-3xl">Playlists</h1>

        <x-media.music-nav />

        @if (session('status'))
            <p class="mt-4 rounded-md border border-accent/40 bg-accent/10 px-4 py-2 text-sm text-ink-200">
                {{ session('status') }}
            </p>
        @endif

        {{-- A cover-card grid, Spotify/Apple style. The first tile creates a new
             playlist; the rest are the account's, each with its cover (image or
             a mosaic of its tracks' art). --}}
        <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {{-- New playlist tile: the form is revealed inline so creating stays
                 one action without leaving the grid. --}}
            <div x-data="{ creating: false }" class="group">
                <button type="button" x-show="!creating" @click="creating = true"
                        class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-base-500 bg-base-800/50 text-ink-400 transition hover:border-accent hover:text-ink-200">
                    <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                    </svg>
                    <span class="text-sm font-medium">New playlist</span>
                </button>

                <form x-show="creating" x-cloak method="POST" action="{{ route('media.playlists.store') }}"
                      class="flex aspect-square w-full flex-col justify-center gap-2 rounded-lg border border-base-600 bg-base-800 p-3">
                    @csrf
                    <input type="text" name="name" required maxlength="120" placeholder="Playlist name"
                           x-init="$nextTick(() => $el.focus())"
                           class="w-full rounded-md border border-base-600 bg-base-900 px-3 py-2 text-sm text-ink-100 placeholder:text-ink-600 focus:border-accent focus:outline-none">
                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 rounded-md bg-accent px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-hot">
                            Create
                        </button>
                        <button type="button" @click="creating = false"
                                class="rounded-md border border-base-600 px-3 py-1.5 text-sm text-ink-400 transition hover:text-ink-200">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            @foreach ($playlists as $playlist)
                <a href="{{ route('media.playlist', $playlist) }}"
                   class="group block rounded-lg p-2 transition hover:bg-base-800/60">
                    <x-media.playlist-cover
                        :url="$playlist->artworkUrl()"
                        :mosaic="$mosaics[$playlist->id] ?? []"
                        class="shadow-lg" />
                    <p class="mt-2 truncate text-sm font-semibold text-ink-100">{{ $playlist->name }}</p>
                    <p class="truncate text-xs text-ink-500">
                        {{ $playlist->media_items_count }} {{ Str::plural('song', $playlist->media_items_count) }}
                    </p>
                </a>
            @endforeach
        </div>

        @if ($playlists->isEmpty())
            <p class="py-12 text-center text-ink-500">
                No playlists yet. Create one above, then add tracks from any album or song.
            </p>
        @endif
    </div>
</x-media.layout>

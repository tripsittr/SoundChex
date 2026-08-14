<x-media.layout title="Playlists">
    <div class="mx-auto max-w-4xl px-4 pb-16 pt-20 sm:px-8">
        <h1 class="text-2xl font-bold text-ink-100 sm:text-3xl">Playlists</h1>

        <x-media.music-nav />

        @if (session('status'))
            <p class="mt-4 rounded-md border border-accent/40 bg-accent/10 px-4 py-2 text-sm text-ink-200">
                {{ session('status') }}
            </p>
        @endif

        {{-- Creating one is the primary action on an empty page, so the form
             comes first rather than hiding behind a button. --}}
        <form method="POST" action="{{ route('media.playlists.store') }}"
              class="mt-6 flex flex-col gap-2 sm:flex-row">
            @csrf
            <input type="text" name="name" required maxlength="120"
                   placeholder="New playlist name"
                   class="min-w-0 flex-1 rounded-md border border-base-600 bg-base-800 px-3 py-2 text-sm text-ink-100 placeholder:text-ink-600 focus:border-accent focus:outline-none">
            <button type="submit"
                    class="shrink-0 rounded-md bg-accent px-5 py-2 text-sm font-semibold text-white transition hover:bg-accent-hot">
                Create
            </button>
        </form>

        @if ($playlists->isEmpty())
            <p class="py-16 text-center text-ink-500">
                No playlists yet. Create one above, then add tracks from any album or song.
            </p>
        @else
            <ul class="mt-8 divide-y divide-base-700/60">
                @foreach ($playlists as $playlist)
                    <li>
                        <a href="{{ route('media.playlist', $playlist) }}"
                           class="flex items-center gap-4 py-3 transition hover:bg-base-800/50">
                            <div class="flex size-12 shrink-0 items-center justify-center rounded bg-base-700 text-ink-500">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 6h11M4 12h11M4 18h7M17 14v6M17 20l3-2-3-2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink-100">{{ $playlist->name }}</p>
                                <p class="text-xs text-ink-500">
                                    {{ $playlist->media_items_count }} {{ Str::plural('track', $playlist->media_items_count) }}
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-media.layout>

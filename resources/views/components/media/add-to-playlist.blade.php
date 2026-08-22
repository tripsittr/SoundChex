@props([
    /** The tracks this control adds. A collection, so one item and a whole album share the code. */
    'items',
    'label' => 'Add to playlist',
    'compact' => false,
])

@php
    use App\Models\Collection as Playlist;

    $playlists = Playlist::query()
        ->where('user_id', auth()->id())
        ->orderBy('name')
        ->get(['id', 'name']);

    $ids = $items->pluck('id')->values();
@endphp

{{--
    Add-to-playlist.

    A native <details> rather than a JS dropdown: it opens without waiting for
    a bundle, closes on Escape for free, and is reachable by keyboard with no
    focus management of our own.
--}}
<details class="add-to-playlist relative inline-block"
         data-items="{{ $ids->toJson() }}">
    <summary class="{{ $compact
        ? 'flex size-8 shrink-0 cursor-pointer list-none items-center justify-center rounded text-ink-500 opacity-0 transition hover:bg-base-700 hover:text-ink-100 focus:opacity-100 group-hover:opacity-100'
        : 'inline-flex cursor-pointer list-none items-center gap-2 rounded-full border border-base-500 px-5 py-2.5 text-sm font-medium text-ink-200 transition hover:border-ink-500 hover:text-ink-100' }}"
             aria-label="{{ $label }}">
        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M12 5v14M5 12h14" stroke-linecap="round" />
        </svg>
        @unless ($compact)<span>{{ $label }}</span>@endunless
    </summary>

    <div class="absolute right-0 z-30 mt-2 w-60 rounded-lg border border-base-600 bg-base-800 p-1 shadow-xl shadow-black/50">
        @forelse ($playlists as $playlist)
            <button type="button"
                    data-add-to-playlist="{{ $playlist->id }}"
                    class="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm text-ink-200 transition hover:bg-base-700 hover:text-ink-100">
                <span class="truncate">{{ $playlist->name }}</span>
            </button>
        @empty
            <p class="px-3 py-2 text-xs text-ink-500">No playlists yet.</p>
        @endforelse

        <div class="mt-1 border-t border-base-700 pt-1">
            <form method="POST" action="{{ route('media.playlists.store') }}" class="flex gap-1 p-1">
                @csrf
                <input type="text" name="name" required maxlength="120"
                       placeholder="New playlist"
                       class="min-w-0 flex-1 rounded border border-base-600 bg-base-900 px-2 py-1.5 text-sm text-ink-100 placeholder:text-ink-600 focus:border-accent focus:outline-none">
                <button type="submit"
                        class="shrink-0 rounded bg-accent px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-accent-hot">
                    Add
                </button>
            </form>
        </div>
    </div>
</details>

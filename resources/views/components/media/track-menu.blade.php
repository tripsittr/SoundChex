@props([
    /** The tracks this menu acts on. A collection, so one song and a whole album share it. */
    'items',
    /** Shown at the top of the menu so it is obvious what is being acted on. */
    'label' => null,
    /** Where "Play next" and "Add to queue" insert from. */
    'compact' => true,
])

@php
    use App\Models\Collection as Playlist;

    // Resolved once per request, not once per menu. Every row on a songs page
    // renders one of these, and each ran the same query — 48 identical fetches
    // of the same playlist list on one page. The set cannot change while a
    // page renders, so the first answer stands for all of them.
    $playlists = once(fn () => Playlist::query()
        ->where('user_id', auth()->id())
        ->orderBy('name')
        ->get(['id', 'name']));

    $payload = $items->map(fn ($item) => $item->playerPayload())->values();

    $downloadable = $items->map(fn ($item) => [
        'id' => $item->id,
        'title' => $item->title,
        'size' => $item->playbackSize() ?? 0,
        'url' => route('media.stream', $item),
    ])->values();

    $ids = $items->pluck('id')->values();
    $single = $items->count() === 1;
@endphp

{{--
    The overflow menu.

    A native <details> rather than a JS popover: it opens with no bundle
    loaded, closes on Escape, and is keyboard-reachable without any focus
    management of our own. The cost is that positioning is plain CSS, which is
    fine for a menu anchored to its own button.
--}}
<details class="track-menu relative inline-block"
         data-items="{{ $ids->toJson() }}"
         data-queue="{{ $payload->toJson() }}"
         data-tracks="{{ $downloadable->toJson() }}">

    <summary class="{{ $compact
        ? 'flex size-8 shrink-0 cursor-pointer list-none items-center justify-center rounded text-ink-500 opacity-0 transition hover:bg-base-700 hover:text-ink-100 focus:opacity-100 group-hover:opacity-100'
        : 'inline-flex cursor-pointer list-none items-center gap-2 rounded-full border border-base-500 px-4 py-2.5 text-sm font-medium text-ink-200 transition hover:border-ink-500 hover:text-ink-100' }}"
             aria-haspopup="menu"
             aria-label="More options{{ $label ? ' for ' . $label : '' }}">
        {{-- The meatball. Three dots is the near-universal signal for "more". --}}
        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="12" r="1.75" />
            <circle cx="12" cy="12" r="1.75" />
            <circle cx="19" cy="12" r="1.75" />
        </svg>
        @unless ($compact)<span>More</span>@endunless
    </summary>

    <div role="menu"
         class="absolute right-0 z-40 mt-1 w-56 overflow-hidden rounded-lg border border-base-600 bg-base-800 py-1 shadow-xl shadow-black/60">

        @if ($label)
            <p class="truncate border-b border-base-700 px-3 pb-2 pt-1.5 text-xs text-ink-500">{{ $label }}</p>
        @endif

        <button type="button" data-menu-play role="menuitem" class="track-menu-item">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M8 5v14l11-7z" />
            </svg>
            Play{{ $single ? '' : ' all' }}
        </button>

        <button type="button" data-menu-play-next role="menuitem" class="track-menu-item">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M4 6h10M4 12h10M4 18h6M17 8v8M21 12l-4-4M21 12l-4 4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Play next
        </button>

        <button type="button" data-menu-queue role="menuitem" class="track-menu-item">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M4 6h11M4 12h11M4 18h7M17 14v6M17 20l3-2-3-2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Add to queue
        </button>

        <button type="button"
                data-download-batch
                data-state="idle"
                data-tracks="{{ $downloadable->toJson() }}"
                role="menuitem"
                class="track-menu-item download-btn">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span data-download-label>Download</span>
        </button>

        <div class="my-1 border-t border-base-700"></div>

        <p class="px-3 pb-1 pt-1 text-xs font-medium uppercase tracking-wider text-ink-600">Add to playlist</p>

        @forelse ($playlists as $playlist)
            <button type="button"
                    data-add-to-playlist="{{ $playlist->id }}"
                    role="menuitem"
                    class="track-menu-item">
                <span class="truncate">{{ $playlist->name }}</span>
            </button>
        @empty
            <p class="px-3 pb-1 text-xs text-ink-600">No playlists yet.</p>
        @endforelse

        <form method="POST" action="{{ route('media.playlists.store') }}" class="flex gap-1 px-2 pb-1 pt-1">
            @csrf
            <input type="text" name="name" required maxlength="120"
                   placeholder="New playlist"
                   class="min-w-0 flex-1 rounded border border-base-600 bg-base-900 px-2 py-1 text-xs text-ink-100 placeholder:text-ink-600 focus:border-accent focus:outline-none">
            <button type="submit"
                    class="shrink-0 rounded bg-accent px-2 py-1 text-xs font-semibold text-white transition hover:bg-accent-hot">
                Add
            </button>
        </form>

        @if ($single)
            <div class="my-1 border-t border-base-700"></div>
            <a href="{{ route('media.show', $items->first()) }}" role="menuitem" class="track-menu-item">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 8h.01M11 12h1v4h1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                More info
            </a>
        @endif
    </div>
</details>

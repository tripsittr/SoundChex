@php
    /**
     * The music sub-navigation.
     *
     * Music has four groupings and the main nav has room for one entry, so
     * they live here rather than competing with Watch and Books at the top
     * level. Defined once and shared by every music page, so a page cannot
     * quietly drop one.
     */
    $tabs = [
        [
            'label' => 'Songs',
            'href' => route('media.browse', 'music'),
            'active' => request()->routeIs('media.browse') && request()->route('type') === 'music',
        ],
        [
            'label' => 'Albums',
            'href' => route('media.albums'),
            'active' => request()->routeIs('media.albums') || request()->routeIs('media.album'),
        ],
        [
            'label' => 'Artists',
            'href' => route('media.artists'),
            'active' => request()->routeIs('media.artists') || request()->routeIs('media.artist'),
        ],
        [
            'label' => 'Genres',
            'href' => route('media.genres'),
            'active' => request()->routeIs('media.genres'),
        ],
        [
            'label' => 'Playlists',
            'href' => route('media.playlists'),
            'active' => request()->routeIs('media.playlists') || request()->routeIs('media.playlist'),
        ],
    ];
@endphp

{{-- Scrolls rather than wraps: five tabs plus shuffle do not fit on a phone
     without scrolling. --}}
<div class="music-subnav-wrap">
<nav class="music-subnav" aria-label="Music sections">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['href'] }}"
           @if ($tab['active']) aria-current="page" @endif
           class="music-subnav__tab {{ $tab['active'] ? 'is-active' : '' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach

    {{-- Shuffle belongs with the groupings: it is another way into the same
         library, and it is wanted from any of these pages rather than only
         from the songs list. --}}
    <button type="button"
            data-shuffle-library
            class="music-subnav__shuffle">
        <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span data-shuffle-label>Shuffle all</span>
    </button>

    {{-- Fetches the whole library rather than the page on screen: the songs
         list is paginated, so a button built from what is rendered would
         quietly download the first forty-eight tracks and stop. --}}
    <button type="button"
            data-download-library
            data-state="idle"
            class="music-subnav__shuffle">
        <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span data-download-label>Download all</span>
    </button>
</nav>
</div>

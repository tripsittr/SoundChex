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


</nav>
</div>

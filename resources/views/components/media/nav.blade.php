@props(['counts' => []])

@php
    // Resolved here rather than passed in: the nav appears on every page, and
    // threading two more variables through every controller would be noise.
    $profileService = app(\App\Services\CurrentProfile::class);
    $profile = $profileService->get();
    $profiles = $profileService->all();

    // Movies and shows share one entry: choosing what to watch rarely starts
    // with deciding between a film and an episode. The split lives as a
    // sub-nav on that page instead.
    $links = [
        ['label' => 'Home',  'route' => 'media.home',        'type' => null],
        ['label' => 'Watch', 'route' => 'media.watch.index', 'type' => 'watch'],
        ['label' => 'Music', 'route' => 'media.browse',      'type' => 'music'],
        ['label' => 'Books', 'route' => 'media.browse',      'type' => 'book'],
    ];

    $activeType = request()->route('type');
    $isHome = request()->routeIs('media.home');
    $isWatch = request()->routeIs('media.watch.index');

    // Watch covers both types, so its badge is their combined total.
    $watchCount = ($counts['movie'] ?? 0) + ($counts['show'] ?? 0);

    // Resolved once here rather than in each of the two link lists below, so
    // desktop and mobile can't drift apart.
    $resolve = function (array $link) use ($isHome, $isWatch, $activeType, $counts, $watchCount): array {
        return match ($link['type']) {
            null => [
                'href' => route('media.home'),
                'active' => $isHome,
                'count' => 0,
            ],
            'watch' => [
                'href' => route('media.watch.index'),
                'active' => $isWatch,
                'count' => $watchCount,
            ],
            default => [
                'href' => route('media.browse', $link['type']),
                'active' => $activeType === $link['type'],
                'count' => $counts[$link['type']] ?? 0,
            ],
        };
    };
@endphp

{{--
    Transparent over the hero, solid once scrolled — the streaming-app
    convention. `nav-scrolled` is toggled from media-center.js.
--}}
<header x-data="mediaNav()"
        :class="scrolled ? 'bg-base-900/95 backdrop-blur-md shadow-lg shadow-black/40' : 'bg-gradient-to-b from-base-900/90 to-transparent'"
        class="fixed inset-x-0 top-0 z-40 transition-colors duration-300">

    {{-- The header is fixed to the top, so on a notched phone it sits under
         the status bar and the clock overlaps the logo. Padding the nav rather
         than the header keeps the blurred background running to the top edge,
         which is what makes the inset look deliberate instead of like a gap. --}}
    <nav class="media-header-nav flex items-center gap-3 px-4 py-3 sm:gap-6 sm:px-8" aria-label="Primary">

        {{-- Back (S-52). The Tauri window and the phone web-app have no browser
             chrome, so without this the only way out of a detail page is to
             navigate all the way round. Hidden on the top-level pages (home and
             the main tabs), where there is nothing above to go back to; shown on
             the pages you drill into — an album, an artist, a playlist, an item.
             `history.back()` uses the SPA history Livewire maintains. --}}
        @unless ($isHome || request()->routeIs('media.watch.index', 'media.browse', 'media.search'))
            <button type="button"
                    @click="window.history.back()"
                    class="shrink-0 rounded-full p-1.5 text-ink-200 transition hover:bg-base-700/60 hover:text-ink-100"
                    aria-label="Back">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
        @endunless

        <a href="{{ route('media.home') }}" class="shrink-0">
            {{-- The light-on-dark variant: its waveform is white, which is
                 invisible on the light logo used in the admin panel. --}}
            <img src="{{ Vite::asset('resources/images/logo-light-on-dark.png') }}"
                 alt="{{ config('app.name', 'SoundChex') }} — home"
                 class="h-9 w-auto sm:h-12">
        </a>

        {{-- Desktop links --}}
        <ul class="hidden items-center gap-1 md:flex">
            @foreach ($links as $link)
                @php $nav = $resolve($link); @endphp

                <li>
                    <a href="{{ $nav['href'] }}"
                       @if($nav['active']) aria-current="page" @endif
                       class="rounded-md px-3 py-1.5 text-sm font-medium transition
                              {{ $nav['active'] ? 'text-ink-100' : 'text-ink-300 hover:text-ink-100' }}">
                        {{ $link['label'] }}
                        @if ($nav['count'] > 0)
                            <span class="ml-1 text-xs text-ink-500">{{ $nav['count'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">

            {{-- Search: collapses to an icon on small screens to protect the logo. --}}
            <form method="GET"
                  action="{{ route('media.search') }}"
                  class="relative"
                  role="search">
                <label for="media-search" class="sr-only">Search your library</label>
                <input id="media-search"
                       type="search"
                       name="q"
                       value="{{ request('q') }}"
                       placeholder="Search…"
                       class="w-28 rounded-full border border-base-500/70 bg-base-800/80 py-1.5 pl-9 pr-3 text-sm
                              text-ink-100 placeholder:text-ink-500 transition-all
                              focus:w-40 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent
                              sm:w-44 sm:focus:w-64">
                <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-500"
                     viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                </svg>
            </form>

            {{-- Account --}}
            <div x-data="{ open: false }" class="relative">
                <button type="button"
                        @click="open = !open"
                        @click.outside="open = false"
                        :aria-expanded="open.toString()"
                        aria-haspopup="menu"
                        class="flex size-8 items-center justify-center overflow-hidden rounded-md text-sm font-semibold text-white transition hover:brightness-110"
                        style="background: {{ $profile?->color ?? 'var(--color-accent)' }}">
                    @if ($profile?->hasAvatar())
                        <img src="{{ $profile->avatarUrl() }}" alt="" class="size-full object-cover">
                    @else
                        {{ $profile?->initial() ?? str(auth()->user()->name ?? '?')->substr(0, 1)->upper() }}
                    @endif
                    <span class="sr-only">Account menu</span>
                </button>

                <div x-show="open"
                     x-cloak
                     x-transition.opacity
                     role="menu"
                     class="absolute right-0 mt-2 w-52 overflow-hidden rounded-lg border border-base-600 bg-base-800 py-1 shadow-xl shadow-black/60">

                    <p class="px-3 pt-2 text-sm font-medium text-ink-100">
                        {{ $profile?->name ?? auth()->user()->name }}
                    </p>
                    <p class="px-3 pb-2 text-xs text-ink-500">
                        {{ auth()->user()->email }}
                    </p>

                    @if ($profiles->count() > 1)
                        <div class="border-t border-base-600 py-1">
                            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-ink-500">
                                Switch profile
                            </p>

                            @foreach ($profiles as $option)
                                @continue($option->id === $profile?->id)

                                <form method="POST" action="{{ route('profiles.switch') }}">
                                    @csrf
                                    <input type="hidden" name="profile_id" value="{{ $option->id }}">
                                    <button type="submit" role="menuitem"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                                        <span class="grid size-5 shrink-0 place-items-center overflow-hidden rounded text-[11px] font-semibold text-white"
                                              style="background: {{ $option->color }}">
                                            @if ($option->hasAvatar())
                                                <img src="{{ $option->avatarUrl() }}" alt="" class="size-full object-cover">
                                            @else
                                                {{ $option->initial() }}
                                            @endif
                                        </span>
                                        {{ $option->name }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif

                    <div class="border-t border-base-600 py-1">
                        {{-- The queue, shown only while something is in it. A
                             download that says "queued" and then gives no sign
                             of progress is indistinguishable from one that did
                             nothing. --}}
                        <div data-download-queue hidden>
                            <button type="button"
                                    data-download-queue-toggle
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                                <span>Downloading</span>
                                <span data-download-queue-count class="text-xs tabular-nums text-ink-500"></span>
                            </button>

                            <div data-download-queue-list
                                 class="max-h-48 overflow-y-auto border-y border-base-700 bg-base-900/60 px-3 py-1"
                                 hidden></div>
                        </div>

                        <a href="{{ route('media.downloads') }}" role="menuitem"
                           class="block px-3 py-2 text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                            Downloads
                        </a>

                        <a href="{{ route('media.playlists') }}" role="menuitem"
                           class="block px-3 py-2 text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                            Playlists
                        </a>

                        {{-- Settings for this profile, as opposed to the admin
                             panel's server-wide configuration. Reachable by every
                             profile, since most have no admin access at all. --}}
                        <a href="{{ route('media.settings') }}" role="menuitem"
                           class="block px-3 py-2 text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                            Settings
                        </a>

                        <a href="{{ route('profiles.index') }}" role="menuitem"
                           class="block px-3 py-2 text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                            Manage profiles
                        </a>

                        {{-- Hidden on a kids profile. Not a security boundary —
                             the panel enforces its own permissions — but it
                             keeps a child from wandering into it. --}}
                        @unless ($profile?->is_kids)
                            <a href="{{ url('/admin') }}" role="menuitem"
                               class="block px-3 py-2 text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                                Library management
                            </a>
                        @endunless
                    </div>

                    {{-- SoundChex is AGPLv3. §13 requires that anyone interacting
                         with it over a network be offered its corresponding
                         source; this is that offer, reachable from every page a
                         network user sees. --}}
                    <div class="border-t border-base-600 py-1">
                        <a href="https://github.com/tripsittr/SoundChex" role="menuitem"
                           target="_blank" rel="noopener"
                           class="block px-3 py-2 text-xs text-ink-500 transition hover:bg-base-700 hover:text-ink-300">
                            Open source (AGPLv3) &mdash; source code
                        </a>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" role="menuitem"
                                class="block w-full px-3 py-2 text-left text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

</header>

{{-- Mobile navigation lives at the bottom, within thumb reach. A scrolling
     pill row at the top of a phone screen is the furthest point from where a
     hand actually is, which is why every streaming app puts it down here. --}}
<nav class="mobile-tabs md:hidden" aria-label="Sections">
    @foreach ($links as $link)
        @php $nav = $resolve($link); @endphp

        <a href="{{ $nav['href'] }}"
           @if($nav['active']) aria-current="page" @endif
           class="mobile-tab {{ $nav['active'] ? 'is-active' : '' }}">
            <span class="mobile-tab-icon" aria-hidden="true">
                @switch($link['type'])
                    @case('watch')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="2" y="4" width="20" height="15" rx="2" />
                            <path d="M10 9l5 2.5-5 2.5z" fill="currentColor" stroke="none" />
                        </svg>
                        @break
                    @case('music')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M9 18V5l10-2v13" />
                            <circle cx="6.5" cy="18" r="2.5" />
                            <circle cx="16.5" cy="16" r="2.5" />
                        </svg>
                        @break
                    @case('book')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 5a2 2 0 012-2h13v18H6a2 2 0 01-2-2z" />
                            <path d="M8 3v18" />
                        </svg>
                        @break
                    @default
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z" />
                        </svg>
                @endswitch
            </span>
            <span class="mobile-tab-label">{{ $link['label'] }}</span>
        </a>
    @endforeach

    <a href="{{ route('media.search') }}" class="mobile-tab {{ request()->routeIs('media.search') ? 'is-active' : '' }}">
        <span class="mobile-tab-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-3.5-3.5" stroke-linecap="round" />
            </svg>
        </span>
        <span class="mobile-tab-label">Search</span>
    </a>
</nav>

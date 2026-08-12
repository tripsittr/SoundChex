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

    <nav class="flex items-center gap-3 px-4 py-3 sm:gap-6 sm:px-8" aria-label="Primary">

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
                        <a href="{{ route('media.downloads') }}" role="menuitem"
                           class="block px-3 py-2 text-sm text-ink-300 transition hover:bg-base-700 hover:text-ink-100">
                            Downloads
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

    {{-- Mobile type switcher: the desktop links don't fit, so they become a
         scrollable pill row under the bar. --}}
    <ul class="flex gap-2 overflow-x-auto px-4 pb-2 md:hidden [&::-webkit-scrollbar]:hidden">
        @foreach ($links as $link)
            @php $nav = $resolve($link); @endphp

            <li>
                <a href="{{ $nav['href'] }}"
                   @if($nav['active']) aria-current="page" @endif
                   class="block whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition
                          {{ $nav['active'] ? 'bg-ink-100 text-base-900' : 'bg-base-700/80 text-ink-300' }}">
                    {{ $link['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</header>

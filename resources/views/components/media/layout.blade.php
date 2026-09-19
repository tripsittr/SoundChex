<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-base-900">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) report real
         values. Without it a notched phone in standalone mode letterboxes the
         app instead of letting it style around the notch. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#08080b">

    <title>{{ $title ?? 'Library' }} — {{ config('app.name', 'SoundChex') }}</title>

    <link rel="icon" href="{{ asset('storage/favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('storage/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('storage/favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('storage/apple-touch-icon.png') }}">

    {{-- PWA: installable on a phone home screen. --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SoundChex">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">

    @vite([
        'resources/css/media-center.css',
        'resources/js/media-center.js',
        'resources/js/now-playing.js',
        'resources/js/now-playing-sheet.js',
        'resources/js/playlists.js',
        'resources/js/connection-status.js',
        'resources/js/library-refresh.js',
        'resources/js/playlist-reorder.js',
        'resources/js/upload-progress.js',
        'resources/js/library/index.js',
        'resources/js/navigate.js',
    ])

    {{-- Livewire's navigation layer only — no Livewire components. It swaps
         the page instead of reloading it, which is the one way an <audio>
         element can survive a link click. Already installed for Filament, so
         this costs no new dependency. --}}
    @livewireStyles
</head>
@php
    // Rendered into the page rather than fetched. These change rarely, and a
    // request per launch to learn them is a request wasted.
    $notificationPrefs = ($p = app(\App\Services\CurrentProfile::class)->get())
        ? [
            'enabled' => (bool) $p->preference('notifications_enabled'),
            'episodes' => (bool) $p->preference('notify_download_complete'),
            'scans' => (bool) $p->preference('notify_scan_complete'),
        ]
        : ['enabled' => false];
@endphp

<body class="min-h-screen bg-base-900 text-ink-100 antialiased"
      data-notification-prefs="{{ json_encode($notificationPrefs) }}">

    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-md focus:bg-accent focus:px-4 focus:py-2 focus:text-white">
        Skip to content
    </a>

    <x-media.nav :counts="$counts ?? []" />

    {{-- Extra bottom padding leaves room for the now-playing bar, so the last
         row of a grid is never hidden behind it. --}}
    <main id="main" class="pb-32">
        {{ $slot }}
    </main>

    {{-- Deliberately not wrapped in @persist. The <audio> element is created
         in JavaScript rather than markup, so there is nothing in the DOM for
         @persist to carry — what survives is the player object on `window`,
         and this bar is only the UI reflecting its state. Re-rendered each
         navigation and re-bound to the same player. --}}
    <x-media.now-playing />

    {{-- Transparency footer. SoundChex is AGPLv3 and stores none of the user's
         media off their own machine; this footer says so plainly and links every
         piece of that — the source, the licence, and the policies — so the whole
         project is reachable from inside the app, not only from a website the
         self-hoster may never have seen. The website is not on a stable public
         domain yet (W-11), so legal links fall back to the public repo. --}}
    @php
        $repo = config('app.links.repository');
        $site = config('app.links.website');
        // Legal pages live on the marketing site; without one, point at the repo.
        $legal = fn (string $slug) => $site ? rtrim($site, '/')."/legal/{$slug}" : $repo;
        $docs = fn (string $slug) => $site ? rtrim($site, '/')."/docs/{$slug}" : $repo;
    @endphp
    <footer class="border-t border-base-600/60 px-4 py-10 text-sm text-ink-500 sm:px-8">
        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3">
                    <img src="{{ Vite::asset('resources/images/logo-light-on-dark.png') }}"
                         alt=""
                         class="mt-0.5 h-5 w-auto opacity-50">
                    <div class="max-w-md">
                        <p class="text-ink-300">Self-hosted media library</p>
                        <p class="mt-1 text-xs leading-relaxed text-ink-500">
                            Your music, films, TV and books, on a server you run. SoundChex is free
                            and open source, and stores none of your media anywhere but your own
                            machine &mdash; it neither acquires your files nor helps you to.
                        </p>
                    </div>
                </div>
                @unless (app(\App\Services\CurrentProfile::class)->isKids())
                    <a href="{{ url('/admin') }}" class="shrink-0 transition hover:text-ink-100">
                        Library management &rarr;
                    </a>
                @endunless
            </div>

            <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" aria-label="Project information">
                <a href="{{ $repo }}" target="_blank" rel="noopener" class="transition hover:text-ink-100">Source (AGPLv3)</a>
                <span class="text-base-600" aria-hidden="true">&middot;</span>
                <a href="{{ $repo }}/blob/main/LICENSE" target="_blank" rel="noopener" class="transition hover:text-ink-100">Licence</a>
                <span class="text-base-600" aria-hidden="true">&middot;</span>
                <a href="{{ $legal('server-privacy') }}" target="_blank" rel="noopener" class="transition hover:text-ink-100">Privacy</a>
                <span class="text-base-600" aria-hidden="true">&middot;</span>
                <a href="{{ $legal('server-terms') }}" target="_blank" rel="noopener" class="transition hover:text-ink-100">Terms</a>
                <span class="text-base-600" aria-hidden="true">&middot;</span>
                <a href="{{ $docs('credits') }}" target="_blank" rel="noopener" class="transition hover:text-ink-100">Open-source credits</a>
                @if ($site)
                    <span class="text-base-600" aria-hidden="true">&middot;</span>
                    <a href="{{ $site }}" target="_blank" rel="noopener" class="transition hover:text-ink-100">Website</a>
                @endif
            </nav>

            <p class="text-xs text-ink-600">
                &copy; {{ date('Y') }} Tripsittr LLC. SoundChex&trade; is a trademark of Tripsittr LLC.
                A commercial licence is available &mdash; see the licence link above.
            </p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>

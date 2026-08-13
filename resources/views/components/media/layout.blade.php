<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-base-900">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is what makes env(safe-area-inset-*) report real
         values. Without it a notched phone in standalone mode letterboxes the
         app instead of letting it style around the notch. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
        'resources/js/navigate.js',
    ])

    {{-- Livewire's navigation layer only — no Livewire components. It swaps
         the page instead of reloading it, which is the one way an <audio>
         element can survive a link click. Already installed for Filament, so
         this costs no new dependency. --}}
    @livewireStyles
</head>
<body class="min-h-screen bg-base-900 text-ink-100 antialiased">

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

    <footer class="border-t border-base-600/60 px-4 py-8 text-sm text-ink-500 sm:px-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <img src="{{ Vite::asset('resources/images/logo-light-on-dark.png') }}"
                     alt=""
                     class="h-5 w-auto opacity-50">
                <span>Self-hosted media library</span>
            </div>
            {{-- Hidden on a kids profile, matching the account menu. The
                 panel enforces its own permissions; this just keeps a child
                 from wandering into it. --}}
            @unless (app(\App\Services\CurrentProfile::class)->isKids())
                <a href="{{ url('/admin') }}" class="transition hover:text-ink-100">
                    Library management →
                </a>
            @endunless
        </div>
    </footer>

    @livewireScripts
</body>
</html>

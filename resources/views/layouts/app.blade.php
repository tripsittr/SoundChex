<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'soundchex') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-gray-50 text-gray-900">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="text-lg font-semibold">{{ config('app.name', 'soundchex') }}</a>

            <nav class="flex items-center gap-4 text-sm">
                @auth
                <a href="{{ route('dashboard') }}" class="text-gray-700 hover:text-gray-900">Dashboard</a>
                <a href="{{ url('/admin') }}" class="text-gray-700 hover:text-gray-900">Admin</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-100">Logout</button>
                </form>
                @else
                <a href="{{ route('login') }}" class="text-gray-700 hover:text-gray-900">Login</a>
                <a href="{{ route('register') }}"
                    class="rounded-md bg-amber-500 px-3 py-1.5 text-white hover:bg-amber-600">Register</a>
                @endauth
            </nav>
        </div>
    </header>

    @if (isset($header))
    <section class="border-b border-gray-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
            {{ $header }}
        </div>
    </section>
    @endif

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {{ $slot }}
    </main>

    @stack('modals')
</body>

</html>
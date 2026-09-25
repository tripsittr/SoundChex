<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'SoundChex') }}</title>

    <link rel="icon" href="{{ asset('storage/favicon.ico') }}" sizes="any">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">

    {{-- The same stylesheet the media center uses, so the door looks like the
         room behind it rather than a stock Laravel form. --}}
    @vite(['resources/css/media-center.css'])

    <style>
        /* A still from the library behind a heavy scrim: enough to signal what
           this is without competing with the form. */
        .auth-stage {
            position: relative;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            overflow: hidden;
        }

        .auth-glow {
            position: absolute;
            inset: -30%;
            background:
                radial-gradient(40rem 30rem at 20% 10%, rgb(225 29 58 / 0.16), transparent 60%),
                radial-gradient(35rem 28rem at 85% 80%, rgb(99 102 241 / 0.14), transparent 60%);
            filter: blur(40px);
            pointer-events: none;
        }

        .auth-card {
            position: relative;
            width: 100%;
            max-width: 25rem;
            border-radius: 1rem;
            border: 1px solid var(--color-base-500);
            background: color-mix(in srgb, var(--color-base-800) 92%, transparent);
            padding: 2rem 1.75rem;
            box-shadow: 0 24px 70px rgb(0 0 0 / 0.55);
            backdrop-filter: blur(12px);
        }

        .auth-field {
            width: 100%;
            border-radius: 0.5rem;
            border: 1px solid var(--color-base-500);
            background: var(--color-base-900);
            color: var(--color-ink-100);
            padding: 0.625rem 0.75rem;
            font-size: 0.9375rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .auth-field::placeholder { color: var(--color-ink-500); }

        .auth-field:focus {
            outline: none;
            border-color: var(--color-accent);
            box-shadow: 0 0 0 3px rgb(225 29 58 / 0.18);
        }

        .auth-label {
            display: block;
            margin-bottom: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--color-ink-300);
        }

        .auth-submit {
            width: 100%;
            border: 0;
            border-radius: 0.5rem;
            background: var(--color-accent);
            color: #fff;
            padding: 0.7rem 1rem;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.1s ease;
        }

        .auth-submit:hover { background: var(--color-accent-hot); }
        .auth-submit:active { transform: translateY(1px); }

        .auth-error {
            margin-top: 0.375rem;
            font-size: 0.8125rem;
            color: #fca5a5;
        }

        .auth-link { color: var(--color-ink-300); text-decoration: none; }
        .auth-link:hover { color: var(--color-ink-100); }
    </style>
</head>
<body class="bg-base-900 font-sans text-ink-100 antialiased">

<div class="auth-stage">
    <div class="auth-glow" aria-hidden="true"></div>

    <div class="w-full max-w-md">
        <div class="mb-7 flex justify-center">
            <img src="{{ Vite::asset('resources/images/logo-light-on-dark.png') }}"
                 alt="{{ config('app.name', 'SoundChex') }}"
                 class="h-12 w-auto">
        </div>

        {{ $slot }}

        {{-- AGPLv3 §13: the source offer must reach network users, including on
             the pages they see before signing in. Pinned to this build's
             commit where there is one — the offer is of the source that
             corresponds to what is serving you, not of the project at large
             (S-401). The About page is behind auth, so the link goes straight
             to the source here. --}}
        <p class="mt-8 text-center text-xs text-ink-500">
            <a href="{{ \App\Support\AppRelease::sourceUrl() }}" target="_blank" rel="noopener"
               class="transition hover:text-ink-300">
                SoundChex {{ \App\Support\AppRelease::version() }} &mdash; open source (AGPLv3)
            </a>
        </p>
    </div>
</div>

</body>
</html>

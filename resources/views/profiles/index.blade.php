<x-guest-layout>
    <div class="w-full">
        <h1 class="text-center text-2xl font-semibold text-ink-100">Who's watching?</h1>
        <p class="mt-2 text-center text-sm text-ink-500">
            Everyone gets their own history, resume points and highlights.
        </p>

        <div class="mt-10 flex flex-wrap items-start justify-center gap-6">
            @foreach ($profiles as $profile)
                <form method="POST" action="{{ route('profiles.switch') }}">
                    @csrf
                    <input type="hidden" name="profile_id" value="{{ $profile->id }}">

                    <button type="submit" class="profile-tile group">
                        <span class="profile-avatar" style="background: {{ $profile->color }}">
                            @if ($profile->hasAvatar())
                                <img src="{{ $profile->avatarUrl() }}" alt="">
                            @else
                                {{ $profile->initial() }}
                            @endif
                        </span>

                        <span class="profile-name">
                            {{ $profile->name }}
                            {{-- A padlock, so it is obvious which profiles
                                 will ask before they are tapped rather than
                                 after. --}}
                            @if ($profile->requiresPin())
                                <svg class="profile-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-label="PIN required">
                                    <rect x="5" y="11" width="14" height="10" rx="2" />
                                    <path d="M8 11V8a4 4 0 018 0v3" stroke-linecap="round" />
                                </svg>
                            @endif
                            @if ($profile->is_kids)
                                <span class="profile-badge">Kids</span>
                            @endif
                        </span>
                    </button>

                    {{-- Below the profile it unlocks. Placed above, it pushed
                         the avatar down and read as belonging to whichever tile
                         happened to sit above it. Shown only for the profile
                         that just asked, so the picker stays one tap for
                         everyone else. --}}
                    @if (session('pin_for') === $profile->id)
                        <div class="profile-pin">
                            <label for="pin-{{ $profile->id }}" class="auth-label">PIN</label>
                            <input id="pin-{{ $profile->id }}" name="pin" type="password"
                                   inputmode="numeric" autocomplete="off" maxlength="6"
                                   autofocus class="auth-field text-center tracking-[0.4em]">
                            @error('pin')
                                <p class="auth-error">{{ $message }}</p>
                            @enderror

                            {{-- Enter submits, but a phone keyboard's return
                                 key is not an obvious "unlock" — so there is a
                                 button to press. --}}
                            <button type="submit" class="profile-pin-submit">Unlock</button>
                        </div>
                    @endif
                </form>
            @endforeach

            {{-- Adding is a tile of the same size, so the row reads as one set
                 of choices rather than a grid plus a stray button. --}}
            <details class="profile-add">
                <summary class="profile-tile">
                    <span class="profile-avatar profile-avatar-add">+</span>
                    <span class="profile-name">Add profile</span>
                </summary>

                <form method="POST" action="{{ route('profiles.store') }}" class="profile-form">
                    @csrf

                    <label for="profile-name" class="auth-label">Name</label>
                    <input id="profile-name" name="name" type="text" required maxlength="40"
                           class="auth-field" placeholder="e.g. Syd">

                    <fieldset class="mt-3">
                        <legend class="auth-label">Colour</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\Profile::COLORS as $index => $color)
                                <label class="cursor-pointer">
                                    <input type="radio" name="color" value="{{ $color }}"
                                           class="sr-only" @checked($index === 0)>
                                    <span class="profile-swatch" style="background: {{ $color }}"></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <label class="mt-4 flex cursor-pointer items-start gap-2 text-sm text-ink-300">
                        <input type="checkbox" name="is_kids" value="1"
                               class="mt-0.5 size-4 rounded border-base-500 bg-base-900 text-accent focus:ring-accent">
                        <span>
                            Kids profile
                            <span class="block text-xs text-ink-500">
                                Hides the admin panel and limits titles to PG and below.
                            </span>
                        </span>
                    </label>

                    <button type="submit" class="auth-submit mt-4">Create profile</button>
                </form>
            </details>
        </div>

        @error('profile')
            <p class="auth-error mt-6 text-center">{{ $message }}</p>
        @enderror

        <p class="mt-10 text-center text-sm">
            <a href="{{ route('media.home') }}" class="auth-link">Continue as {{ $current?->name }}</a>
        </p>
    </div>

    <style>
        .profile-tile {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            width: 8rem;
            border: 0;
            background: transparent;
            cursor: pointer;
            list-style: none;
        }

        .profile-tile::-webkit-details-marker { display: none; }

        /* The photo fills the tile; the initial shows through when there
           isn't one, which is why the colour stays on the container. */
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }

        .profile-avatar {
            display: grid;
            overflow: hidden;
            place-items: center;
            width: 6rem;
            height: 6rem;
            border-radius: 0.875rem;
            color: #fff;
            font-size: 2.25rem;
            font-weight: 600;
            border: 2px solid transparent;
            transition: transform 0.15s var(--ease-out-soft), border-color 0.15s ease;
        }

        .profile-tile:hover .profile-avatar {
            transform: scale(1.06);
            border-color: var(--color-ink-100);
        }

        .profile-avatar-add {
            background: var(--color-base-700);
            color: var(--color-ink-500);
            border: 2px dashed var(--color-base-500);
            font-weight: 300;
        }

        .profile-name {
            font-size: 0.9375rem;
            color: var(--color-ink-300);
            text-align: center;
        }

        .profile-tile:hover .profile-name { color: var(--color-ink-100); }

        .profile-badge {
            display: inline-block;
            margin-left: 0.25rem;
            padding: 0.05rem 0.35rem;
            border-radius: 0.25rem;
            background: var(--color-base-600);
            font-size: 0.625rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--color-ink-300);
        }

        .profile-form {
            width: min(20rem, 90vw);
            margin-top: 1.25rem;
            padding: 1.25rem;
            border-radius: 0.875rem;
            border: 1px solid var(--color-base-500);
            background: var(--color-base-800);
            text-align: left;
        }

        /* Selection is drawn with a box-shadow ring rather than Tailwind's
           ring utilities: this block is plain CSS emitted after the compiled
           stylesheet, so `peer-checked:ring-2` had no base ring to build on
           and `ring-offset-color` isn't a real property at all — it was
           silently dropped, leaving the chosen colour unmarked. */
        .profile-lock {
            width: 0.85rem;
            height: 0.85rem;
            display: inline-block;
            vertical-align: -0.1rem;
            margin-inline-start: 0.3rem;
            color: var(--color-ink-500, #a1a1aa);
        }

        .profile-pin-submit {
            width: 100%;
            margin-top: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            background: var(--color-accent, #e11d3a);
            color: #fff;
            font-size: 0.8125rem;
            font-weight: 600;
        }

        .profile-pin {
            /* The same width as the tile it sits under, so the two read as
               one thing rather than a stray form beside a picture. */
            width: 8rem;
            margin-top: 0.75rem;
            text-align: left;
        }

        .profile-swatch {
            display: block;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 9999px;
            box-shadow: 0 0 0 2px transparent;
            transition: box-shadow 0.15s ease, transform 0.15s ease;
        }

        .profile-swatch:hover { transform: scale(1.1); }

        input[name="color"]:checked + .profile-swatch {
            box-shadow:
                0 0 0 2px var(--color-base-800),
                0 0 0 4px var(--color-ink-100);
        }
    </style>
</x-guest-layout>

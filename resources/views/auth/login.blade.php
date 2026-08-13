<x-guest-layout>
    <div class="auth-card">
        <h1 class="text-lg font-semibold text-ink-100">Sign in</h1>
        <p class="mt-1 text-sm text-ink-500">Your library is waiting.</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="auth-label">Email</label>
                <input id="email" name="email" type="email" required autofocus
                       autocomplete="username"
                       value="{{ old('email') }}"
                       class="auth-field">
                @error('email')
                    <p class="auth-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="auth-label">Password</label>
                <input id="password" name="password" type="password" required
                       autocomplete="current-password"
                       class="auth-field">
                @error('password')
                    <p class="auth-error">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-300">
                <input type="checkbox" name="remember"
                       class="size-4 rounded border-base-500 bg-base-900 text-accent focus:ring-accent">
                Stay signed in
            </label>

            <button type="submit" class="auth-submit">Sign in</button>
        </form>

        {{-- Hidden when sign-up is closed: the route 404s, so offering it
             would send someone to a dead end. --}}
        @if (\App\Http\Middleware\EnsureRegistrationIsOpen::isOpen())
            <p class="mt-6 text-center text-sm text-ink-500">
                Don't have an account?
                <a href="{{ route('register') }}" class="auth-link font-medium">Create one</a>
            </p>
        @endif
    </div>
</x-guest-layout>

<x-guest-layout>
    <div class="auth-card">
        <h1 class="text-lg font-semibold text-ink-100">Create your account</h1>
        <p class="mt-1 text-sm text-ink-500">One account, a profile for everyone.</p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="name" class="auth-label">Name</label>
                <input id="name" name="name" type="text" required autofocus
                       autocomplete="name"
                       value="{{ old('name') }}"
                       class="auth-field">
                @error('name')
                    <p class="auth-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="auth-label">Email</label>
                <input id="email" name="email" type="email" required
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
                       autocomplete="new-password"
                       class="auth-field">
                @error('password')
                    <p class="auth-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="auth-label">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                       autocomplete="new-password"
                       class="auth-field">
            </div>

            <button type="submit" class="auth-submit">Create account</button>
        </form>

        <p class="mt-6 text-center text-sm text-ink-500">
            Already have an account?
            <a href="{{ route('login') }}" class="auth-link font-medium">Sign in</a>
        </p>
    </div>
</x-guest-layout>

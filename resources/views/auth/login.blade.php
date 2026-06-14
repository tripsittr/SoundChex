<x-guest-layout>
    <div class="mx-auto mt-16 w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold text-gray-900">Sign in</h1>
        <p class="mt-1 text-sm text-gray-600">Use your account to continue.</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember"
                    class="rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
                Remember me
            </label>

            <button type="submit"
                class="w-full rounded-lg bg-amber-500 px-4 py-2 font-medium text-white hover:bg-amber-600">
                Log in
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            Need an account?
            <a href="{{ route('register') }}" class="font-medium text-amber-600 hover:text-amber-700">Register</a>
        </p>
    </div>

    @if (app()->isLocal() && config('app.dev_login_autofill.enabled'))
    <script>
        (() => {
                const emailInput = document.getElementById('email');
                const passwordInput = document.getElementById('password');

                if (emailInput && !emailInput.value) {
                    emailInput.value = @json(config('app.dev_login_autofill.email'));
                }

                if (passwordInput && !passwordInput.value) {
                    passwordInput.value = @json(config('app.dev_login_autofill.password'));
                }
            })();
    </script>
    @endif
</x-guest-layout>
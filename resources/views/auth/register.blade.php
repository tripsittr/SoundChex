<x-guest-layout>
    <div class="mx-auto mt-16 w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold text-gray-900">Create account</h1>
        <p class="mt-1 text-sm text-gray-600">
            @if (!empty($invite))
            You were invited to join {{ $invite->organization->name }} as {{ str($invite->role)->replace('_', '
            ')->title() }}.
            @else
            Get started with SoundChex and create your organization.
            @endif
        </p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf

            @if (!empty($inviteToken))
            <input type="hidden" name="invite_token" value="{{ $inviteToken }}">
            @endif

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="name" name="name" type="text" required value="{{ old('name') }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                @error('name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input id="email" name="email" type="email" required value="{{ old('email') }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            @if (empty($invite))
            <div>
                <label for="organization_name" class="block text-sm font-medium text-gray-700">Organization name</label>
                <input id="organization_name" name="organization_name" type="text" required
                    value="{{ old('organization_name') }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                @error('organization_name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            @endif

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm
                    password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" />
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-amber-500 px-4 py-2 font-medium text-white hover:bg-amber-600">
                Register
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            Already have an account?
            <a href="{{ route('login') }}" class="font-medium text-amber-600 hover:text-amber-700">Log in</a>
        </p>
    </div>
</x-guest-layout>
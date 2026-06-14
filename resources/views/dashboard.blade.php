<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Dashboard</h1>
    </x-slot>

    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <p class="text-gray-700">You are signed in.</p>
        <div class="mt-4">
            <a href="{{ url('/admin') }}"
                class="inline-flex items-center rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                Open Filament Admin
            </a>
        </div>
    </div>
</x-app-layout>
<x-media.layout :counts="$counts" title="Home">

    @if ($hero)
        <x-media.hero :item="$hero" eyebrow="Recently added" />
    @else
        {{-- First run: an empty catalog should explain itself, not look broken. --}}
        <section class="flex min-h-[60vh] items-center justify-center px-6 pt-28 sm:pt-32 text-center">
            <div class="max-w-md">
                <img src="{{ Vite::asset('resources/images/logo-light-on-dark.png') }}"
                     alt="{{ config('app.name', 'SoundChex') }}"
                     class="mx-auto h-16 w-auto opacity-90">
                <h1 class="mt-8 text-2xl font-bold">Your library is empty</h1>
                <p class="mt-2 text-ink-300">
                    Add music, movies, shows, or books from the management panel and
                    they'll appear here automatically.
                </p>
                <a href="{{ url('/admin') }}"
                   class="mt-6 inline-flex items-center gap-2 rounded-md bg-accent px-5 py-2.5 text-sm font-bold text-white transition hover:bg-accent-hot">
                    Open library management
                </a>
            </div>
        </section>
    @endif

    <div class="relative z-10 {{ $hero ? '-mt-8 sm:-mt-16' : '' }}">
        @foreach ($rows as $row)
            <x-media.rail
                :title="$row['title']"
                :items="$row['items']"

                :view-all-url="$row['viewAllUrl']" />
        @endforeach
    </div>

</x-media.layout>

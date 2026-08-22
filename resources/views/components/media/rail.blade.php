@props(['title', 'items', 'viewAllUrl' => null])

@if ($items->isNotEmpty())
    <section x-data="rail()" class="group/rail relative py-4" aria-label="{{ $title }}">

        <div class="mb-2 flex items-baseline justify-between gap-4 px-4 sm:px-8">
            <h2 class="text-base font-bold tracking-tight text-ink-100 sm:text-lg">
                {{ $title }}
            </h2>

            @if ($viewAllUrl)
                <a href="{{ $viewAllUrl }}"
                   class="shrink-0 text-xs font-medium text-ink-500 transition hover:text-ink-100">
                    View all →
                </a>
            @endif
        </div>

        <div class="relative">
            {{-- Arrows: desktop only. Touch devices scroll the rail directly,
                 where overlay arrows would just cover artwork. --}}
            <button type="button"
                    x-show="!atStart"
                    x-cloak
                    x-transition.opacity
                    @click="scrollBy(-1)"
                    class="absolute left-0 top-0 z-20 hidden h-full w-12 items-center justify-center
                           bg-gradient-to-r from-base-900 to-transparent text-ink-100
                           opacity-0 transition group-hover/rail:opacity-100 focus:opacity-100 md:flex"
                    aria-label="Scroll {{ $title }} left">
                <svg class="size-7 drop-shadow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <div x-ref="track" class="rail px-4 sm:px-8">
                @foreach ($items as $item)
                    <x-media.poster :item="$item" />
                @endforeach
            </div>

            <button type="button"
                    x-show="!atEnd"
                    x-cloak
                    x-transition.opacity
                    @click="scrollBy(1)"
                    class="absolute right-0 top-0 z-20 hidden h-full w-12 items-center justify-center
                           bg-gradient-to-l from-base-900 to-transparent text-ink-100
                           opacity-0 transition group-hover/rail:opacity-100 focus:opacity-100 md:flex"
                    aria-label="Scroll {{ $title }} right">
                <svg class="size-7 drop-shadow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>
    </section>
@endif

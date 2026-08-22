@props(['item', 'eyebrow' => null])

@php
    $subtitle = $item->subtitle();
    $year = $item->year();
    $genres = $item->tags->where('type', 'genre')->pluck('value')->take(3);
@endphp

<section class="relative isolate -mt-px min-h-[62vh] w-full overflow-hidden sm:min-h-[70vh]">

    {{-- Backdrop. Blurred and scaled so a 2:3 poster can fill a 16:9 banner
         without the upscaling looking soft or letterboxed. --}}
    @if ($item->coverUrl())
        <img src="{{ $item->coverUrl() }}"
             alt=""
             aria-hidden="true"
             class="absolute inset-0 -z-10 size-full scale-110 blur-2xl brightness-[0.45] saturate-150">
    @else
        <div class="art-placeholder absolute inset-0 -z-10 size-full" aria-hidden="true"></div>
    @endif

    <div class="hero-fade-bottom absolute inset-0 -z-10" aria-hidden="true"></div>
    <div class="hero-fade-left absolute inset-0 -z-10" aria-hidden="true"></div>

    <div class="flex min-h-[62vh] items-end px-4 pb-10 pt-28 sm:pt-32 sm:min-h-[70vh] sm:px-8 sm:pb-14">
        <div class="flex w-full max-w-5xl gap-5 sm:gap-8">

            {{-- Foreground artwork, sharp against the blurred backdrop. --}}
            <div class="hidden w-40 shrink-0 sm:block lg:w-52">
                <div class="{{ $item->artworkAspect() }} overflow-hidden rounded-lg shadow-2xl shadow-black/70 ring-1 ring-white/10">
                    @if ($item->coverUrl())
                        <img src="{{ $item->coverUrl() }}" alt="" class="size-full">
                    @else
                        <div class="art-placeholder flex size-full items-center justify-center">
                            <span class="text-5xl opacity-40" aria-hidden="true">{{ $item->typeGlyph() }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="min-w-0 flex-1">
                @if ($eyebrow)
                    <p class="mb-2 text-xs font-bold uppercase tracking-[0.2em] text-accent">
                        {{ $eyebrow }}
                    </p>
                @endif

                <h1 class="text-shadow-hero text-3xl font-extrabold leading-tight tracking-tight sm:text-5xl lg:text-6xl">
                    {{ $item->title }}
                </h1>

                @if ($subtitle)
                    <p class="text-shadow-hero mt-2 text-base text-ink-300 sm:text-xl">
                        {{ $subtitle }}
                    </p>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-300">
                    @if ($year)
                        <span>{{ $year }}</span>
                    @endif

                    @if ($item->user_rating)
                        <span class="flex items-center gap-1 text-amber-400">
                            ★ {{ $item->user_rating }}/10
                        </span>
                    @endif

                    @foreach ($genres as $genre)
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-xs">{{ $genre }}</span>
                    @endforeach
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <a href="{{ route('media.show', $item) }}"
                       class="inline-flex items-center gap-2 rounded-md bg-ink-100 px-6 py-2.5 text-sm font-bold text-base-900 transition hover:bg-white">
                        <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                        View details
                    </a>

                    <a href="{{ route('media.browse', $item->type->value) }}"
                       class="inline-flex items-center gap-2 rounded-md bg-white/15 px-6 py-2.5 text-sm font-semibold text-ink-100 backdrop-blur transition hover:bg-white/25">
                        Browse {{ str($item->type->label())->plural() }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

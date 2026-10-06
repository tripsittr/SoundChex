@props(['item'])

@php
    $subtitle = $item->subtitle();
    $year = $item->year();

    // An episode is named by its series, not by its own title. A Continue
    // Watching card reading "But at Last Came a Knock" says nothing about
    // what is being watched; the episode is the detail, and it goes on the
    // line below as "S1:E9 ...".
    // Only from relations the caller eager-loaded. Reading an unloaded one
    // here is a query per tile on a page of them -- which the home-page cost
    // guard catches, and which is why both are checked rather than assumed.
    $show = $item->relationLoaded('showMetadata') ? $item->showMetadata : null;
    $series = $item->relationLoaded('parent') ? $item->parent?->title : null;

    $season = $show?->season_number;
    $number = $show?->episode_number;
    $episodeTitle = $show?->episode_title ?? $item->title;

    $headline = $series ?? $item->title;

    $episodeLine = null;

    if ($series !== null) {
        $marker = match (true) {
            $season !== null && $number !== null => "S{$season}:E{$number}",
            $number !== null => "E{$number}",
            default => '',
        };

        $episodeLine = trim($marker.' '.$episodeTitle);
    }
@endphp

<a href="{{ route('media.show', $item) }}"
   class="poster group focus:outline-none">

    <div class="{{ $item->artworkAspect() }} w-full">
        @if ($item->coverUrl())
            <img src="{{ $item->coverUrl() }}"
                 alt=""
                 loading="lazy"
                 decoding="async"
                 class="size-full">
        @else
            {{-- No artwork: a type glyph reads as intentional, a grey box reads as broken. --}}
            <div class="art-placeholder flex size-full items-center justify-center">
                <span class="text-4xl opacity-40" aria-hidden="true">{{ $item->typeGlyph() }}</span>
            </div>
        @endif
    </div>

    {{-- Title overlay, always present so cards read without hovering. --}}
    <div class="scrim absolute inset-x-0 bottom-0 p-2.5 pt-8">
        <p class="line-clamp-2 text-sm font-semibold leading-tight text-ink-100">
            {{ $headline }}
        </p>

        @if ($episodeLine)
            <p class="mt-0.5 line-clamp-1 text-xs text-ink-300">{{ $episodeLine }}</p>
        @elseif ($subtitle || $year)
            <p class="mt-0.5 line-clamp-1 text-xs text-ink-300">
                {{ collect([$subtitle, $year])->filter()->implode(' • ') }}
            </p>
        @endif
    </div>

    {{--
        How far through this the viewer is. Drawn only where the length is
        known as well: a fraction of an unknown duration is a guess presented
        as fact.
    --}}
    @if (($percent = $item->resumePercent()) !== null)
        <span class="absolute inset-x-0 bottom-0 h-[3px] bg-white/25">
            <span class="block h-full bg-accent" style="width: {{ $percent }}%"></span>
        </span>
    @endif

    {{-- Hover-to-play, the way a streaming app surfaces playback without a
         permanent button cluttering every tile. Desktop only: on touch there
         is no hover, and the tile itself opens the detail page. --}}
    @if ($item->type === \App\Enums\MediaItemType::Music && $item->file_path)
        <button type="button"
                data-play="{{ json_encode([$item->playerPayload()]) }}"
                class="absolute bottom-2 right-2 hidden size-10 items-center justify-center rounded-full
                       bg-accent text-white opacity-0 shadow-lg transition
                       hover:bg-accent-hot focus:opacity-100 group-hover:opacity-100 md:flex"
                aria-label="Play {{ $item->title }}">
            <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M8 5v14l11-7z" />
            </svg>
        </button>
    @endif

    @if ($item->wishlist && ! $item->owned)
        <span class="absolute left-1.5 top-1.5 rounded bg-base-900/80 px-1.5 py-0.5 text-[0.625rem] font-semibold uppercase tracking-wide text-ink-300">
            Wishlist
        </span>
    @endif
</a>

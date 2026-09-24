@props([
    /** The track. */
    'item',
    /** The queue this row plays into, so starting here leaves the rest behind it. */
    'queue' => null,
    /** Position within that queue. */
    'index' => 0,
    /** Shown instead of artwork when the list is already numbered. */
    'number' => null,
])

@php
    // A row with no queue plays alone, which is right for a search result but
    // wrong inside an album — the caller decides.
    //
    // With a queue, the row carries only its position: the list writes the
    // queue once into `data-queue` and every row points into it. Writing it
    // here instead put the whole queue on both of this row's buttons — 96
    // copies of the same 26 KB on a 48-song page, 83% of the HTML, and a
    // 26 KB JSON.parse on every tap.
    $payload = $queue === null ? collect([$item->playerPayload()])->toJson() : null;
@endphp

{{--
    One song, as a row.

    Music is a list medium: an album is an ordered sequence and a poster grid
    cannot show track order, duration, or per-track actions. The grid stays for
    films and books, where the cover is the identity.

    Every action the kebab offers is reachable here without it — play from the
    artwork, download from its own button — because a menu is a place to hide
    things, not the only way to reach them.
--}}
<li data-long-press-menu
    class="group flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-base-800/60">

    {{-- Artwork doubles as the play button. --}}
    <button type="button"
            @if ($payload !== null) data-play="{{ $payload }}" @endif
            data-play-index="{{ $index }}"
            class="relative flex size-11 shrink-0 items-center justify-center overflow-hidden rounded bg-base-700"
            aria-label="Play {{ $item->title }}">
        @if ($number !== null)
            <span class="text-sm tabular-nums text-ink-500 group-hover:opacity-0">{{ $number }}</span>
        @elseif ($item->coverUrl())
            <img src="{{ $item->coverUrl() }}" alt="" loading="lazy"
                 class="size-full object-cover transition group-hover:opacity-40">
        @else
            <span class="text-lg text-ink-600 group-hover:opacity-0">♪</span>
        @endif

        <svg class="absolute size-5 text-ink-100 opacity-0 transition group-hover:opacity-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M8 5v14l11-7z" />
        </svg>
    </button>

    {{-- Tapping the row plays it. This was a link to the detail page, which is
         backwards for a music library: the overwhelmingly common intent is to
         listen, and routing that through a details page put a page load between
         the user and the song. Details moved to the kebab, where the
         occasionally-wanted things live. --}}
    <button type="button"
            @if ($payload !== null) data-play="{{ $payload }}" @endif
            data-play-index="{{ $index }}"
            class="min-w-0 flex-1 text-left"
            aria-label="Play {{ $item->title }}">
        <span class="block truncate text-sm text-ink-100">{{ $item->title }}</span>
        <span class="block truncate text-xs text-ink-500">{{ $item->subtitle() }}</span>
    </button>

    @if ($item->musicMetadata?->album)
        {{-- Hidden on a phone: the album is one tap away on the detail page,
             and the row is already tight. --}}
        <a href="{{ route('media.album', ['artist' => $item->musicMetadata->artist, 'album' => $item->musicMetadata->album]) }}"
           class="hidden max-w-[12rem] truncate text-xs text-ink-500 hover:text-ink-300 hover:underline lg:block">
            {{ $item->musicMetadata->album }}
        </a>
    @endif

    <button type="button"
            data-download="{{ $item->id }}"
            data-download-url="{{ route('media.stream', $item) }}"
            data-download-title="{{ $item->title }}"
            data-download-type="music"
            data-state="idle"
            class="download-btn download-btn--icon flex size-8 shrink-0 items-center justify-center rounded text-ink-500 transition hover:bg-base-700 hover:text-ink-100 md:opacity-0 md:group-hover:opacity-100 md:focus:opacity-100"
            aria-label="Download {{ $item->title }}">
        {{-- Three glyphs, swapped by CSS on data-state. Kept in the markup
             rather than drawn in JS so the state is visible in the DOM and a
             test can assert on it without reading pixels. --}}
        <svg data-icon="idle" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>

        {{-- Spins while the transfer runs. --}}
        <svg data-icon="downloading" class="size-4 download-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke-opacity="0.25" />
            <path d="M21 12a9 9 0 00-9-9" stroke-linecap="round" />
        </svg>

        {{-- Green tick once the bytes are on the device. --}}
        {{-- Filled rather than outlined: at 16px a thin stroked circle reads as
             faint and indecisive, where the point of this state is to be
             recognisable at a glance while scrolling. The tick is knocked out of
             the disc, which is the convention every music app uses. --}}
        <svg data-icon="stored" class="size-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" fill="currentColor" />
            <path d="M7.5 12.4l3 3 6-6.4" fill="none" stroke="var(--color-base-900)" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round" />
        </svg>

        {{-- A failure has to be visible: these rows have no status line. --}}
        <svg data-icon="failed" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v4.5M12 16h.01" stroke-linecap="round" />
        </svg>
    </button>

    <x-media.track-menu :items="collect([$item])" :label="$item->title" />

    @if ($item->musicMetadata?->duration_ms)
        <span class="hidden w-11 shrink-0 text-right text-xs tabular-nums text-ink-500 sm:block">
            {{ sprintf('%d:%02d',
                floor($item->musicMetadata->duration_ms / 60000),
                floor(($item->musicMetadata->duration_ms % 60000) / 1000)) }}
        </span>
    @endif

    {{-- At the end of a track row: a plugin's own per-track action — a rating,
         a share, a "why is this here" (S-318). Last, so it cannot displace the
         duration or the menu. --}}
    @pluginSlot('song.row.actions', $item)
</li>

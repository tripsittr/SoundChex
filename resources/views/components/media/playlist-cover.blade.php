@props([
    'url' => null,      // the playlist's own cover image URL, or null
    'mosaic' => [],     // up to 4 track cover URLs for the fallback
    'rounded' => 'rounded-lg',
])

{{--
    A playlist cover: its uploaded image when it has one, otherwise a 2×2 mosaic
    of the first four tracks' covers (Spotify's fallback), or a note glyph when
    there is nothing to show yet. Square; the caller sizes it.
--}}
<div {{ $attributes->merge(['class' => "relative aspect-square overflow-hidden $rounded bg-base-700"]) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" class="size-full object-cover" loading="lazy">
    @elseif (count($mosaic))
        <div class="grid size-full grid-cols-2 grid-rows-2">
            @for ($i = 0; $i < 4; $i++)
                @if (isset($mosaic[$i]))
                    <img src="{{ $mosaic[$i] }}" alt="" class="size-full object-cover" loading="lazy">
                @else
                    <div class="size-full bg-base-700"></div>
                @endif
            @endfor
        </div>
    @else
        <div class="flex size-full items-center justify-center text-ink-600">
            <svg class="size-1/3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 18V5l12-2v13" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="16" r="3" />
            </svg>
        </div>
    @endif
</div>

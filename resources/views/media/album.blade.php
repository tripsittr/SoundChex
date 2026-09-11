@php
    /**
     * One album, with its tracks listed.
     *
     * The queue is built once here and shared by every play control on the
     * page: the header buttons pass the whole thing, each row passes the same
     * array with its own index. That way playing track 5 leaves the rest of
     * the album queued behind it, which is what makes an album feel like an
     * album rather than a folder of unrelated files.
     */
    $queue = $tracks->map(fn ($track) => $track->playerPayload())->values();

    $downloadable = $tracks->map(fn ($track) => [
        'id' => $track->id,
        'title' => $track->title,
        'size' => $track->playbackSize() ?? 0,
        'url' => route('media.stream', $track),
    ])->values();

    $cover = $tracks->firstWhere(fn ($track) => filled($track->coverUrl()))?->coverUrl();
@endphp

<x-media.layout :title="$album">
    {{-- The queue, once. Every play control below — the header buttons and
         each track row — points into it rather than repeating it. --}}
    <div class="clears-header mx-auto max-w-5xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8"
         data-play-queue="{{ $queue->toJson() }}">

        {{-- Header: artwork beside the album's identity and its actions. --}}
        <div class="flex flex-col gap-6 sm:flex-row sm:items-end">
            <div class="mx-auto aspect-square w-44 shrink-0 overflow-hidden rounded-lg bg-base-700 shadow-xl shadow-black/40 sm:mx-0 sm:w-52">
                @if ($cover)
                    <img src="{{ $cover }}" alt="" class="size-full object-cover">
                @else
                    <div class="flex size-full items-center justify-center text-5xl text-ink-600">♪</div>
                @endif
            </div>

            <div class="min-w-0 flex-1 text-center sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wider text-ink-500">Album</p>
                <h1 class="mt-1 text-2xl font-bold text-ink-100 sm:text-4xl">{{ $album }}</h1>

                <p class="mt-2 text-sm text-ink-400">
                    <a href="{{ route('media.artist', ['name' => $artist]) }}"
                       class="font-medium text-ink-200 hover:text-ink-100 hover:underline">{{ $artist }}</a>
                    <span class="text-ink-600">·</span>
                    {{ $tracks->count() }} {{ Str::plural('track', $tracks->count()) }}
                    @if ($duration)
                        <span class="text-ink-600">·</span>
                        {{ $duration >= 3600
                            ? floor($duration / 3600) . ' hr ' . floor(($duration % 3600) / 60) . ' min'
                            : max(1, round($duration / 60)) . ' min' }}
                    @endif
                </p>

                {{-- One action row.
                     Play, Shuffle, then everything else behind the overflow —
                     which is what every music app does. These were three
                     stacked rows: Play and Shuffle, then Download on its own
                     line, then a centred "More" floating alone. --}}
                <div class="album-actions mt-5 flex items-center justify-center gap-2 sm:justify-start">
                    <button type="button"
                            data-play-index="0"
                            class="inline-flex flex-1 items-center justify-center gap-2 rounded-full bg-accent px-6 py-3 text-sm font-semibold text-white transition hover:bg-accent-hot sm:flex-none">
                        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                        Play
                    </button>

                    {{-- Shuffle is its own control rather than a mode toggle:
                         "shuffle this album" is a different intent from
                         "shuffle whatever happens to be playing". --}}
                    <button type="button"
                            data-play-index="0"
                            data-play-shuffle="true"
                            class="flex size-11 shrink-0 items-center justify-center rounded-full border border-base-500 text-ink-200 transition hover:border-ink-500 hover:text-ink-100"
                            aria-label="Shuffle this album">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <button type="button"
                            data-download-batch
                            data-state="idle"
                            data-tracks="{{ $downloadable->toJson() }}"
                            class="download-btn download-btn--circle flex size-11 shrink-0 items-center justify-center rounded-full border border-base-500 text-ink-200 transition hover:border-ink-500 hover:text-ink-100"
                            aria-label="Download this album">
                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                        </svg>
                    </button>

                    <x-media.track-menu :items="$tracks" :label="$album" />
                </div>

                <p id="download-status" class="download-status mt-3 hidden"></p>
            </div>
        </div>

        {{-- Track listing. --}}
        <ol class="mt-10 divide-y divide-base-700/60">
            @php $disc = null; @endphp

            @foreach ($tracks as $index => $track)
                @if ($multiDisc && ($track->musicMetadata?->discNumber() ?? 1) !== $disc)
                    @php $disc = $track->musicMetadata?->discNumber() ?? 1; @endphp
                    <li class="pb-2 pt-6 text-xs font-semibold uppercase tracking-wider text-ink-500">
                        Disc {{ $disc }}
                    </li>
                @endif

                <li data-long-press-menu class="group flex items-center gap-3 py-2.5">
                    {{-- The number becomes a play button on hover, so the row
                         stays quiet until it is being used. --}}
                    <button type="button"
                            data-play-index="{{ $index }}"
                            class="relative flex size-8 shrink-0 items-center justify-center rounded text-sm tabular-nums text-ink-500 transition hover:bg-base-700 hover:text-ink-100"
                            aria-label="Play {{ $track->title }}">
                        <span class="group-hover:opacity-0">{{ $track->musicMetadata?->trackNumber() ?? $index + 1 }}</span>
                        <svg class="absolute size-4 opacity-0 transition group-hover:opacity-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        <a href="{{ route('media.show', $track) }}" class="block truncate text-sm text-ink-100 hover:underline">
                            {{ $track->title }}
                        </a>
                        @if ($track->musicMetadata?->artist && $track->musicMetadata->artist !== $artist)
                            <span class="block truncate text-xs text-ink-500">{{ $track->musicMetadata->artist }}</span>
                        @endif
                    </div>

                    <x-media.track-menu :items="collect([$track])" :label="$track->title" />

                    @if ($track->musicMetadata?->duration_ms)
                        <span class="w-12 shrink-0 text-right text-xs tabular-nums text-ink-500">
                            {{ sprintf('%d:%02d',
                                floor($track->musicMetadata->duration_ms / 60000),
                                floor(($track->musicMetadata->duration_ms % 60000) / 1000)) }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
</x-media.layout>

@php
    /**
     * One artist: their albums, then any tracks belonging to none.
     *
     * Singles get the same row treatment as an album's tracks, so playing one
     * queues the rest behind it rather than playing in isolation.
     */
    $queue = $singles->map(fn ($track) => $track->playerPayload())->values();
    $appearsOnQueue = $appearsOn->map(fn ($track) => $track->playerPayload())->values();
@endphp

<x-media.layout :title="$artist">
    <div class="clears-header mx-auto max-w-6xl px-4 pb-16 pt-32 sm:pt-36 sm:px-8">

        {{-- The profile is null for most of a self-hosted library — an artist
             MusicBrainz has never heard of, or one whose name matches several
             people and was refused rather than guessed at. The header reads the
             same either way; the picture and the prose are additions to it, not
             the thing it is built around. --}}
        <div class="flex items-start gap-5">
            @if ($profile?->headshot_url)
                <img src="{{ $profile->headshot_url }}"
                     alt=""
                     loading="lazy"
                     decoding="async"
                     class="hidden size-28 shrink-0 rounded-full object-cover ring-1 ring-base-600 sm:block sm:size-36">
            @endif

            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wider text-ink-500">
                    {{ $profile?->artist_type === 'Group' ? 'Band' : 'Artist' }}
                </p>

                <h1 class="mt-1 text-2xl font-bold text-ink-100 sm:text-4xl">{{ $artist }}</h1>

                @if ($profile?->disambiguation)
                    {{-- Often the single most useful line on the page, when two
                         artists share a name. --}}
                    <p class="mt-1 text-sm text-ink-400">{{ $profile->disambiguation }}</p>
                @endif

                @php
                    $facts = collect([
                        $profile?->country,
                        $profile?->began ? Str::before($profile->began, '-') . ($profile->ended ? '–' . Str::before($profile->ended, '-') : '') : null,
                    ])->filter();
                @endphp

                @if ($facts->isNotEmpty())
                    <p class="mt-1 text-sm text-ink-500">{{ $facts->implode(' · ') }}</p>
                @endif

        <p class="mt-2 text-sm text-ink-500">
            {{ $albums->count() }} {{ Str::plural('album', $albums->count()) }}
            @if ($singles->isNotEmpty())
                <span class="text-ink-600">·</span>
                {{ $singles->count() }} {{ Str::plural('single', $singles->count()) }}
            @endif
        </p>
            </div>
        </div>

        @if ($profile?->biography)
            {{-- Clamped rather than truncated server-side: the whole thing is
                 there for anyone who wants it, and three lines is enough to say
                 who this is. --}}
            <details class="group mt-4 max-w-3xl">
                <summary class="cursor-pointer list-none text-sm leading-relaxed text-ink-400 group-open:hidden">
                    {{ Str::limit($profile->biography, 260) }}
                    @if (strlen($profile->biography) > 260)
                        <span class="text-ink-300 underline">more</span>
                    @endif
                </summary>

                <p class="text-sm leading-relaxed text-ink-400">{{ $profile->biography }}</p>
            </details>
        @endif

        @if ($albums->isNotEmpty())
            <h2 class="mb-4 mt-10 text-lg font-semibold text-ink-100">Albums</h2>

            @php
                // The covers, in one query rather than one per album.
                $samples = \App\Models\MediaItem::whereIn('id', $albums->pluck('sample_item_id')->filter())
                    ->get()
                    ->keyBy('id');
            @endphp

            <div class="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($albums as $album)
                    @php
                        $cover = $samples->get($album->sample_item_id)?->coverUrl();
                    @endphp
                    <a href="{{ route('media.album', ['artist' => $album->artist, 'album' => $album->album]) }}"
                       class="group block">
                        <div class="aspect-square overflow-hidden rounded-lg bg-base-700 shadow-lg shadow-black/30 transition group-hover:shadow-xl">
                            @if ($cover)
                                <img src="{{ $cover }}" alt="" loading="lazy"
                                     class="size-full object-cover transition duration-300 group-hover:scale-105">
                            @else
                                <div class="flex size-full items-center justify-center text-3xl text-ink-600">♪</div>
                            @endif
                        </div>
                        <p class="mt-2 truncate text-sm font-medium text-ink-100">{{ $album->album }}</p>
                        <p class="truncate text-xs text-ink-500">
                            {{ $album->track_count }} {{ Str::plural('track', $album->track_count) }}
                        </p>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- One Downloaded filter for every track row on the page — Singles and
             Appears-on both (S-116). The script filters all
             `li[data-long-press-menu]` rows, so a single control covers both. --}}
        @if ($singles->isNotEmpty() || $appearsOn->isNotEmpty())
            <div class="mt-12 flex justify-end">
                @include('media.partials.downloaded-toggle')
            </div>
        @endif

        @if ($singles->isNotEmpty())
            <h2 class="mb-2 mt-6 text-lg font-semibold text-ink-100">Singles</h2>

            {{-- This list's queue, once; each row points into it by index. --}}
            <ol class="divide-y divide-base-700/60" data-play-queue="{{ $queue->toJson() }}">
                @foreach ($singles as $index => $track)
                    <li data-long-press-menu class="group flex items-center gap-3 py-2.5">
                        <button type="button"
                                data-play-index="{{ $index }}"
                                class="relative flex size-8 shrink-0 items-center justify-center rounded text-sm tabular-nums text-ink-500 transition hover:bg-base-700 hover:text-ink-100"
                                aria-label="Play {{ $track->title }}">
                            <span class="group-hover:opacity-0">{{ $index + 1 }}</span>
                            <svg class="absolute size-4 opacity-0 transition group-hover:opacity-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>

                        <a href="{{ route('media.show', $track) }}"
                           class="min-w-0 flex-1 truncate text-sm text-ink-100 hover:underline">
                            {{ $track->title }}
                        </a>

                        <x-media.track-menu :items="collect([$track])" :label="$track->title" />
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($appearsOn->isNotEmpty())
            {{-- Tracks credited to this artist but led by someone else.
                 Without this an artist page showed only the records they made
                 alone, and every collaboration was invisible. --}}
            <h2 class="mb-2 mt-12 text-lg font-semibold text-ink-100">Appears on</h2>

            {{-- A different queue from Singles above, so it is scoped to this
                 list rather than shared with it. --}}
            <ol class="divide-y divide-base-700/60" data-play-queue="{{ $appearsOnQueue->toJson() }}">
                @foreach ($appearsOn as $index => $track)
                    <li data-long-press-menu class="group flex items-center gap-3 py-2.5">
                        <button type="button"
                                data-play-index="{{ $index }}"
                                class="relative flex size-8 shrink-0 items-center justify-center rounded text-sm tabular-nums text-ink-500 transition hover:bg-base-700 hover:text-ink-100"
                                aria-label="Play {{ $track->title }}">
                            <span class="group-hover:opacity-0">{{ $index + 1 }}</span>
                            <svg class="absolute size-4 opacity-0 transition group-hover:opacity-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('media.show', $track) }}"
                               class="block truncate text-sm text-ink-100 hover:underline">
                                {{ $track->title }}
                            </a>

                            {{-- Whose record it is. The point of the section is
                                 that these belong to someone else. --}}
                            @php($lead = $track->musicMetadata?->primary_artist ?: $track->musicMetadata?->artist)

                            @if (filled($lead))
                                <a href="{{ route('media.artist', ['name' => $lead]) }}"
                                   class="block truncate text-xs text-ink-500 hover:underline">
                                    {{ $lead }}
                                </a>
                            @endif
                        </div>

                        <x-media.track-menu :items="collect([$track])" :label="$track->title" />
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Below the albums: a plugin's own section about this artist —
             a biography, related artists, tour dates (S-318). --}}
        @pluginSlot('artist.detail', $artist)
    </div>
</x-media.layout>

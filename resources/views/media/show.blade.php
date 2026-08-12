@php
    /**
     * Metadata rows are built per type so the view stays declarative and each
     * type shows only the fields that mean something for it.
     */
    $meta = match ($item->type) {
        \App\Enums\MediaItemType::Music => [
            'Artist'   => $item->musicMetadata?->artist,
            'Album'    => $item->musicMetadata?->album,
            'Track'    => $item->musicMetadata?->track_number,
            'Year'     => $item->musicMetadata?->release_year,
            'Label'    => $item->musicMetadata?->label,
            'BPM'      => $item->musicMetadata?->bpm ? (int) $item->musicMetadata->bpm : null,
            'Key'      => trim(($item->musicMetadata?->key ?? '') . ' ' . ($item->musicMetadata?->scale === 'minor' ? 'minor' : ($item->musicMetadata?->scale ?? ''))) ?: null,
            'Duration' => $item->musicMetadata?->duration_ms
                ? gmdate($item->musicMetadata->duration_ms >= 3600000 ? 'H:i:s' : 'i:s', (int) ($item->musicMetadata->duration_ms / 1000))
                : null,
            'Format'   => $item->musicMetadata?->format ? strtoupper($item->musicMetadata->format) : null,
            'ISRC'     => $item->musicMetadata?->isrc,
        ],
        \App\Enums\MediaItemType::Movie => [
            'Director' => $item->movieMetadata?->director,
            'Studio'   => $item->movieMetadata?->studio,
            'Year'     => $item->movieMetadata?->release_year,
            'Runtime'  => $item->movieMetadata?->runtime_minutes ? $item->movieMetadata->runtime_minutes . ' min' : null,
            'Rating'   => $item->movieMetadata?->mpaa_rating,
            'IMDb'     => $item->movieMetadata?->imdb_rating,
            'Language' => $item->movieMetadata?->language,
        ],
        \App\Enums\MediaItemType::Show => [
            'Creator'  => $item->showMetadata?->creator,
            'Network'  => $item->showMetadata?->network,
            'Aired'    => collect([$item->showMetadata?->first_air_year, $item->showMetadata?->last_air_year])->filter()->implode(' – ') ?: null,
            'Seasons'  => $item->showMetadata?->season_count,
            'Episodes' => $item->showMetadata?->episode_count,
            'Status'   => $item->showMetadata?->status ? ucfirst($item->showMetadata->status) : null,
        ],
        \App\Enums\MediaItemType::Book => [
            'Author'    => $item->bookMetadata?->author,
            'Publisher' => $item->bookMetadata?->publisher,
            'Year'      => $item->bookMetadata?->publish_year,
            'Pages'     => $item->bookMetadata?->pages,
            'ISBN'      => $item->bookMetadata?->isbn_13 ?? $item->bookMetadata?->isbn_10,
            'Series'    => $item->bookMetadata?->series_name,
        ],
    };

    $meta = array_filter($meta, fn ($v) => filled($v));
    $genres = $item->tags->where('type', 'genre')->pluck('value');
    $otherTags = $item->tags->where('type', '!=', 'genre');
@endphp

<x-media.layout :counts="$counts" :title="$item->title">

    <article>
        {{-- Backdrop --}}
        <div class="relative isolate min-h-[52vh] w-full overflow-hidden sm:min-h-[60vh]">
            @if ($item->coverUrl())
                <img src="{{ $item->coverUrl() }}" alt="" aria-hidden="true"
                     class="absolute inset-0 -z-10 size-full scale-110 blur-3xl brightness-[0.35] saturate-150">
            @else
                <div class="art-placeholder absolute inset-0 -z-10 size-full" aria-hidden="true"></div>
            @endif

            <div class="hero-fade-bottom absolute inset-0 -z-10" aria-hidden="true"></div>

            <div class="flex min-h-[52vh] items-end px-4 pb-8 pt-28 sm:pt-32 sm:min-h-[60vh] sm:px-8">
                <div class="flex w-full max-w-6xl gap-5 sm:gap-8">

                    <div class="w-28 shrink-0 sm:w-44 lg:w-56">
                        <div class="{{ $item->artworkAspect() }} overflow-hidden rounded-lg shadow-2xl shadow-black/70 ring-1 ring-white/10">
                            @if ($item->coverUrl())
                                <img src="{{ $item->coverUrl() }}" alt="Artwork for {{ $item->title }}" class="size-full">
                            @else
                                <div class="art-placeholder flex size-full items-center justify-center">
                                    <span class="text-5xl opacity-40" aria-hidden="true">{{ $item->typeGlyph() }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="mb-1.5 text-xs font-bold uppercase tracking-[0.2em] text-accent">
                            {{ $item->type->label() }}
                        </p>

                        <h1 class="text-shadow-hero text-2xl font-extrabold leading-tight tracking-tight sm:text-4xl lg:text-5xl">
                            {{ $item->title }}
                        </h1>

                        @if ($item->subtitle())
                            <p class="text-shadow-hero mt-1.5 text-base text-ink-300 sm:text-xl">
                                {{ $item->subtitle() }}
                            </p>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-300">
                            @if ($item->year())
                                <span>{{ $item->year() }}</span>
                            @endif

                            @if ($item->user_rating)
                                <span class="text-amber-400">★ {{ $item->user_rating }}/10</span>
                            @endif

                            @if ($item->owned)
                                <span class="rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs text-emerald-300">Owned</span>
                            @endif

                            @if ($item->wishlist)
                                <span class="rounded-full bg-sky-500/20 px-2 py-0.5 text-xs text-sky-300">Wishlist</span>
                            @endif

                            @if (in_array($item->processing_status?->value, ['needs_review', 'failed'], true))
                                <span class="rounded-full bg-amber-500/20 px-2 py-0.5 text-xs text-amber-300">
                                    {{ $item->processing_status->label() }}
                                </span>
                            @endif
                        </div>

                        @if ($genres->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($genres as $genre)
                                    <a href="{{ route('media.browse', [$item->type->value, 'genre' => $genre]) }}"
                                       class="rounded-full bg-white/10 px-2.5 py-1 text-xs text-ink-100 transition hover:bg-white/20">
                                        {{ $genre }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

        {{-- Playback goes through the persistent now-playing bar rather than an
             inline element, so browsing away doesn't stop the music. --}}
                        @if ($item->type === \App\Enums\MediaItemType::Music && $item->file_path)
                            @php
                                // Queue the whole album from this track on, so
                                // pressing play behaves like an album, not a
                                // one-track dead end.
                                $albumQueue = $item->albumQueue();
                                $startIndex = $albumQueue->search(fn ($queued) => $queued->id === $item->id) ?: 0;
                            @endphp

                            <div class="mt-5 flex flex-wrap items-center gap-3">
                                <button type="button"
                                        data-play="{{ json_encode($albumQueue->map->playerPayload()->values()) }}"
                                        data-play-index="{{ $startIndex }}"
                                        class="inline-flex items-center gap-2 rounded-md bg-ink-100 px-6 py-2.5 text-sm font-bold text-base-900 transition hover:bg-white">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M8 5v14l11-7z" />
                                    </svg>
                                    Play
                                </button>

                                @if ($albumQueue->count() > 1)
                                    <span class="text-sm text-ink-500">
                                        {{ $albumQueue->count() }} tracks in this album
                                    </span>
                                @endif
                            </div>
                        @endif

                        @if (in_array($item->type, [\App\Enums\MediaItemType::Movie, \App\Enums\MediaItemType::Show], true) && $item->file_path)
                            @php
                                $resumeAt = $item->resumePosition();
                                $extension = strtoupper(pathinfo($item->file_path, PATHINFO_EXTENSION));
                            @endphp

                            <div class="mt-5 flex flex-wrap items-center gap-3">
                                @if ($item->isPlayableVideo())
                                    <a href="{{ route('media.watch', $item) }}"
                                       class="inline-flex items-center gap-2 rounded-md bg-ink-100 px-6 py-2.5 text-sm font-bold text-base-900 transition hover:bg-white">
                                        <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M8 5v14l11-7z" />
                                        </svg>
                                        {{ $resumeAt ? 'Resume' : 'Play' }}
                                    </a>

                                    @if ($resumeAt)
                                        <span class="text-sm text-ink-300">
                                            {{ gmdate($resumeAt >= 3600 ? 'H:i:s' : 'i:s', $resumeAt) }} in
                                        </span>
                                    @endif
                                @else
                                    {{-- MKV and AVI don't decode in any browser, so a
                                         player would just show a black rectangle. --}}
                                    <p class="text-sm text-ink-300">
                                        {{ $extension }} can't play in a browser — download it to watch.
                                    </p>
                                @endif

                                <a href="{{ route('media.stream', $item) }}" download
                                   class="inline-flex items-center gap-2 rounded-md bg-white/15 px-6 py-2.5 text-sm font-semibold text-ink-100 backdrop-blur transition hover:bg-white/25">
                                    Download
                                </a>
                            </div>
                        @endif

                        @if ($item->type === \App\Enums\MediaItemType::Book && $item->file_path)
                            @php
                                $format = strtolower(pathinfo($item->file_path, PATHINFO_EXTENSION));
                                // MOBI and AZW3 have no browser renderer worth
                                // shipping, so they're download-only.
                                $readable = in_array($format, ['epub', 'pdf', 'cbz', 'cbr'], true);
                                $progress = $item->progressFor();
                            @endphp

                            <div class="mt-5 flex flex-wrap items-center gap-3">
                                @if ($readable)
                                    <a href="{{ route('media.read', $item) }}"
                                       class="inline-flex items-center gap-2 rounded-md bg-ink-100 px-6 py-2.5 text-sm font-bold text-base-900 transition hover:bg-white">
                                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.5 5 8 4.5 5 5v13c3-.5 5.5 0 7 1.5 1.5-1.5 4-2 7-1.5V5c-3-.5-5.5 0-7 1.5zM12 6.5V20" />
                                        </svg>
                                        {{ $progress?->percent ? 'Continue reading' : 'Read' }}
                                    </a>

                                    @if ($progress?->percent)
                                        <span class="text-sm text-ink-300">{{ $progress->percent }}% read</span>
                                    @endif
                                @endif

                                <a href="{{ route('media.read.file', $item) }}"
                                   download
                                   class="inline-flex items-center gap-2 rounded-md bg-white/15 px-6 py-2.5 text-sm font-semibold text-ink-100 backdrop-blur transition hover:bg-white/25">
                                    Download {{ strtoupper($format) }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Details --}}
        <div class="mx-auto max-w-6xl px-4 py-8 sm:px-8">
            <div class="grid gap-8 lg:grid-cols-3">

                <div class="lg:col-span-2">
                    @if ($item->notes)
                        <h2 class="mb-2 text-lg font-bold">Notes</h2>
                        <p class="whitespace-pre-line leading-relaxed text-ink-300">{{ $item->notes }}</p>
                    @endif

                    @if ($item->people->isNotEmpty())
                        <h2 class="mb-3 mt-8 text-lg font-bold">Cast &amp; Crew</h2>
                        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($item->people as $person)
                                <li class="rounded-lg bg-base-800 p-3">
                                    <p class="truncate text-sm font-medium">{{ $person->name }}</p>
                                    @if ($person->pivot->role)
                                        <p class="truncate text-xs capitalize text-ink-500">{{ $person->pivot->role }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <aside>
                    @if ($meta)
                        <h2 class="mb-3 text-lg font-bold">Details</h2>
                        <dl class="divide-y divide-base-600 overflow-hidden rounded-lg bg-base-800">
                            @foreach ($meta as $label => $value)
                                <div class="flex justify-between gap-4 px-4 py-2.5 text-sm">
                                    <dt class="shrink-0 text-ink-500">{{ $label }}</dt>
                                    <dd class="truncate text-right font-medium">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($otherTags->isNotEmpty())
                        <h2 class="mb-2 mt-6 text-sm font-bold uppercase tracking-wide text-ink-500">Tags</h2>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($otherTags as $tag)
                                <span class="rounded bg-base-700 px-2 py-1 text-xs text-ink-300">{{ $tag->value }}</span>
                            @endforeach
                        </div>
                    @endif

                    @php
                        $profile = app(\App\Services\CurrentProfile::class)->get();
                        $inList = $profile?->watchlist()->where('media_item_id', $item->id)->exists() ?? false;
                    @endphp

                    @if ($profile)
                        <button type="button"
                                x-data="{ inList: {{ $inList ? 'true' : 'false' }}, busy: false }"
                                :aria-pressed="inList.toString()"
                                @click="
                                    if (busy) return;
                                    busy = true;
                                    fetch('{{ route('media.watchlist.toggle', $item) }}', {
                                        method: 'POST',
                                        headers: {
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                            Accept: 'application/json',
                                        },
                                    })
                                    .then((r) => r.json())
                                    .then((d) => { inList = d.inWatchlist })
                                    .catch(() => {})
                                    .finally(() => { busy = false });
                                "
                                class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-md border px-4 py-2 text-sm font-medium transition"
                                :class="inList
                                    ? 'border-accent bg-accent/10 text-ink-100'
                                    : 'border-base-500 text-ink-300 hover:border-ink-500 hover:text-ink-100'">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path x-show="!inList" stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                                <path x-show="inList" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            <span x-text="inList ? 'In My List' : 'Add to My List'">Add to My List</span>
                        </button>
                    @endif

                    {{-- Offline. Only for something with a file to take:
                         a wishlist entry has nothing to download. --}}
                    @if ($item->hasReadableFile())
                        @php $downloadSize = $item->playbackSize(); @endphp

                        <button type="button"
                                id="download-toggle"
                                data-state="idle"
                                data-item-id="{{ $item->id }}"
                                data-title="{{ $item->title }}"
                                data-type="{{ $item->type->value }}"
                                data-size="{{ $downloadSize ?? 0 }}"
                                data-url="{{ $item->type === \App\Enums\MediaItemType::Book
                                    ? route('media.read.file', $item)
                                    : route('media.stream', $item) }}"
                                class="download-btn mt-3">
                            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                            </svg>
                            <span data-download-label>Download for offline</span>
                            @if ($downloadSize)
                                <span class="ml-auto text-xs text-ink-500">
                                    {{ $downloadSize >= 1073741824
                                        ? round($downloadSize / 1073741824, 1) . ' GB'
                                        : round($downloadSize / 1048576) . ' MB' }}
                                </span>
                            @endif
                        </button>

                        {{-- One action for a whole album; twelve taps
                             otherwise. Only when there is more than one
                             track, or it duplicates the button above. --}}
                        @if ($item->type === \App\Enums\MediaItemType::Music)
                            @php
                                $album = $item->albumQueue()
                                    ->filter(fn ($track) => $track->hasReadableFile())
                                    ->values();
                            @endphp

                            @if ($album->count() > 1)
                                <button type="button"
                                        id="download-album"
                                        data-state="idle"
                                        data-tracks="{{ json_encode($album->map(fn ($track) => [
                                            'id' => $track->id,
                                            'title' => $track->title,
                                            'size' => $track->playbackSize() ?? 0,
                                            'url' => route('media.stream', $track),
                                        ])) }}"
                                        class="download-btn mt-2">
                                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                                    </svg>
                                    <span data-download-label>Download album</span>
                                    <span class="ml-auto text-xs text-ink-500">{{ $album->count() }} tracks</span>
                                </button>
                            @endif
                        @endif

                        <p id="download-status" class="download-status hidden"></p>
                    @endif

                    @unless (app(\App\Services\CurrentProfile::class)->isKids())
                    <a href="{{ $item->adminEditUrl() }}"
                       class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-base-500 px-4 py-2 text-sm font-medium text-ink-300 transition hover:border-ink-500 hover:text-ink-100">
                        Edit in management
                    </a>
                    @endunless
                </aside>
            </div>
        </div>

        @if ($related->isNotEmpty())
            <x-media.rail title="More Like This" :items="$related" />
        @endif
    </article>

</x-media.layout>

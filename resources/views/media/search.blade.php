@php
    /**
     * Turns the search service's \x02…\x03 sentinels into <mark> elements.
     *
     * The text is escaped first, so a book or a subtitle containing angle
     * brackets can't inject anything — the marks are added afterwards, which
     * is why the server never sends HTML.
     */
    $highlight = function (?string $text): string {
        $escaped = e((string) $text);

        return str_replace(
            ["\u{0002}", "\u{0003}"],
            ['<mark class="search-mark">', '</mark>'],
            $escaped,
        );
    };
@endphp

<x-media.layout :counts="$counts" title="Search">

    <section class="mx-auto max-w-5xl px-4 pt-28 sm:px-8 sm:pt-32">

        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
            @if ($term !== '')
                Results for &ldquo;{{ $term }}&rdquo;
            @else
                Search your library
            @endif
        </h1>

        @if ($term !== '')
            <p class="mt-1 text-sm text-ink-500">
                {{ $search['total'] }} {{ str('result')->plural($search['total']) }}
                across titles, dialogue and book text
            </p>
        @endif

        @if ($term === '')
            <div class="mt-10 space-y-3 text-ink-300">
                <p>Search reaches everything at once:</p>
                <ul class="space-y-1.5 text-sm text-ink-500">
                    <li>Titles, artists, albums, authors and directors</li>
                    <li>Spoken dialogue &mdash; jump straight to the moment it&rsquo;s said</li>
                    <li>Text inside your books, down to the page</li>
                    <li>Cast, crew and genres</li>
                </ul>
            </div>
        @elseif ($search['total'] === 0)
            <div class="mt-12 rounded-lg border border-dashed border-base-500 py-16 text-center">
                <p class="text-ink-300">Nothing matched &ldquo;{{ $term }}&rdquo;.</p>
                <p class="mt-1 text-sm text-ink-500">
                    Try a title, a person, a line of dialogue, or a phrase from a book.
                </p>
            </div>
        @else
            <div class="mt-10 space-y-12 pb-20">
                @foreach ($search['groups'] as $group)
                    <div>
                        <div class="mb-4 flex items-baseline gap-3">
                            <h2 class="text-lg font-semibold">{{ $group['label'] }}</h2>
                            @if ($group['hint'])
                                <span class="text-xs text-ink-500">{{ $group['hint'] }}</span>
                            @endif
                        </div>

                        {{-- Titles keep the poster grid; everything else is a
                             line of text with a destination, which reads
                             better as a list. --}}
                        @if ($group['key'] === 'titles')
                            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
                                @foreach ($group['results'] as $result)
                                    <x-media.poster :item="$result['item']" />
                                @endforeach
                            </div>

                        @elseif ($group['key'] === 'people')
                            <div class="space-y-1">
                                @foreach ($group['results'] as $result)
                                    <div class="rounded-lg border border-base-600 px-4 py-3">
                                        <p class="text-sm font-medium text-ink-100">{{ $result['label'] }}</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($result['items'] as $item)
                                                <a href="{{ route('media.show', $item) }}"
                                                   class="rounded-full bg-base-700 px-3 py-1 text-xs text-ink-300 transition hover:bg-base-600 hover:text-ink-100">
                                                    {{ $item->title }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        @elseif ($group['key'] === 'tags')
                            <div class="flex flex-wrap gap-2">
                                @foreach ($group['results'] as $result)
                                    <a href="{{ $result['url'] }}"
                                       class="rounded-full border border-base-500 px-4 py-1.5 text-sm text-ink-300 transition hover:border-accent hover:text-ink-100">
                                        {{ $result['label'] }}
                                        <span class="ml-1 text-xs text-ink-500">{{ $result['detail'] }}</span>
                                    </a>
                                @endforeach
                            </div>

                        @else
                            {{-- Dialogue and book pages: a snippet, and where
                                 it lives. --}}
                            <div class="space-y-1">
                                @foreach ($group['results'] as $result)
                                    <a href="{{ $result['url'] }}"
                                       class="flex gap-4 rounded-lg px-4 py-3 transition hover:bg-base-700">
                                        <span class="w-16 shrink-0 pt-0.5 text-right text-xs tabular-nums text-ink-500">
                                            {{ $result['timestamp'] ?? 'p' . $result['page'] }}
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm leading-relaxed text-ink-100">
                                                {!! $highlight($result['snippet']) !!}
                                            </span>
                                            <span class="mt-1 block text-xs text-ink-500">
                                                {{ $result['label'] }}
                                                @if ($result['ocr'] ?? false)
                                                    <span class="ml-1 rounded border border-base-500 px-1 py-px text-[10px] uppercase tracking-wide">
                                                        scanned
                                                    </span>
                                                @endif
                                            </span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <style>
        .search-mark {
            background: color-mix(in srgb, var(--color-accent) 35%, transparent);
            color: inherit;
            border-radius: 2px;
            padding: 0 2px;
        }
    </style>

</x-media.layout>

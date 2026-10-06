{{--
    The review queue (#480), built to the layout the user approved (#481).

    A queue, not a table: one decision at a time with its evidence and the two
    or three plausible answers. The six tabs this replaces were named after the
    system's taxonomy rather than anything a person sets out to do.

    Styling comes from the panel's own Filament theme rather than the media
    centre's tokens, because this is admin chrome — Filament's `fi-` classes and
    its dark-mode variants keep it consistent with every other page here. The
    prototype used --sc-* tokens directly; this is the same design expressed in
    the surface it actually lives on.
--}}
<x-filament-panels::page>
    @php
        $counts = $this->counts;
        $queue = $this->queue;
        $current = $this->current;
        $ask = $this->ask;
        $original = $this->original;
        $jobs = \App\Services\Review\ReviewQueue::JOBS;
        $titles = \App\Services\Review\ReviewQueue::TITLES;
        $total = array_sum($counts);
    @endphp

    {{-- Entry by job. An empty job is dimmed, never hidden: the set of
         controls must not shift under somebody between visits, and the user
         asked for "File problems" to keep its slot even while empty. --}}
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="What do you want to do">
        @foreach ($jobs as $key => $label)
            @php $n = $counts[$key] ?? 0; @endphp
            <button
                type="button"
                wire:click="openJob('{{ $key }}')"
                @class([
                    'flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm transition',
                    'border-primary-600 bg-primary-50 text-primary-700 dark:border-primary-500 dark:bg-primary-500/10 dark:text-primary-300' => $job === $key,
                    'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5' => $job !== $key,
                    'opacity-50' => $n === 0 && $job !== $key,
                ])
                aria-pressed="{{ $job === $key ? 'true' : 'false' }}"
            >
                {{ $label }}
                <span @class([
                    'rounded-full px-2 py-0.5 text-xs font-medium tabular-nums',
                    'bg-primary-600 text-white' => $job === $key && $n > 0,
                    'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-400' => ! ($job === $key && $n > 0),
                ])>{{ $n }}</span>
            </button>
        @endforeach

        <div class="ms-auto text-xs text-gray-500 dark:text-gray-400">
            <span class="rounded border border-gray-300 px-1.5 py-0.5 font-mono dark:border-white/10">J</span>
            <span class="rounded border border-gray-300 px-1.5 py-0.5 font-mono dark:border-white/10">K</span>
            move
            <span class="ms-1 rounded border border-gray-300 px-1.5 py-0.5 font-mono dark:border-white/10">S</span>
            skip
        </div>
    </div>

    @if ($total === 0)
        {{-- A designed empty state: names what will appear and when, rather
             than an empty panel that reads as broken. --}}
        <div class="flex min-h-[22rem] items-center justify-center rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="max-w-sm px-6 text-center">
                <x-filament::icon
                    icon="heroicon-o-check-circle"
                    class="mx-auto h-10 w-10 text-success-500"
                />
                <h2 class="mt-3 text-lg font-semibold text-gray-950 dark:text-white">Nothing needs you</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Items appear here when a file cannot be identified, a duplicate is found,
                    cover art is uncertain, or a move fails. The sweeper checks every five minutes.
                </p>
            </div>
        </div>
    @else
        <div
            class="grid gap-4 lg:grid-cols-[18rem_1fr]"
            x-data
            {{-- A queue is only fast if it can be driven without the mouse.
                 Ignored while typing, so a search box stays usable. --}}
            @keydown.window.debounce.50ms="
                if ($event.target.matches('input, textarea, select')) return;
                const k = $event.key.toLowerCase();
                if (k === 'j') { $event.preventDefault(); $wire.next(); }
                else if (k === 'k') { $event.preventDefault(); $wire.previous(); }
                else if (k === 's') { $event.preventDefault(); $wire.next(); }
            "
        >
            {{-- The queue. Thin, scannable, and it keeps your place. --}}
            <nav
                class="max-h-[32rem] overflow-y-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 lg:max-h-[42rem]"
                aria-label="{{ $titles[$job] ?? 'Queue' }}"
            >
                <div class="sticky top-0 z-10 flex items-baseline justify-between gap-2 border-b border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $titles[$job] ?? 'Queue' }}
                    </h2>
                    <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $queue->count() }} waiting
                    </span>
                </div>

                @forelse ($queue as $row)
                    <button
                        type="button"
                        wire:click="select({{ $row->id }})"
                        wire:key="row-{{ $row->id }}"
                        @class([
                            'flex w-full items-center gap-3 border-b border-gray-100 px-4 py-2.5 text-start transition dark:border-white/5',
                            'bg-primary-50 dark:bg-primary-500/10' => $current?->id === $row->id,
                            'hover:bg-gray-50 dark:hover:bg-white/5' => $current?->id !== $row->id,
                        ])
                        @if ($current?->id === $row->id) aria-current="true" @endif
                    >
                        {{-- The cover, falling back to a type icon only when
                             there is none. The list drew the icon for *every*
                             row regardless, so a queue of 89 cover-art
                             questions showed 89 identical music notes -- the
                             one view where the artwork is the thing being
                             judged. Fixing the detail pane alone left this
                             looking unchanged, which is how the owner found
                             it. --}}
                        @if ($row->coverUrl())
                            <img
                                src="{{ $row->coverUrl() }}"
                                alt=""
                                loading="lazy"
                                class="h-9 w-9 flex-none rounded bg-gray-100 object-cover dark:bg-white/5"
                            >
                        @else
                            <span class="flex h-9 w-9 flex-none items-center justify-center rounded bg-gray-100 text-gray-400 dark:bg-white/5">
                                <x-filament::icon
                                    :icon="$row->type?->value === 'music' ? 'heroicon-m-musical-note' : 'heroicon-m-film'"
                                    class="h-4 w-4"
                                />
                            </span>
                        @endif
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $row->title }}
                            </span>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $row->musicMetadata?->artist ?? $row->type?->value }}
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">Nothing in this one.</p>
                @endforelse
            </nav>

            {{-- The decision, at full size. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                @if ($current === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nothing selected. Pick something from the queue.
                    </p>
                @else
                    <div class="flex flex-wrap items-center gap-2 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <span>{{ $titles[$job] ?? '' }}</span>
                        @if ($current->match_confidence)
                            <span class="rounded border border-current px-2 py-0.5 normal-case tracking-normal">
                                {{ $current->match_confidence->label() }}
                            </span>
                        @endif
                        <span>#{{ $current->id }}</span>
                    </div>

                    {{-- The cover sits beside the title on *every* job, not
                         only the cover job. It was previously drawn in the
                         `covers` branch alone, so on a library with nothing in
                         that queue the review page showed no artwork at all --
                         38 items to identify and 6 duplicates to judge, each
                         rendered as text while valid artwork sat on disk.
                         Recognising a record by its sleeve is most of how
                         somebody answers these questions. --}}
                    <div class="mt-2 flex items-start gap-4">
                        @if ($current->coverUrl())
                            <img
                                src="{{ $current->coverUrl() }}"
                                alt="Cover art for {{ $current->title }}"
                                loading="lazy"
                                class="size-20 shrink-0 rounded-lg border border-gray-200 bg-gray-100 object-cover dark:border-white/10 dark:bg-white/5"
                            >
                        @endif

                        <div class="min-w-0">
                            <h1 class="text-pretty text-2xl font-semibold text-gray-950 dark:text-white">
                                {{ $current->title }}
                            </h1>

                            @if ($current->musicMetadata?->artist)
                                <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $current->musicMetadata->artist }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- The question, in words. The old table showed a row and
                         left the reader to work out what was being asked. --}}
                    @if ($ask)
                        <div class="mt-5 rounded-lg border border-gray-200 border-s-4 border-s-primary-600 bg-gray-50 p-4 dark:border-white/10 dark:border-s-primary-500 dark:bg-white/5">
                            <p class="font-medium text-gray-950 dark:text-white">{{ $ask['question'] }}</p>
                            <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-300">{{ $ask['because'] }}</p>
                        </div>
                    @endif

                    {{-- A duplicate is compared, not listed. --}}
                    @if ($job === \App\Services\Review\ReviewQueue::DUPLICATES && $original)
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            @foreach ([['This copy', $current], ['Already in the library', $original]] as [$label, $side])
                                <div class="min-w-0 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</h3>

                                    {{-- Both covers, side by side. "Is this the
                                         same record?" is answered by eye faster
                                         than by comparing two file paths, and
                                         differing artwork is often the clearest
                                         sign two copies are different releases
                                         rather than duplicates. --}}
                                    @if ($side->coverUrl())
                                        <img
                                            src="{{ $side->coverUrl() }}"
                                            alt="Cover art for {{ $side->title }}"
                                            loading="lazy"
                                            class="mt-2 size-28 rounded-lg border border-gray-200 bg-gray-100 object-cover dark:border-white/10 dark:bg-white/5"
                                        >
                                    @endif

                                    <p class="mt-2 break-all font-mono text-xs text-gray-500 dark:text-gray-400">
                                        {{ $side->file_path }}
                                    </p>
                                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                        <dt class="text-gray-500 dark:text-gray-400">Album</dt>
                                        <dd class="text-gray-950 dark:text-white">{{ $side->musicMetadata?->album ?? '—' }}</dd>
                                        <dt class="text-gray-500 dark:text-gray-400">Size</dt>
                                        <dd class="tabular-nums text-gray-950 dark:text-white">
                                            {{ $side->file_size ? number_format($side->file_size / 1048576, 1).' MB' : '—' }}
                                        </dd>
                                        <dt class="text-gray-500 dark:text-gray-400">Format</dt>
                                        <dd class="text-gray-950 dark:text-white">{{ $side->musicMetadata?->format ?? '—' }}</dd>
                                    </dl>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Cover art is judged by eye, so show it. --}}
                    @if ($job === \App\Services\Review\ReviewQueue::COVERS)
                        <div class="mt-5">
                            @if ($counts[\App\Services\Review\ReviewQueue::COVERS] > 1)
                                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-success-300 bg-success-50 p-3 text-sm dark:border-success-500/30 dark:bg-success-500/10">
                                    <span class="font-semibold text-success-700 dark:text-success-400">
                                        {{ $counts[\App\Services\Review\ReviewQueue::COVERS] }} waiting
                                    </span>
                                    <span class="text-gray-600 dark:text-gray-300">
                                        Each already has its audio decision made, so confirming them together changes nothing on disk.
                                    </span>
                                    <x-filament::button wire:click="acceptAllCovers" size="sm" color="gray">
                                        Confirm all
                                    </x-filament::button>
                                </div>
                            @endif

                            {{-- Through `coverUrl()`, not `Storage::url()`.
                                 The disk helper does not encode the path, and
                                 these paths come from artist and album names:
                                 spaces, commas, `$`, and in one real case a
                                 NO-BREAK SPACE (U+00A0) in "$uicideboy$,&nbsp;Germ".
                                 Emitted raw, the browser never fetched them.
                                 `coverUrl()` rawurlencodes each segment. --}}
                            @if ($current->coverUrl())
                                <img
                                    src="{{ $current->coverUrl() }}"
                                    alt="Cover currently on {{ $current->title }}"
                                    loading="lazy"
                                    class="h-40 w-40 rounded-lg border border-gray-200 object-cover dark:border-white/10"
                                >
                            @endif
                        </div>
                    @endif

                    {{-- Only the evidence needed to answer. --}}
                    <dl class="mt-5 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                        @if ($current->musicMetadata)
                            <dt class="text-gray-500 dark:text-gray-400">Album</dt>
                            <dd @class(['text-gray-950 dark:text-white', 'text-warning-600 dark:text-warning-400' => blank($current->musicMetadata->album)])>
                                {{ $current->musicMetadata->album ?? 'none recorded' }}
                            </dd>
                        @endif

                        @if ($edition = $current->editionSuffix())
                            {{-- Shown as evidence, never stripped: "Psycho Killer
                                 - Acoustic" and "1979 - Remastered 2012" are
                                 distinct recordings (#476). --}}
                            <dt class="text-gray-500 dark:text-gray-400">Edition</dt>
                            <dd class="text-gray-950 dark:text-white">
                                <strong>{{ $edition }}</strong>
                                <span class="text-gray-500 dark:text-gray-400">— kept, not stripped</span>
                            </dd>
                        @endif

                        <dt class="text-gray-500 dark:text-gray-400">File</dt>
                        <dd class="break-all font-mono text-xs text-gray-600 dark:text-gray-300">{{ $current->file_path }}</dd>

                        @if ($current->pipeline_stage)
                            <dt class="text-gray-500 dark:text-gray-400">Stopped at</dt>
                            <dd class="text-gray-950 dark:text-white">{{ $current->pipeline_stage->label() }}</dd>
                        @endif
                    </dl>

                    {{-- Two or three plausible answers, the likely one first. --}}
                    <div class="mt-6 flex flex-wrap gap-2 border-t border-gray-200 pt-5 dark:border-white/10">
                        @if ($job === \App\Services\Review\ReviewQueue::DUPLICATES)
                            <x-filament::button wire:click="keepBoth({{ $current->id }})">
                                Keep both as versions
                            </x-filament::button>
                            <x-filament::button wire:click="keepOne({{ $current->id }})" color="gray">
                                Keep the one already filed
                            </x-filament::button>
                            <x-filament::button wire:click="keepOne({{ $current->id }}, true)" color="gray">
                                Keep this one instead
                            </x-filament::button>
                        @elseif ($job === \App\Services\Review\ReviewQueue::COVERS)
                            <x-filament::button wire:click="acceptCover({{ $current->id }})">
                                This cover is right
                            </x-filament::button>
                            <x-filament::button wire:click="reidentify({{ $current->id }})" color="gray">
                                Fetch artwork again
                            </x-filament::button>
                        @else
                            <x-filament::button wire:click="accept({{ $current->id }})">
                                Looks fine
                            </x-filament::button>
                            <x-filament::button wire:click="reidentify({{ $current->id }})" color="gray">
                                Look it up again
                            </x-filament::button>

                            {{-- The way out of the loop. "Look it up again"
                                 re-runs the same automated query and returns
                                 the same answer, so an item nothing could
                                 settle had no exit: the only actions were to
                                 accept a wrong match, repeat a failing lookup,
                                 or skip forever. --}}
                            <x-filament::button
                                wire:click="openLookup({{ $current->id }})"
                                color="gray">
                                Search by hand
                            </x-filament::button>
                        @endif

                        <x-filament::button wire:click="skip({{ $current->id }})" color="gray" outlined>
                            Skip
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Manual lookup. The automated path returns one verdict; this returns the
         field and lets the person choose, because the reason an item is here is
         that the matcher could not settle it. --}}
    <x-filament::modal id="manual-lookup" width="2xl">
        <x-slot name="heading">Search by hand</x-slot>

        <x-slot name="description">
            Correct the words and search again — dropping a bracketed remix tag or a
            guest credit is usually all it takes.
        </x-slot>

        <div class="space-y-4">
            <div class="flex gap-2">
                <x-filament::input.wrapper class="flex-1">
                    <x-filament::input
                        type="text"
                        wire:model="lookupQuery"
                        wire:keydown.enter.prevent="runLookup"
                        placeholder="Title and artist" />
                </x-filament::input.wrapper>

                <x-filament::button
                    wire:click="runLookup"
                    wire:loading.attr="disabled"
                    wire:target="runLookup">
                    <span wire:loading.remove wire:target="runLookup">Search</span>
                    <span wire:loading wire:target="runLookup">Searching…</span>
                </x-filament::button>
            </div>

            @if ($lookupRan && count($lookupResults) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing matched that. Try fewer words — the title alone often works
                    when the artist is spelled differently here than in MusicBrainz.
                </p>
            @endif

            @if (count($lookupResults) > 0)
                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
                    @foreach ($lookupResults as $candidate)
                        <li class="flex items-center justify-between gap-3 p-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-950 dark:text-white">
                                    {{ $candidate['title'] }}
                                </p>
                                <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                                    {{ $candidate['artist'] }}
                                    @if ($candidate['album'])
                                        — {{ $candidate['album'] }}
                                    @endif
                                    @if ($candidate['year'])
                                        ({{ $candidate['year'] }})
                                    @endif
                                </p>
                            </div>

                            <x-filament::button
                                size="sm"
                                wire:click="chooseMatch({{ $current?->id ?? 0 }}, '{{ $candidate['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="chooseMatch">
                                This one
                            </x-filament::button>
                        </li>
                    @endforeach
                </ul>

                <p class="text-xs opacity-60">
                    Choosing marks the match as confirmed, which is what lets the file be
                    filed — the automatic matcher's own guess is never trusted that far.
                </p>
            @endif
        </div>
    </x-filament::modal>

</x-filament-panels::page>

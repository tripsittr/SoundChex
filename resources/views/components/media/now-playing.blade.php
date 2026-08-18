{{--
    Persistent now-playing bar.

    Hidden (translated off-screen) until something plays, then docked to the
    bottom of every media center page. The progress-URL template carries an
    __ID__ placeholder the player swaps per track, so the bar doesn't need a
    route helper in JavaScript.
--}}
<div id="now-playing"
     data-progress-template="{{ route('media.progress', ['item' => '__ID__']) }}"
     class="fixed inset-x-0 bottom-0 z-40 translate-y-full border-t border-base-600/60 bg-base-800/95 backdrop-blur-md transition-transform duration-300">

    {{-- Seek bar spans the full width, sitting on the bar's top edge. --}}
    <div id="np-seek"
         class="group absolute inset-x-0 -top-1 h-3 cursor-pointer"
         role="slider"
         aria-label="Seek">
        <div class="absolute inset-x-0 top-1 h-1 bg-base-600">
            <div id="np-played" class="h-full bg-accent transition-[width] duration-150" style="width: 0"></div>
        </div>
    </div>

    <div class="flex items-center gap-3 px-3 py-2.5 sm:gap-4 sm:px-5">

        {{-- What's playing --}}
        {{-- Tapping the bar opens the full-screen player, the way every
             music app behaves. It stays an <a> so the item page is still
             reachable by long-press, middle-click or "open in new tab" —
             the click itself is intercepted. --}}
        <a id="np-link" href="#" class="flex min-w-0 flex-1 items-center gap-3">
            <img id="np-artwork" src="" alt=""
                 class="hidden size-11 shrink-0 rounded object-cover sm:size-12">

            <div class="min-w-0">
                <p id="np-title" class="truncate text-sm font-medium text-ink-100"></p>
                <p id="np-subtitle" class="truncate text-xs text-ink-500"></p>
            </div>
        </a>

        {{-- Transport --}}
        <div class="flex shrink-0 items-center gap-1 sm:gap-2">
            <button type="button" id="np-shuffle"
                    class="hidden rounded-md p-2 text-ink-500 transition hover:text-ink-100 sm:block"
                    aria-label="Shuffle">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 3h5v5M4 20l17-17M21 16v5h-5M15 15l6 6M4 4l5 5" />
                </svg>
            </button>

            <button type="button" id="np-prev"
                    class="rounded-md p-2 text-ink-300 transition hover:text-ink-100"
                    aria-label="Previous">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M6 6h2v12H6zM20 6v12l-9-6z" />
                </svg>
            </button>

            <button type="button" id="np-toggle"
                    class="rounded-full bg-ink-100 p-2.5 text-base-900 transition hover:bg-white"
                    aria-label="Play">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path id="np-toggle-icon" d="M8 5v14l11-7z" />
                </svg>
            </button>

            <button type="button" id="np-next"
                    class="rounded-md p-2 text-ink-300 transition hover:text-ink-100"
                    aria-label="Next">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M16 6h2v12h-2zM4 6v12l9-6z" />
                </svg>
            </button>

            <button type="button" id="np-repeat"
                    class="relative hidden rounded-md p-2 text-ink-500 transition hover:text-ink-100 sm:block"
                    data-mode="off"
                    aria-label="Repeat">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 01-4 4H3" />
                </svg>
                {{-- The dot marks repeat-one, which is otherwise
                     indistinguishable from repeat-all at this size. --}}
                <span class="absolute right-1 top-1 size-1.5 rounded-full bg-accent opacity-0 [[data-mode=one]_&]:opacity-100"></span>
            </button>
        </div>

        {{-- Time and volume: desktop only, where there's room. --}}
        <div class="hidden shrink-0 items-center gap-3 lg:flex">
            <span class="text-xs tabular-nums text-ink-500">
                <span id="np-current">0:00</span> / <span id="np-duration">0:00</span>
            </span>

            <input type="range" id="np-volume" min="0" max="100" value="100"
                   class="h-1 w-20 cursor-pointer appearance-none rounded-full bg-base-600 accent-accent"
                   aria-label="Volume">
        </div>
    </div>
</div>

{{--
    The full-screen player.

    Tapping the bar opens this. The bar stays the persistent, always-visible
    control; this is where the things that do not fit on it live — large
    artwork, a real scrubber, shuffle and repeat as proper targets, and the
    queue, which had no UI at all before.

    A sibling of the bar rather than a child, so the bar's transform does not
    become this element's containing block.
--}}
<div id="np-sheet"
     class="fixed inset-0 z-50 flex translate-y-full flex-col bg-base-900 transition-transform duration-300 ease-out"
     role="dialog"
     aria-modal="true"
     aria-label="Now playing"
     aria-hidden="true"
     inert>

    {{-- Clears the notch: the sheet covers the whole screen, status bar
         included. --}}
    <div class="flex items-center justify-between px-4 pb-2"
         style="padding-top: max(0.75rem, env(safe-area-inset-top))">
        <button type="button"
                id="np-sheet-close"
                class="flex size-10 items-center justify-center rounded-full text-ink-300 transition hover:bg-base-700 hover:text-ink-100"
                aria-label="Close player">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <p class="text-xs font-medium uppercase tracking-wider text-ink-500">Now playing</p>

        <button type="button"
                id="np-sheet-queue-toggle"
                class="flex size-10 items-center justify-center rounded-full text-ink-300 transition hover:bg-base-700 hover:text-ink-100"
                aria-label="Show queue"
                aria-expanded="false">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 6h11M4 12h11M4 18h7M17 14v6M17 20l3-2-3-2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>

    {{-- Artwork and the queue occupy the same space; the toggle swaps them. --}}
    <div class="relative min-h-0 flex-1">

        <div id="np-sheet-art-pane" class="flex h-full flex-col items-center justify-center px-8">
            <div class="aspect-square w-full max-w-sm overflow-hidden rounded-xl bg-base-700 shadow-2xl shadow-black/50">
                <img id="np-sheet-artwork" src="" alt="" class="size-full object-cover" hidden>
                <div id="np-sheet-artwork-fallback" class="flex size-full items-center justify-center text-6xl text-ink-600">♪</div>
            </div>
        </div>

        <div id="np-sheet-queue-pane" class="h-full overflow-y-auto px-4" hidden>
            <p class="px-2 pb-2 pt-1 text-xs font-medium uppercase tracking-wider text-ink-500">
                Queue
            </p>
            <ol id="np-sheet-queue" class="space-y-1 pb-4"></ol>
        </div>
    </div>

    {{-- Controls, pinned above the home indicator. --}}
    <div class="px-6 pt-4" style="padding-bottom: max(1.5rem, env(safe-area-inset-bottom))">

        <div class="mb-4 flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p id="np-sheet-title" class="truncate text-lg font-semibold text-ink-100"></p>
                <p id="np-sheet-subtitle" class="truncate text-sm text-ink-400"></p>
            </div>

            {{-- Acting on whatever is playing, so both are wired up as the
                 track changes rather than rendered per item. Beside the title
                 because that is what they act on — in the header they would
                 read as acting on the queue. --}}
            <button type="button"
                    id="np-sheet-download"
                    data-state="idle"
                    class="download-btn download-btn--icon flex size-10 shrink-0 items-center justify-center rounded-full text-ink-400 transition hover:bg-base-700 hover:text-ink-100"
                    aria-label="Download this track">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <button type="button"
                    id="np-sheet-playlist"
                    class="flex size-10 shrink-0 items-center justify-center rounded-full text-ink-400 transition hover:bg-base-700 hover:text-ink-100"
                    aria-label="Add this track to a playlist">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M4 6h11M4 12h11M4 18h7M17 12v8M13 16h8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        </div>

        {{-- A tall hit area around a thin track: thumbs are imprecise, but a
             chunky bar looks clumsy. --}}
        <div id="np-sheet-seek" class="group relative -mx-1 cursor-pointer px-1 py-3" role="slider" aria-label="Seek">
            <div class="h-1 rounded-full bg-base-600">
                <div id="np-sheet-played" class="h-full rounded-full bg-accent" style="width: 0"></div>
            </div>
        </div>

        <div class="mb-5 flex justify-between text-xs tabular-nums text-ink-500">
            <span id="np-sheet-current">0:00</span>
            <span id="np-sheet-duration">0:00</span>
        </div>

        <div class="flex items-center justify-between">
            <button type="button" id="np-sheet-shuffle"
                    class="flex size-11 items-center justify-center rounded-full text-ink-400 transition hover:text-ink-100"
                    aria-label="Shuffle" aria-pressed="false">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <button type="button" id="np-sheet-prev"
                    class="flex size-12 items-center justify-center rounded-full text-ink-200 transition hover:text-ink-100"
                    aria-label="Previous track">
                <svg class="size-7" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M6 6h2v12H6zm3.5 6l8.5 6V6z" />
                </svg>
            </button>

            <button type="button" id="np-sheet-toggle"
                    class="flex size-16 items-center justify-center rounded-full bg-accent text-white shadow-lg shadow-accent/25 transition hover:bg-accent-hot"
                    aria-label="Play">
                <svg class="size-8" viewBox="0 0 24 24" fill="currentColor">
                    <path id="np-sheet-toggle-icon" d="M8 5v14l11-7z" />
                </svg>
            </button>

            <button type="button" id="np-sheet-next"
                    class="flex size-12 items-center justify-center rounded-full text-ink-200 transition hover:text-ink-100"
                    aria-label="Next track">
                <svg class="size-7" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 6h2v12h-2zM6 6l8.5 6L6 18z" />
                </svg>
            </button>

            <button type="button" id="np-sheet-repeat"
                    class="relative flex size-11 items-center justify-center rounded-full text-ink-400 transition hover:text-ink-100"
                    aria-label="Repeat" data-mode="off">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 2l4 4-4 4M3 11V9a4 4 0 014-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 01-4 4H3" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                {{-- Repeat-one is a badge rather than a second icon: the
                     difference is one glyph, not a different control. --}}
                <span id="np-sheet-repeat-one"
                      class="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-accent text-[0.5rem] font-bold text-white"
                      hidden>1</span>
            </button>
        </div>
    </div>
</div>

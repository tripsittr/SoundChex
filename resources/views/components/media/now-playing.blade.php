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

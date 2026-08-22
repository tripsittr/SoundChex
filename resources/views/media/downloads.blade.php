<x-media.layout :counts="$counts" title="Downloads">

    <section class="mx-auto max-w-3xl px-4 pt-28 sm:px-8 sm:pt-32">

        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">On this device</h1>
        <p class="mt-1 text-sm text-ink-500">
            Stored in this browser. Add SoundChex to your home screen and the
            downloads come with it &mdash; installed apps are far less likely to
            have their storage reclaimed.
        </p>

        <div id="downloads-summary" class="mt-6 hidden rounded-lg border border-base-600 px-4 py-3 text-sm text-ink-300"></div>

        {{-- Shown only when there is something to remove. Destructive and
             irreversible, so it sits away from the per-row buttons and asks
             before it acts. --}}
        <div id="downloads-actions" class="mt-4 hidden justify-end">
            <button type="button"
                    id="downloads-remove-all"
                    class="rounded-md border border-base-500 px-3 py-1.5 text-xs text-ink-300 transition hover:border-red-500 hover:text-red-300">
                Remove all
            </button>
        </div>

        <div id="downloads-list" class="mt-6 space-y-2"></div>

        {{-- The confirmation. Built here rather than in script so it is styled
             with everything else, and hidden until asked for. --}}
        <div id="downloads-confirm"
             class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 p-4"
             role="dialog"
             aria-modal="true"
             aria-labelledby="downloads-confirm-title">
            <div class="w-full max-w-sm rounded-xl border border-base-600 bg-base-800 p-5 shadow-xl">
                <h2 id="downloads-confirm-title" class="text-base font-semibold text-ink-100">
                    Remove all downloads?
                </h2>

                <p id="downloads-confirm-detail" class="mt-2 text-sm text-ink-400"></p>

                <p class="mt-2 text-sm text-ink-500">
                    They stay in your library and can be downloaded again.
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button"
                            id="downloads-confirm-cancel"
                            class="rounded-md border border-base-500 px-3 py-1.5 text-sm text-ink-300 transition hover:border-base-400">
                        Cancel
                    </button>

                    <button type="button"
                            id="downloads-confirm-remove"
                            class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-red-500">
                        Remove all
                    </button>
                </div>
            </div>
        </div>

        <div id="downloads-empty" class="mt-12 hidden rounded-lg border border-dashed border-base-500 py-16 text-center">
            <p class="text-ink-300">Nothing downloaded yet.</p>
            <p class="mt-1 text-sm text-ink-500">
                Open anything in your library and choose &ldquo;Download for offline&rdquo;.
            </p>
        </div>

        <p id="downloads-unsupported" class="mt-12 hidden text-sm text-ink-500">
            This browser can&rsquo;t store downloads.
        </p>
    </section>

    @vite('resources/js/downloads-page.js')

</x-media.layout>

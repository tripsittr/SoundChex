<x-media.layout :counts="$counts" title="Downloads">

    <section class="mx-auto max-w-3xl px-4 pt-28 sm:px-8 sm:pt-32">

        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">On this device</h1>
        <p class="mt-1 text-sm text-ink-500">
            Stored in this browser. Add SoundChex to your home screen and the
            downloads come with it &mdash; installed apps are far less likely to
            have their storage reclaimed.
        </p>

        <div id="downloads-summary" class="mt-6 hidden rounded-lg border border-base-600 px-4 py-3 text-sm text-ink-300"></div>

        <div id="downloads-list" class="mt-6 space-y-2"></div>

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

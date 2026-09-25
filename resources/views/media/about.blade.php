@php
    use App\Support\AppRelease;

    $commit = AppRelease::commit();
    $modified = AppRelease::isModified();
@endphp

<x-media.layout :counts="$counts" title="About">

    <section class="mx-auto max-w-3xl px-4 pt-28 sm:px-8 sm:pt-32">

        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">About this build</h1>

        <dl class="mt-6 divide-y divide-base-700 rounded-lg border border-base-600">
            <div class="flex items-baseline justify-between gap-4 px-4 py-3">
                <dt class="text-sm text-ink-500">Version</dt>
                <dd class="text-sm font-medium text-ink-100">{{ AppRelease::display() }}</dd>
            </div>

            {{-- The commit is the point of this page. Two servers can report
                 the same version and be running different code, because builds
                 ship from main between releases — so the version alone cannot
                 identify the source that corresponds to what is running. --}}
            <div class="flex items-baseline justify-between gap-4 px-4 py-3">
                <dt class="text-sm text-ink-500">Built from</dt>
                <dd class="text-sm font-medium text-ink-100">
                    @if ($commit)
                        <code class="rounded bg-base-800 px-1.5 py-0.5 text-xs">{{ $commit }}</code>
                    @else
                        <span class="text-ink-500">Not recorded &mdash; an unstamped build</span>
                    @endif
                </dd>
            </div>

            @if ($modified)
                <div class="flex items-baseline justify-between gap-4 px-4 py-3">
                    <dt class="text-sm text-ink-500">Source</dt>
                    <dd class="text-sm font-medium text-amber-300">Modified</dd>
                </div>
            @endif
        </dl>

        <h2 class="mt-10 text-lg font-semibold">Source code</h2>

        <p class="mt-2 text-sm leading-relaxed text-ink-300">
            SoundChex is free software under the
            <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener"
               class="text-accent underline decoration-accent/40 underline-offset-2 hover:decoration-accent">AGPL-3.0-or-later</a>.
            Section&nbsp;13 says that anyone who uses this server over a network
            must be offered the source of the build they are using &mdash; not
            the project in general, but this one.
        </p>

        <a href="{{ AppRelease::sourceUrl() }}" target="_blank" rel="noopener"
           class="mt-4 inline-flex items-center gap-2 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition hover:bg-accent-hot">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.5 1.5M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7L12 19"
                      stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            @if ($commit)
                Source of this build
            @else
                Source code
            @endif
        </a>

        @if ($commit)
            <p class="mt-2 text-xs text-ink-500">
                Pinned to <code>{{ $commit }}</code>, so it is the code this
                server is actually running.
            </p>
        @else
            {{-- Said plainly rather than quietly linking main and hoping. A
                 build with no commit cannot honour §13 on its own, and the
                 person reading this is the one who needs to know. --}}
            <p class="mt-2 text-xs text-ink-500">
                This build was not stamped with a commit, so this link goes to
                the repository rather than to the exact code running here. Ask
                whoever runs this server for the commit it was built from.
            </p>
        @endif

        @if ($modified)
            {{-- The obligation an operator most often misses: running modified
                 code means owing *their* source, not ours. --}}
            <div class="mt-6 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3">
                <h3 class="text-sm font-semibold text-amber-200">This build has been modified</h3>
                <p class="mt-1 text-sm leading-relaxed text-amber-100/90">
                    It was built from a working tree that differed from the
                    published commit. If you run this for other people, the
                    AGPL asks you to offer them <em>your</em> source, not this
                    project's &mdash; set <code>SOUNDCHEX_REPO_URL</code> to
                    your own repository so this page points there.
                </p>
            </div>
        @endif

        <h2 class="mt-10 text-lg font-semibold">Your media</h2>

        <p class="mt-2 text-sm leading-relaxed text-ink-300">
            None of this covers your files. SoundChex is a library for media you
            already have; it neither acquires it nor helps you to.
        </p>

    </section>

</x-media.layout>

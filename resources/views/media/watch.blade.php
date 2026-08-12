<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $item->title }} — {{ config('app.name', 'SoundChex') }}</title>

    <link rel="icon" href="{{ asset('storage/favicon.ico') }}" sizes="any">

    {{-- Like the reader, this deliberately skips the media center layout: a
         player wants the whole viewport with no nav competing with the film. --}}
    @vite(['resources/css/media-center.css', 'resources/js/watch.js'])

    <style>
        /* Captions are drawn here rather than by the browser: native cue
           rendering can't be positioned or styled, and it sits underneath the
           controls. Every track is loaded in "hidden" mode instead. */
        .watch-captions {
            position: absolute;
            left: 50%;
            bottom: var(--caption-bottom, 12%);
            transform: translateX(-50%);
            z-index: 15;
            width: min(92%, 60rem);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25em;
            pointer-events: none;
            text-align: center;
            font-family: var(--caption-font, inherit);
            font-size: var(--caption-size, 3.2vh);
            line-height: 1.35;
            transition: bottom 0.2s ease;
        }

        .watch-caption-line {
            display: inline-block;
            max-width: 100%;
            padding: 0.1em 0.4em;
            border-radius: 0.15em;
            color: var(--caption-color, #fff);
            background: var(--caption-bg, rgba(0, 0, 0, 0.7));
            text-shadow: var(--caption-shadow, none);
            text-wrap: balance;
        }

        /* The controls occupy the lower strip, so captions lift clear of them
           while the chrome is visible. */
        #watch-chrome:not(.opacity-0) ~ .watch-captions,
        .watch-captions:has(~ #watch-chrome:not(.opacity-0)) {
            bottom: calc(var(--caption-bottom, 12%) + 4rem);
        }

        .watch-menu {
            position: absolute;
            bottom: calc(100% + 0.5rem);
            right: 0;
            z-index: 30;
            min-width: 13rem;
            max-height: 60vh;
            overflow-y: auto;
            border-radius: 0.625rem;
            padding: 0.375rem;
            background: rgb(18 18 22 / 0.97);
            box-shadow: 0 12px 40px rgb(0 0 0 / 0.55);
            backdrop-filter: blur(12px);
        }

        .watch-menu-heading {
            margin: 0;
            padding: 0.375rem 0.625rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(255 255 255 / 0.45);
        }

        .watch-menu-item {
            display: block;
            width: 100%;
            border: 0;
            background: transparent;
            color: rgb(255 255 255 / 0.85);
            text-align: left;
            cursor: pointer;
            padding: 0.5rem 0.625rem;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
        }

        .watch-menu-item:hover { background: rgb(255 255 255 / 0.1); color: #fff; }

        .watch-menu-item.is-active {
            color: var(--color-accent, #6ee7b7);
            font-weight: 600;
        }

        .watch-menu-item.is-active::before { content: '✓ '; }

        .watch-menu-empty {
            margin: 0;
            padding: 0.5rem 0.625rem;
            font-size: 0.75rem;
            color: rgb(255 255 255 / 0.45);
        }

        .watch-menu-divider {
            height: 1px;
            margin: 0.375rem 0.25rem;
            background: rgb(255 255 255 / 0.12);
        }

        .watch-dialog {
            position: absolute;
            inset: 0;
            z-index: 40;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgb(0 0 0 / 0.6);
            backdrop-filter: blur(3px);
        }


        .watch-dialog-panel {
            width: min(34rem, 100%);
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            border-radius: 0.875rem;
            background: rgb(18 18 22 / 0.98);
            box-shadow: 0 20px 60px rgb(0 0 0 / 0.6);
        }

        .watch-dialog-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1rem 0.5rem;
        }

        .watch-dialog-body { overflow-y: auto; padding: 0 0.5rem; }

        .watch-subtitle-result {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
            width: 100%;
            border: 0;
            background: transparent;
            color: rgb(255 255 255 / 0.9);
            text-align: left;
            cursor: pointer;
            padding: 0.625rem;
            border-radius: 0.5rem;
        }

        .watch-subtitle-result:hover { background: rgb(255 255 255 / 0.08); }
        .watch-subtitle-result:disabled { opacity: 0.5; cursor: default; }

        .watch-subtitle-name {
            font-size: 0.8125rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .watch-subtitle-meta { font-size: 0.6875rem; color: rgb(255 255 255 / 0.5); }

        .watch-style-panel {
            position: absolute;
            right: 1rem;
            bottom: 6rem;
            z-index: 40;
            width: min(20rem, calc(100vw - 2rem));
            border-radius: 0.875rem;
            background: rgb(18 18 22 / 0.98);
            box-shadow: 0 16px 50px rgb(0 0 0 / 0.6);
        }

        .watch-style-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            font-size: 0.8125rem;
            color: rgb(255 255 255 / 0.8);
        }

        .watch-select {
            border-radius: 0.375rem;
            border: 1px solid rgb(255 255 255 / 0.2);
            background: rgb(0 0 0 / 0.4);
            color: #fff;
            padding: 0.25rem 0.5rem;
            font-size: 0.8125rem;
        }

        .watch-color {
            width: 2.5rem;
            height: 1.75rem;
            border: 1px solid rgb(255 255 255 / 0.2);
            border-radius: 0.375rem;
            background: transparent;
            cursor: pointer;
        }

        /* Last on purpose. This block is emitted after Tailwind's stylesheet,
           so the `display` values set above beat .hidden at equal specificity
           — without this the search dialog opens on load and won't close. */
        .watch-dialog.hidden,
        .watch-menu.hidden,
        .watch-style-panel.hidden,
        .watch-captions.hidden {
            display: none;
        }
    </style>
</head>
<body class="bg-black">

<div id="watch"
     class="relative flex h-dvh w-full items-center justify-center bg-black"
     data-item-id="{{ $item->id }}"
     data-title="{{ $item->title }}"
     data-progress-url="{{ route('media.progress', $item) }}"
     data-resume-at="{{ $resumeAt ?? 0 }}"
     data-markers="{{ json_encode($markers) }}">

    <video id="watch-video"
           class="h-full w-full"
           src="{{ route('media.stream', $item) }}"
           preload="metadata"
           playsinline
           autoplay
           crossorigin="anonymous">
        {{-- Tracks are loaded but never shown by default: the browser's own
             rendering can't be styled, so cues are drawn by watch.js into the
             overlay below. Mode is forced to "hidden" there. --}}
        @foreach ($subtitles as $track)
            <track kind="{{ $track->sdh ? 'captions' : 'subtitles' }}"
                   src="{{ route('media.subtitle', ['item' => $item, 'subtitle' => $track]) }}"
                   srclang="{{ $track->language }}"
                   label="{{ $track->displayLabel() }}"
                   data-track-id="{{ $track->id }}"
                   data-forced="{{ $track->forced ? '1' : '0' }}"
                   @if ($track->is_default) default @endif>
        @endforeach
        Your browser can't play this video.
    </video>

    {{-- Cues are rendered here rather than by the browser so they can be
         positioned above the controls and styled to taste. --}}
    <div id="watch-captions" class="watch-captions" aria-live="polite"></div>

    {{-- Buffering indicator --}}
    <div id="watch-spinner" class="pointer-events-none absolute inset-0 hidden items-center justify-center">
        <div class="size-12 animate-spin rounded-full border-4 border-white/20 border-t-white"></div>
    </div>

    {{-- Skip Intro / Skip Credits. Appears only inside its window. --}}
    <button type="button" id="watch-skip"
            class="pointer-events-none absolute bottom-28 right-6 z-20 rounded-md border border-white/30
                   bg-black/70 px-5 py-2.5 text-sm font-semibold text-white opacity-0 backdrop-blur
                   transition hover:bg-black/90 sm:bottom-32 sm:right-10">
        Skip Intro
    </button>

    {{-- Controls: overlay the picture, fade out during playback. --}}
    <div id="watch-chrome"
         class="absolute inset-0 z-10 flex flex-col justify-between transition-opacity duration-300">

        <div class="flex items-center gap-3 bg-gradient-to-b from-black/80 to-transparent px-4 py-3 sm:px-6">
            <a href="{{ route('media.show', $item) }}"
               class="rounded-md p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
               aria-label="Back to details">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </a>

            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-white sm:text-base">{{ $item->title }}</p>
                @if ($item->subtitle())
                    <p class="truncate text-xs text-white/60">{{ $item->subtitle() }}</p>
                @endif
            </div>
        </div>

        <div class="bg-gradient-to-t from-black/90 to-transparent px-4 pb-4 pt-10 sm:px-6 sm:pb-6">

            {{-- Seek bar. The buffered range sits behind the played range so
                 you can see how far ahead the file has loaded. --}}
            <div id="watch-seek" class="group relative mb-3 h-6 cursor-pointer" role="slider" aria-label="Seek">
                <div class="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 overflow-hidden rounded-full bg-white/25">
                    <div id="watch-buffered" class="absolute inset-y-0 left-0 bg-white/30" style="width: 0"></div>
                    <div id="watch-played" class="absolute inset-y-0 left-0 bg-accent" style="width: 0"></div>
                </div>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                <button type="button" id="watch-toggle"
                        class="rounded-full p-2 text-white transition hover:bg-white/15"
                        aria-label="Play">
                    <svg class="size-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path id="watch-toggle-icon" d="M8 5v14l11-7z" />
                    </svg>
                </button>

                <button type="button" id="watch-back10"
                        class="rounded-md p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                        aria-label="Back 10 seconds">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 17l-5-5 5-5M18 17l-5-5 5-5" />
                    </svg>
                </button>

                <button type="button" id="watch-forward10"
                        class="rounded-md p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                        aria-label="Forward 10 seconds">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 17l5-5-5-5M6 17l5-5-5-5" />
                    </svg>
                </button>

                <span class="ml-1 text-xs tabular-nums text-white/70 sm:text-sm">
                    <span id="watch-current">0:00</span> / <span id="watch-duration">0:00</span>
                </span>

                <div class="ml-auto flex items-center gap-2">
                    <button type="button" id="watch-mute"
                            class="rounded-md p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                            aria-label="Mute">
                        <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M11 5L6 9H3v6h3l5 4V5z" />
                        </svg>
                    </button>

                    <input type="range" id="watch-volume" min="0" max="100" value="100"
                           class="hidden h-1 w-24 cursor-pointer appearance-none rounded-full bg-white/25 accent-white sm:block"
                           aria-label="Volume">

                    {{-- Captions. Present even with no tracks, so the button
                         can offer to search for some. --}}
                    <div class="relative">
                        <button type="button" id="watch-captions-toggle"
                                class="rounded-md p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                                aria-label="Subtitles and captions"
                                aria-haspopup="true"
                                aria-expanded="false">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2" />
                                <path stroke-linecap="round" d="M7 14.5h3.5M13.5 14.5H17" />
                            </svg>
                            {{-- A dot marks captions being on, readable at a
                                 glance without opening the menu. --}}
                            <span id="watch-captions-dot"
                                  class="absolute right-1 top-1 hidden size-1.5 rounded-full bg-accent"></span>
                        </button>

                        <div id="watch-captions-menu"
                             class="watch-menu hidden"
                             role="menu"
                             data-subtitles-url="{{ route('media.subtitles.index', $item) }}"
                             data-search-url="{{ route('media.subtitles.search', $item) }}">

                            <p class="watch-menu-heading">Subtitles</p>

                            <button type="button" class="watch-menu-item" data-track-id="off" role="menuitemradio">
                                Off
                            </button>

                            @foreach ($subtitles as $track)
                                <button type="button" class="watch-menu-item"
                                        data-track-id="{{ $track->id }}"
                                        role="menuitemradio">
                                    {{ $track->displayLabel() }}
                                </button>
                            @endforeach

                            @if ($subtitles->isEmpty())
                                <p class="watch-menu-empty">No subtitles for this title.</p>
                            @endif

                            <div class="watch-menu-divider"></div>

                            <button type="button" id="watch-captions-search" class="watch-menu-item">
                                Search online&hellip;
                            </button>

                            <button type="button" id="watch-captions-style" class="watch-menu-item">
                                Appearance&hellip;
                            </button>
                        </div>
                    </div>

                    <button type="button" id="watch-fullscreen"
                            class="rounded-md p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                            aria-label="Fullscreen">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Online subtitle search --}}
    <div id="watch-subtitle-search" class="watch-dialog hidden">
        <div class="watch-dialog-panel">
            <header class="watch-dialog-header">
                <h2 class="text-sm font-semibold text-white">Find subtitles</h2>
                <button type="button" id="watch-subtitle-close"
                        class="rounded-md p-1.5 text-white/70 transition hover:bg-white/10 hover:text-white"
                        aria-label="Close">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="flex items-center gap-2 px-4 pb-2">
                <label for="watch-subtitle-language" class="text-xs text-white/60">Language</label>
                <select id="watch-subtitle-language" class="watch-select">
                    @foreach ([
                        'en' => 'English', 'es' => 'Spanish', 'fr' => 'French',
                        'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese',
                        'nl' => 'Dutch', 'pl' => 'Polish', 'ru' => 'Russian',
                        'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese',
                        'ar' => 'Arabic', 'hi' => 'Hindi', 'tr' => 'Turkish',
                        'sv' => 'Swedish', 'da' => 'Danish', 'fi' => 'Finnish',
                        'no' => 'Norwegian',
                    ] as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>

                <span id="watch-subtitle-status" class="ml-auto text-xs text-white/50"></span>
            </div>

            <div id="watch-subtitle-results" class="watch-dialog-body"></div>

            <p class="px-4 pb-3 pt-1 text-[11px] leading-relaxed text-white/40">
                Results marked &ldquo;matches your file&rdquo; were identified by file hash, so their timing lines up with this exact copy.
            </p>
        </div>
    </div>

    {{-- Caption appearance --}}
    <div id="watch-caption-style" class="watch-style-panel hidden">
        <header class="watch-dialog-header">
            <h2 class="text-sm font-semibold text-white">Caption appearance</h2>
            <button type="button" id="watch-caption-style-close"
                    class="rounded-md p-1.5 text-white/70 transition hover:bg-white/10 hover:text-white"
                    aria-label="Close">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        <div class="space-y-3 p-4">
            <label class="watch-style-row">
                <span>Size</span>
                <select id="watch-caption-size" class="watch-select">
                    <option value="small">Small</option>
                    <option value="medium">Medium</option>
                    <option value="large">Large</option>
                    <option value="huge">Extra large</option>
                </select>
            </label>

            <label class="watch-style-row">
                <span>Backdrop</span>
                <select id="watch-caption-bg" class="watch-select">
                    <option value="dim">Dimmed</option>
                    <option value="solid">Solid</option>
                    <option value="none">None (outlined)</option>
                </select>
            </label>

            <label class="watch-style-row">
                <span>Typeface</span>
                <select id="watch-caption-font" class="watch-select">
                    <option value="sans">Sans</option>
                    <option value="serif">Serif</option>
                    <option value="mono">Monospace</option>
                </select>
            </label>

            <label class="watch-style-row">
                <span>Colour</span>
                <input type="color" id="watch-caption-color" class="watch-color">
            </label>

            <label class="watch-style-row">
                <span>Height</span>
                <input type="range" id="watch-caption-position" min="4" max="40" step="1"
                       class="h-1 w-32 cursor-pointer appearance-none rounded-full bg-white/25 accent-white">
            </label>
        </div>
    </div>
</div>

</body>
</html>

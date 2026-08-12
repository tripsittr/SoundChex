@php
    $author = $item->bookMetadata?->author;
    $format = strtolower(pathinfo((string) $item->file_path, PATHINFO_EXTENSION));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $item->title }} — {{ config('app.name', 'SoundChex') }}</title>

    <link rel="icon" href="{{ asset('storage/favicon.ico') }}" sizes="any">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">

    {{-- The reader deliberately does NOT use the media center layout: an
         e-reader wants the whole viewport, with no nav bar competing with the
         page. Everything here is scoped to reading. --}}
    @vite(['resources/css/media-center.css', 'resources/js/reader.js'])

    <style>
        /* Theme tokens are set from JS so the page, the chrome, and the
           surrounding frame all shift together when the theme changes. */
        #reader-root {
            --reader-page: #12121a;
            --reader-ink: #d8d8dd;
            --reader-muted: #8a8a96;
            --reader-chrome: #1b1b24;
            --reader-surround: #08080b;
        }

        html, body { height: 100%; overscroll-behavior: none; }

        body {
            margin: 0;
            background: var(--reader-surround, #08080b);
            transition: background-color 0.3s ease;
        }

        #reader-root { background: var(--reader-surround); color: var(--reader-ink); }

        /* The page itself: a capped measure centred in the viewport, which is
           what makes long-form text readable on a wide screen. */
        #reader-page {
            background: var(--reader-page);
            color: var(--reader-ink);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .reader-bar {
            background: color-mix(in srgb, var(--reader-chrome) 92%, transparent);
            color: var(--reader-ink);
            backdrop-filter: blur(8px);
        }

        .reader-btn {
            color: var(--reader-muted);
            transition: color 0.15s ease, background-color 0.15s ease;
        }

        .reader-btn:hover {
            color: var(--reader-ink);
            background: color-mix(in srgb, var(--reader-ink) 10%, transparent);
        }

        /* ---------------------------------------------------------- chrome */

        /* Hidden by default and slid out of the way. An e-reader should be
           almost entirely page; controls appear on a tap and leave again. */
        /* Deliberately NOT a full-screen layer. `inset: 0` over the page made
           an invisible sheet that swallowed every tap meant for the text —
           the bars pin to the top and bottom edges instead, so the middle of
           the page is never covered by anything. */
        .reader-chrome {
            position: absolute;
            inset: 0;
            z-index: 30;
            pointer-events: none;
            transition: opacity 0.22s ease;
        }

        .reader-chrome.is-hidden { opacity: 0; }

        .reader-header,
        .reader-footer {
            position: absolute;
            left: 0;
            right: 0;
            /* Only the bars themselves take input; the gap between them
               belongs to the page. */
            pointer-events: auto;
        }

        .reader-header { top: 0; }
        .reader-footer { bottom: 0; }

        .reader-chrome.is-hidden .reader-header,
        .reader-chrome.is-hidden .reader-footer { pointer-events: none; }

        .reader-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 0.75rem;
            /* Notch and home-indicator safe areas, so nothing sits under the
               status bar on a phone. */
            padding-top: max(0.625rem, env(safe-area-inset-top));
        }

        .reader-footer {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            padding-bottom: max(0.5rem, env(safe-area-inset-bottom));
        }

        @media (min-width: 640px) {
            .reader-header { padding-left: 1.25rem; padding-right: 1.25rem; }
            .reader-footer { padding-left: 1.5rem; padding-right: 1.5rem; }
        }

        .reader-badge {
            position: absolute;
            top: -0.125rem;
            right: -0.125rem;
            min-width: 1rem;
            padding: 0 0.25rem;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 600;
            line-height: 1rem;
            background: var(--reader-ink);
            color: var(--reader-page);
        }

        .reader-badge:empty { display: none; }

        .reader-page-input {
            width: 3.25rem;
            border-radius: 0.375rem;
            border: 1px solid color-mix(in srgb, var(--reader-ink) 22%, transparent);
            background: transparent;
            color: var(--reader-ink);
            padding: 0.2rem 0.35rem;
            text-align: center;
            font-size: 0.75rem;
            font-variant-numeric: tabular-nums;
        }

        /* ----------------------------------------------------------- sheet */

        /* Bottom sheet on a phone, floating panel on a wide screen — reachable
           by thumb where that matters, out of the way where it doesn't. */
        .reader-sheet {
            position: fixed;
            z-index: 60;
            display: flex;
            flex-direction: column;
            background: color-mix(in srgb, var(--reader-chrome) 98%, transparent);
            color: var(--reader-ink);
            box-shadow: 0 -8px 40px rgb(0 0 0 / 0.4);
            backdrop-filter: blur(12px);
            left: 0;
            right: 0;
            bottom: 0;
            max-height: 85vh;
            border-radius: 1rem 1rem 0 0;
            padding-bottom: env(safe-area-inset-bottom);
        }

        @media (min-width: 640px) {
            .reader-sheet {
                left: auto;
                right: 1rem;
                bottom: auto;
                top: 3.75rem;
                width: 20rem;
                max-height: min(32rem, calc(100vh - 5rem));
                border-radius: 0.875rem;
                box-shadow: 0 16px 50px rgb(0 0 0 / 0.45);
            }
        }

        .reader-sheet-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1rem 0.5rem;
            flex: none;
        }

        .reader-sheet-body {
            overflow-y: auto;
            padding: 0 1rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 1.125rem;
        }

        .reader-group { display: flex; flex-direction: column; gap: 0.5rem; }

        .reader-group-label {
            margin: 0;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--reader-muted);
        }

        .reader-theme-swatch {
            border: 1px solid color-mix(in srgb, var(--reader-ink) 15%, transparent);
            border-radius: 0.5rem;
            padding: 0.75rem 0;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
            cursor: pointer;
        }

        .reader-theme-swatch.ring-2 {
            outline: 2px solid var(--reader-ink);
            outline-offset: 1px;
        }

        /* Segmented control — one visible choice out of a small set, which is
           clearer than three separate buttons that look independent. */
        .reader-segment {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: 1fr;
            gap: 2px;
            padding: 2px;
            border-radius: 0.5rem;
            background: color-mix(in srgb, var(--reader-ink) 10%, transparent);
        }

        .reader-segment-btn {
            border: 0;
            border-radius: 0.375rem;
            background: transparent;
            color: var(--reader-muted);
            padding: 0.4rem 0.25rem;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .reader-segment-btn.ring-2 {
            background: var(--reader-page);
            color: var(--reader-ink);
            font-weight: 600;
        }

        .reader-stepper {
            display: flex;
            align-items: center;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in srgb, var(--reader-ink) 18%, transparent);
            overflow: hidden;
        }

        .reader-stepper-btn {
            flex: none;
            width: 2.75rem;
            border: 0;
            background: transparent;
            color: var(--reader-ink);
            padding: 0.5rem 0;
            font-size: 0.95rem;
            cursor: pointer;
        }

        .reader-stepper-btn:hover { background: color-mix(in srgb, var(--reader-ink) 10%, transparent); }

        .reader-stepper-value {
            flex: 1;
            border: 0;
            background: transparent;
            color: var(--reader-muted);
            font-size: 0.8125rem;
            font-variant-numeric: tabular-nums;
            text-align: center;
            cursor: pointer;
        }

        .reader-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            font-size: 0.875rem;
            cursor: pointer;
        }

        .reader-row-hint {
            display: block;
            margin-top: 0.125rem;
            font-size: 0.6875rem;
            line-height: 1.4;
            color: var(--reader-muted);
        }

        .reader-switch {
            flex: none;
            position: relative;
            width: 2.5rem;
            height: 1.5rem;
            border: 0;
            border-radius: 9999px;
            background: color-mix(in srgb, var(--reader-ink) 22%, transparent);
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .reader-switch[aria-checked="true"] { background: var(--reader-ink); }

        .reader-switch-knob {
            position: absolute;
            top: 0.1875rem;
            left: 0.1875rem;
            width: 1.125rem;
            height: 1.125rem;
            border-radius: 9999px;
            background: var(--reader-page);
            transition: transform 0.15s ease;
        }

        .reader-switch[aria-checked="true"] .reader-switch-knob { transform: translateX(1rem); }

        .reader-checkbox {
            width: 1rem;
            height: 1rem;
            accent-color: var(--reader-ink);
            cursor: pointer;
        }

        .reader-slider-row { display: flex; align-items: center; gap: 0.75rem; }

        .reader-range {
            width: 100%;
            height: 0.25rem;
            appearance: none;
            border-radius: 9999px;
            background: color-mix(in srgb, var(--reader-ink) 20%, transparent);
            accent-color: var(--reader-ink);
            cursor: pointer;
        }

        .reader-sheet-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 0;
            border-top: 1px solid color-mix(in srgb, var(--reader-ink) 12%, transparent);
            color: var(--reader-muted);
            font-size: 0.8125rem;
            text-decoration: none;
        }

        .reader-sheet-link:hover { color: var(--reader-ink); }

        /* -------------------------------------------------------- contents */

        /* Wider than the settings sheet: a gallery and search results need
           room that a list of toggles doesn't. */
        @media (min-width: 640px) {
            .reader-sheet-wide { width: 24rem; }
        }

        .reader-tabs {
            display: flex;
            gap: 2px;
            padding: 2px;
            border-radius: 0.5rem;
            background: color-mix(in srgb, var(--reader-ink) 10%, transparent);
        }

        .reader-tab {
            border: 0;
            border-radius: 0.375rem;
            background: transparent;
            color: var(--reader-muted);
            padding: 0.3rem 0.7rem;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .reader-tab.is-active {
            background: var(--reader-page);
            color: var(--reader-ink);
            font-weight: 600;
        }

        .reader-empty {
            margin: 0;
            padding: 1rem 0;
            font-size: 0.8125rem;
            line-height: 1.6;
            color: var(--reader-muted);
        }

        .reader-toc { list-style: none; margin: 0; padding: 0; }

        .reader-toc-item {
            display: flex;
            align-items: baseline;
            gap: 0.75rem;
            width: 100%;
            border: 0;
            background: transparent;
            color: var(--reader-ink);
            text-align: left;
            cursor: pointer;
            padding: 0.5rem 0.5rem 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
            line-height: 1.4;
        }

        .reader-toc-item:hover { background: color-mix(in srgb, var(--reader-ink) 9%, transparent); }

        .reader-toc-title { flex: 1; min-width: 0; }

        .reader-toc-page {
            flex: none;
            font-size: 0.6875rem;
            font-variant-numeric: tabular-nums;
            color: var(--reader-muted);
        }

        .reader-image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr));
            gap: 0.5rem;
        }

        .reader-image-tile {
            position: relative;
            display: block;
            border: 0;
            padding: 0;
            border-radius: 0.5rem;
            overflow: hidden;
            background: color-mix(in srgb, var(--reader-ink) 8%, transparent);
            cursor: pointer;
            aspect-ratio: 3 / 4;
        }

        .reader-image-tile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .reader-image-tile:hover img { filter: brightness(1.08); }

        .reader-image-page {
            position: absolute;
            right: 0.25rem;
            bottom: 0.25rem;
            padding: 0.05rem 0.3rem;
            border-radius: 0.25rem;
            background: rgb(0 0 0 / 0.65);
            color: #fff;
            font-size: 0.625rem;
            font-variant-numeric: tabular-nums;
        }

        .reader-search-input {
            width: 100%;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in srgb, var(--reader-ink) 20%, transparent);
            background: color-mix(in srgb, var(--reader-page) 55%, transparent);
            color: var(--reader-ink);
            padding: 0.5rem 0.7rem;
            font: inherit;
            font-size: 0.875rem;
        }

        .reader-search-status {
            margin: 0.5rem 0 0.25rem;
            font-size: 0.6875rem;
            color: var(--reader-muted);
        }

        .reader-search-results { display: flex; flex-direction: column; gap: 2px; }

        .reader-search-result {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            width: 100%;
            border: 0;
            background: transparent;
            color: var(--reader-ink);
            text-align: left;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            line-height: 1.5;
        }

        .reader-search-result:hover { background: color-mix(in srgb, var(--reader-ink) 9%, transparent); }

        .reader-search-page {
            flex: none;
            font-variant-numeric: tabular-nums;
            color: var(--reader-muted);
        }

        .reader-search-snippet { flex: 1; min-width: 0; }

        .reader-search-mark {
            background: color-mix(in srgb, #f5c518 55%, transparent);
            color: inherit;
            border-radius: 2px;
            padding: 0 1px;
        }

        /* Recognised text can be wrong, so a hit from a scan says so rather
           than presenting OCR output as if it were the publisher's text. */
        .reader-search-badge {
            flex: none;
            align-self: center;
            padding: 0.05rem 0.3rem;
            border-radius: 0.25rem;
            border: 1px solid color-mix(in srgb, var(--reader-muted) 45%, transparent);
            font-size: 0.5625rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--reader-muted);
        }

        /* Edge tap zones for paging — invisible, but the whole point of
           one-handed reading. */
        .reader-edge { cursor: pointer; }
        @media (hover: hover) {
            .reader-edge:hover { background: color-mix(in srgb, var(--reader-ink) 4%, transparent); }
        }

        /* ------------------------------------------------------------ PDF */

        /* The scroller is what pans; the stage is the page-sized box the
           canvas, highlights and text layer all share so they stay aligned. */
        .reader-pdf-scroller {
            height: 100%;
            width: 100%;
            overflow: auto;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 16px;
            /* Momentum scrolling on iOS, and no browser-native pan gesture
               fighting the drag-to-pan below. */
            -webkit-overflow-scrolling: touch;
        }

        .reader-pdf-scroller.is-panning { cursor: grabbing; user-select: none; }

        .reader-pdf-stage {
            position: relative;
            flex: none;
            box-shadow: 0 10px 40px rgb(0 0 0 / 0.35);
        }

        .reader-pdf-canvas { display: block; }

        /* pdf.js positions transparent spans over the glyphs. They must be
           invisible but selectable — the whole point is that the browser has
           real text to select. */
        .reader-pdf-textlayer {
            position: absolute;
            inset: 0;
            overflow: hidden;
            opacity: 1;
            line-height: 1;
            text-size-adjust: none;
            forced-color-adjust: none;
            transform-origin: 0 0;
            z-index: 2;
        }

        .reader-pdf-textlayer span,
        .reader-pdf-textlayer br {
            color: transparent;
            position: absolute;
            white-space: pre;
            cursor: text;
            transform-origin: 0% 0%;
        }

        .reader-pdf-textlayer ::selection {
            background: color-mix(in srgb, #5aa9e6 45%, transparent);
        }

        /* Highlights sit under the text layer so selection keeps working. */
        .reader-pdf-highlights {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
        }

        /* isolate + a single blend on the layer, not on each mark. Blending
           every highlight individually meant two overlapping rectangles
           multiplied against each other and went progressively darker — and
           a selection spanning several lines produces overlapping rects even
           for one highlight. Compositing the layer once fixes both. */
        .reader-pdf-highlights {
            isolation: isolate;
            mix-blend-mode: multiply;
        }

        .reader-highlight {
            position: absolute;
            border: 0;
            padding: 0;
            border-radius: 2px;
            /* Opacity baked into the layer below rather than set per mark, so
               overlaps can't compound. */
            opacity: 0.38;
            pointer-events: auto;
            cursor: pointer;
            transition: filter 0.15s ease;
        }

        /* Brightening rather than raising opacity keeps hover from stacking
           with a neighbour underneath it. */
        .reader-highlight:hover { filter: brightness(0.88); }

        /* A note gets an underline so it's distinguishable from a plain
           highlight at a glance. */
        .reader-highlight[data-has-note] {
            box-shadow: inset 0 -2px 0 0 rgb(0 0 0 / 0.45);
        }

        /* Night theme darkens the page, so multiply would black the highlight
           out; screen keeps it visible. */
        /* Night page: multiply would black the highlight out, so the layer
           screens instead. Set on the layer, matching the rule above. */
        #reader-root:has(#reader-page[style*="#12121a"]) .reader-pdf-highlights {
            mix-blend-mode: screen;
        }

        /* OCR word spans are positioned and sized from recognised boxes rather
           than by pdf.js, so they need their own scaling rules. */
        .reader-pdf-textlayer span[data-ocr] {
            white-space: nowrap;
            overflow: hidden;
        }

        .reader-ocr-status {
            position: absolute;
            left: 50%;
            bottom: 1.25rem;
            transform: translateX(-50%);
            z-index: 25;
            margin: 0;
            max-width: min(30rem, calc(100vw - 2rem));
            border-radius: 0.5rem;
            padding: 0.5rem 0.875rem;
            font-size: 0.75rem;
            line-height: 1.5;
            text-align: center;
            background: color-mix(in srgb, var(--reader-chrome) 94%, transparent);
            color: var(--reader-muted);
            box-shadow: 0 6px 24px rgb(0 0 0 / 0.35);
            backdrop-filter: blur(8px);
        }

        /* ------------------------------------------------------ text mode */

        /* A continuous column rather than one PDF page at a time. A page of a
           trade paperback holds barely a hundred words, which left a stub of
           text stranded in a tall viewport and a page turn every few seconds. */
        .reader-flow {
            /* ~65 characters is the measure long-form text reads best at. */
            max-width: 34rem;
            margin: 0 auto;
            padding: 4.5rem 1.5rem 6rem;
        }

        @media (min-width: 640px) {
            .reader-flow { padding: 5rem 2rem 7rem; }
        }

        /* Zoom scales the type rather than the canvas, which text mode hides.
           The measure grows with it so the line length stays comfortable
           instead of the words simply getting bigger in a fixed column. */
        .reader-flow p {
            margin: 0 0 1.15em;
            font-size: calc(1.0625rem * var(--flow-scale, 1));
            line-height: 1.75;
            hyphens: auto;
        }

        .reader-flow { max-width: calc(34rem * var(--flow-scale, 1)); }

        /* Justification needs hyphenation to avoid rivers of whitespace, and
           narrow screens can't hyphenate well enough for it. */
        @media (min-width: 640px) {
            .reader-flow p { text-align: justify; }
        }

        /* Page boundaries exist for the position indicator, not the reader —
           they must not interrupt the prose. */
        .reader-flow-anchor { height: 0; overflow: hidden; }

        .reader-flow-figure {
            margin: 1.75rem 0;
            text-align: center;
        }

        .reader-flow-figure img {
            max-width: 100%;
            height: auto;
            border-radius: 0.375rem;
            /* Plates are often scanned on white; a light border keeps them
               from bleeding into a pale page. */
            box-shadow: 0 2px 12px rgb(0 0 0 / 0.18);
        }

        .reader-textmode-notice { color: var(--reader-muted); font-size: 0.875rem; }

        #reader-textmode-toggle.is-active {
            background: color-mix(in srgb, var(--reader-ink) 15%, transparent);
            color: var(--reader-ink);
        }

        /* ----------------------------------------------------- annotations */

        .reader-popover {
            position: fixed;
            z-index: 60;
            display: flex;
            align-items: center;
            gap: 0.375rem;
            border-radius: 0.625rem;
            padding: 0.375rem 0.5rem;
            background: color-mix(in srgb, var(--reader-chrome) 96%, transparent);
            box-shadow: 0 8px 30px rgb(0 0 0 / 0.4);
            backdrop-filter: blur(8px);
        }

        .reader-swatch {
            width: 1.375rem;
            height: 1.375rem;
            border: 0;
            border-radius: 9999px;
            cursor: pointer;
            transition: transform 0.12s ease;
        }

        .reader-swatch:hover { transform: scale(1.15); }

        .reader-popover-divider {
            width: 1px;
            height: 1.25rem;
            background: color-mix(in srgb, var(--reader-ink) 20%, transparent);
        }

        .reader-popover-btn {
            border: 0;
            background: transparent;
            color: var(--reader-ink);
            cursor: pointer;
            border-radius: 0.375rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.8125rem;
        }

        .reader-popover-btn:hover { background: color-mix(in srgb, var(--reader-ink) 12%, transparent); }

        .reader-popover-danger { color: #e26d6d; display: flex; align-items: center; }
        .reader-popover-danger:hover { background: color-mix(in srgb, #e26d6d 18%, transparent); }
        .reader-popover-danger.hidden { display: none; }

        .reader-note-editor {
            position: fixed;
            left: 50%;
            bottom: 1.5rem;
            transform: translateX(-50%);
            z-index: 60;
            width: min(28rem, calc(100vw - 2rem));
            border-radius: 0.875rem;
            padding: 1rem;
            background: color-mix(in srgb, var(--reader-chrome) 97%, transparent);
            color: var(--reader-ink);
            box-shadow: 0 12px 40px rgb(0 0 0 / 0.45);
            backdrop-filter: blur(10px);
        }

        .reader-note-quote {
            margin: 0 0 0.75rem;
            border-left: 3px solid color-mix(in srgb, var(--reader-muted) 55%, transparent);
            padding-left: 0.625rem;
            font-size: 0.8125rem;
            line-height: 1.5;
            color: var(--reader-muted);
            max-height: 4.5rem;
            overflow: hidden;
        }

        .reader-note-input {
            width: 100%;
            resize: vertical;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in srgb, var(--reader-ink) 20%, transparent);
            background: color-mix(in srgb, var(--reader-page) 60%, transparent);
            color: var(--reader-ink);
            padding: 0.5rem 0.625rem;
            font: inherit;
            font-size: 0.875rem;
        }

        .reader-note-input:focus {
            outline: 2px solid color-mix(in srgb, var(--reader-ink) 35%, transparent);
            outline-offset: 1px;
        }

        .reader-note-primary,
        .reader-note-secondary,
        .reader-note-danger {
            border: 0;
            border-radius: 0.5rem;
            padding: 0.375rem 0.875rem;
            font-size: 0.8125rem;
            cursor: pointer;
        }

        .reader-note-primary { background: var(--reader-ink); color: var(--reader-page); font-weight: 600; }
        .reader-note-secondary { background: transparent; color: var(--reader-muted); }
        .reader-note-danger { background: transparent; color: #e26d6d; }

        .reader-notes {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 55;
            width: min(22rem, 100vw);
            display: flex;
            flex-direction: column;
            background: color-mix(in srgb, var(--reader-chrome) 98%, transparent);
            color: var(--reader-ink);
            box-shadow: -8px 0 40px rgb(0 0 0 / 0.4);
            backdrop-filter: blur(10px);
        }

        .reader-notes-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1rem;
            border-bottom: 1px solid color-mix(in srgb, var(--reader-ink) 12%, transparent);
        }

        .reader-notes-empty {
            padding: 1.5rem 1rem;
            font-size: 0.8125rem;
            line-height: 1.6;
            color: var(--reader-muted);
        }

        .reader-notes-list {
            list-style: none;
            margin: 0;
            padding: 0.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .reader-note-entry {
            display: flex;
            align-items: flex-start;
            gap: 0.25rem;
            border-radius: 0.5rem;
        }

        .reader-note-entry:hover { background: color-mix(in srgb, var(--reader-ink) 8%, transparent); }

        .reader-note-jump {
            flex: 1;
            display: flex;
            gap: 0.625rem;
            align-items: flex-start;
            border: 0;
            background: transparent;
            color: inherit;
            text-align: left;
            cursor: pointer;
            padding: 0.625rem;
            border-radius: 0.5rem;
        }

        .reader-note-swatch {
            margin-top: 0.25rem;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            flex: none;
        }

        .reader-note-body { display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; }

        .reader-note-excerpt {
            font-size: 0.8125rem;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .reader-note-text {
            font-size: 0.8125rem;
            line-height: 1.5;
            color: var(--reader-muted);
            font-style: italic;
        }

        .reader-note-page { font-size: 0.6875rem; color: var(--reader-muted); }

        .reader-note-delete {
            border: 0;
            background: transparent;
            color: var(--reader-muted);
            cursor: pointer;
            font-size: 1.125rem;
            line-height: 1;
            padding: 0.625rem 0.5rem;
            border-radius: 0.375rem;
        }

        .reader-note-delete:hover { color: #e26d6d; }

        /* Last on purpose. This block is emitted after Tailwind's stylesheet,
           so the `display` values set above beat .hidden at equal specificity
           — without this the popover and sidebar could never be dismissed. */
        .offline-badge.hidden,
        .reader-popover.hidden,
        .reader-notes.hidden,
        .reader-note-editor.hidden,
        .reader-sheet.hidden,
        .reader-ocr-status.hidden {
            display: none;
        }
    </style>
</head>
<body>

{{-- `relative` matters: the chrome is absolutely positioned and needs this as
     its containing block, or it anchors to the viewport instead. --}}
<div id="reader-root" class="relative flex h-dvh flex-col">

    {{-- Chrome starts hidden and stays out of the way. Tapping the middle of
         the page brings it back; it fades again on its own. An e-reader is
         mostly page, so nothing overlays the text by default. --}}
    {{-- Visible on arrival, then fades once reading starts. Starting hidden
         left no way in: the only reveal was a tap on the middle of the page,
         which is invisible and which PDFs didn't even bind. --}}
    <div id="reader-chrome" class="reader-chrome">
        <header class="reader-bar reader-header">
            <a href="{{ route('media.show', $item) }}"
               class="reader-btn shrink-0 rounded-md p-2"
               aria-label="Back to details">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </a>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ $item->title }}</p>
                @if ($author)
                    <p class="truncate text-xs" style="color: var(--reader-muted)">{{ $author }}</p>
                @endif
            </div>

            @if ($format === 'pdf')
                <button type="button" id="reader-contents-toggle"
                        class="reader-btn shrink-0 rounded-md p-2"
                        aria-label="Contents, illustrations and search"
                        aria-expanded="false">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M4 6h10M4 12h16M4 18h13M18 4l2 2-2 2" />
                    </svg>
                </button>
            @endif

            @if ($supportsAnnotations ?? false)
                <button type="button" id="reader-notes-toggle"
                        class="reader-btn relative shrink-0 rounded-md p-2"
                        aria-label="Highlights and notes">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M5 4h11a2 2 0 012 2v14l-7-4-7 4V6a2 2 0 012-2z" />
                    </svg>
                    <span id="reader-notes-count" class="reader-badge"></span>
                </button>
            @endif

            {{-- One entry point for every reading control. Previously these
                 were split across a header button and a permanently-visible
                 second toolbar, which left two rows of chrome over the page. --}}
            <button type="button" id="reader-settings-toggle"
                    class="reader-btn shrink-0 rounded-md p-2"
                    aria-label="Reading settings"
                    aria-expanded="false">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
            </button>
        </header>

        {{-- Page position, always at the foot where it belongs, out of the
             way of the text. Doubles as the seek control for PDFs. --}}
        <footer class="reader-bar reader-footer">
            @if ($format === 'pdf')
                <button type="button" id="reader-prev-btn" class="reader-btn rounded-md p-1.5" aria-label="Previous page">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                <label for="reader-page-input" class="sr-only">Page</label>
                <input type="number" id="reader-page-input" min="1" value="1" class="reader-page-input">
                <span class="text-xs tabular-nums" style="color: var(--reader-muted)">
                    / <span id="reader-page-total">?</span>
                </span>

                <button type="button" id="reader-next-btn" class="reader-btn rounded-md p-1.5" aria-label="Next page">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            @endif

            <span id="reader-offline-badge" class="offline-badge ml-auto hidden">
                Offline copy
            </span>

            <span id="reader-percent" class="ml-2 text-xs tabular-nums" style="color: var(--reader-muted)">
                {{ $progress?->percent ? $progress->percent . '%' : '' }}
            </span>
        </footer>
    </div>

    {{-- Contents: chapters, illustrations, and search. All three answer the
         same question — take me somewhere in this book — so they share one
         panel rather than three controls competing for header space. --}}
    @if ($format === 'pdf')
        <div id="reader-contents" class="reader-sheet reader-sheet-wide hidden" role="dialog" aria-label="Contents">
            <header class="reader-sheet-header">
                <div class="reader-tabs" role="tablist">
                    <button type="button" data-contents-tab="chapters" class="reader-tab is-active" role="tab">Contents</button>
                    <button type="button" data-contents-tab="images" class="reader-tab" role="tab">Images</button>
                    <button type="button" data-contents-tab="search" class="reader-tab" role="tab">Search</button>
                </div>

                <button type="button" id="reader-contents-close"
                        class="reader-btn rounded-md p-1.5" aria-label="Close">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="reader-sheet-body">
                <div data-contents-panel="chapters">
                    <p id="reader-chapters-empty" class="reader-empty hidden">
                        This file has no chapter outline.
                    </p>
                    <ul id="reader-chapter-list" class="reader-toc"></ul>
                </div>

                <div data-contents-panel="images" class="hidden">
                    <p id="reader-images-empty" class="reader-empty hidden">
                        No illustrations were found in this book.
                    </p>
                    <div id="reader-image-grid" class="reader-image-grid"></div>
                </div>

                <div data-contents-panel="search" class="hidden">
                    <label for="reader-search-input" class="sr-only">Search this book</label>
                    <input type="search" id="reader-search-input"
                           class="reader-search-input"
                           placeholder="Search this book&hellip;"
                           autocomplete="off">

                    <p id="reader-search-status" class="reader-search-status"></p>
                    <div id="reader-search-results" class="reader-search-results"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- One settings sheet, outside the chrome so it doesn't fade while it's
         being used. Grouped by what someone is actually trying to change:
         how the page looks, how big it is, and how bright it is. --}}
    <div id="reader-settings" class="reader-sheet hidden" role="dialog" aria-label="Reading settings">
        <header class="reader-sheet-header">
            <h2 class="text-sm font-semibold">Reading settings</h2>
            <button type="button" id="reader-settings-close"
                    class="reader-btn rounded-md p-1.5" aria-label="Close">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        <div class="reader-sheet-body">
            <section class="reader-group">
                <p class="reader-group-label">Page colour</p>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['paper' => '#faf6ef', 'sepia' => '#f2e5cd', 'night' => '#12121a'] as $name => $swatch)
                        <button type="button"
                                data-theme-option="{{ $name }}"
                                class="reader-theme-swatch"
                                style="background: {{ $swatch }}; color: {{ $name === 'night' ? '#d8d8dd' : '#2b2724' }}">
                            {{ $name }}
                        </button>
                    @endforeach
                </div>
            </section>

            @if ($format === 'pdf')
                <section class="reader-group">
                    <p class="reader-group-label">Fit page to</p>
                    <div class="reader-segment" role="group" aria-label="Fit">
                        @foreach (['width' => 'Width', 'page' => 'Whole page', 'actual' => 'Actual'] as $mode => $label)
                            <button type="button" data-pdf-fit="{{ $mode }}" class="reader-segment-btn">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </section>

                <section class="reader-group">
                    <p class="reader-group-label">Zoom</p>
                    <div class="reader-stepper">
                        <button type="button" id="reader-zoom-out" class="reader-stepper-btn" aria-label="Zoom out">&minus;</button>
                        <button type="button" id="reader-zoom-reset" class="reader-stepper-value" aria-label="Reset zoom">
                            <span id="reader-zoom-label">100%</span>
                        </button>
                        <button type="button" id="reader-zoom-in" class="reader-stepper-btn" aria-label="Zoom in">+</button>
                    </div>
                </section>

                <section class="reader-group">
                    <label class="reader-row">
                        <span>
                            Reflow text
                            <span class="reader-row-hint">
                                One column instead of the page. Layout and figures are lost.
                            </span>
                        </span>
                        <button type="button" id="reader-textmode-toggle" class="reader-switch" role="switch" aria-checked="false">
                            <span class="reader-switch-knob"></span>
                        </button>
                    </label>
                </section>
            @else
                <section class="reader-group">
                    <p class="reader-group-label">Text size</p>
                    <div class="reader-stepper">
                        <button type="button" id="reader-font-smaller" class="reader-stepper-btn">A&minus;</button>
                        <span class="reader-stepper-value">Size</span>
                        <button type="button" id="reader-font-larger" class="reader-stepper-btn">A+</button>
                    </div>
                </section>

                <section class="reader-group">
                    <label class="reader-row">
                        <span>Serif typeface</span>
                        <input type="checkbox" id="reader-serif" class="reader-checkbox">
                    </label>
                </section>
            @endif

            {{-- Front light, the way an e-reader does it: brightness first,
                 then a warm wash for reading at night. --}}
            <section class="reader-group">
                <p class="reader-group-label">Brightness</p>
                <div class="reader-slider-row">
                    <svg class="size-4 shrink-0" style="color: var(--reader-muted)" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <circle cx="12" cy="12" r="4" />
                    </svg>
                    <input type="range" id="reader-brightness" min="20" max="100" step="1"
                           class="reader-range" aria-label="Brightness">
                    <svg class="size-5 shrink-0" style="color: var(--reader-muted)" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <circle cx="12" cy="12" r="5" />
                        <path d="M12 1v3M12 20v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M1 12h3M20 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"
                              stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" />
                    </svg>
                </div>
            </section>

            <section class="reader-group">
                <p class="reader-group-label">Warmth</p>
                <div class="reader-slider-row">
                    <span class="size-4 shrink-0 rounded-full" style="background: #cfd8e3"></span>
                    <input type="range" id="reader-warmth" min="0" max="100" step="1"
                           class="reader-range" aria-label="Warmth">
                    <span class="size-4 shrink-0 rounded-full" style="background: #f0a860"></span>
                </div>
            </section>

            <a href="{{ route('media.read.file', $item) }}" download class="reader-sheet-link">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                </svg>
                Download this file
            </a>
        </div>
    </div>

    {{-- The page --}}
    <main id="reader-page" class="relative min-h-0 flex-1">
        {{-- Edge zones page forward and back; the middle toggles the chrome. --}}
        <div id="reader-edges" class="pointer-events-none absolute inset-0 z-10 flex">
            <button type="button" id="reader-prev"
                    class="reader-edge pointer-events-auto h-full w-[18%] border-0 bg-transparent"
                    aria-label="Previous page"></button>
            <div class="flex-1"></div>
            <button type="button" id="reader-next"
                    class="reader-edge pointer-events-auto h-full w-[18%] border-0 bg-transparent"
                    aria-label="Next page"></button>
        </div>

        <div id="reader"
             class="size-full"
             data-item-id="{{ $item->id }}"
             data-format="{{ $format }}"
             data-file-url="{{ route('media.read.file', $item) }}"
             data-progress-url="{{ route('media.read.progress', $item) }}"
             @if ($supportsAnnotations ?? false)
                 data-annotations-url="{{ route('media.read.annotations', $item) }}"
             @endif
             @if ($format === 'pdf')
                 {{-- __PAGE__ is substituted by the reader for whichever page
                      it is showing. --}}
                 data-ocr-url="{{ route('media.read.page-text', ['item' => $item, 'page' => '__PAGE__']) }}"
                 data-contents-url="{{ route('media.read.contents', $item) }}"
                 data-search-url="{{ route('media.read.search', $item) }}"
                 data-reading-text-url="{{ route('media.read.text', $item) }}"
             @endif
             data-location="{{ $progress?->location }}">

            {{-- Generous padding is what separates a reader from a document
                 viewer; the measure is capped so lines never run too long. --}}
            <div id="reader-surface"
                 class="mx-auto size-full max-w-3xl px-6 py-14 sm:px-10 sm:py-16"></div>

            {{-- Reflowed text. Hidden until the reader opts in, and always
                 one tap from the real page, because extraction is lossy. --}}
            @if ($format === 'pdf')
                <div id="reader-textmode" class="hidden size-full overflow-y-auto">
                    {{-- No standing banner. The reflow is opt-in and the
                         switch that turned it on says what it does; repeating
                         it above every page is nagging, not informing. --}}
                    <div id="reader-textmode-content" class="reader-flow"></div>
                </div>
            @endif

            <p id="reader-status"
               class="absolute inset-0 flex items-center justify-center text-sm"
               style="color: var(--reader-muted)">
                Opening {{ strtoupper($format) }}&hellip;
            </p>
        </div>

        {{-- Front light. Two composited overlays rather than a CSS filter on
             the page: a filter would re-composite the text on every slider
             move and stutter visibly. Both are click-through so tapping the
             page edges still turns pages. --}}
        <div id="reader-dim"
             class="pointer-events-none absolute inset-0 z-20 bg-black"
             style="opacity: 0"
             aria-hidden="true"></div>

        <div id="reader-warm"
             class="pointer-events-none absolute inset-0 z-20"
             style="opacity: 0; background: #ff9a3c; mix-blend-mode: multiply"
             aria-hidden="true"></div>

        {{-- Explains what's happening on a scanned page. Without it, a scan
             just looks like selection is broken. --}}
        @if ($format === 'pdf')
            <p id="reader-ocr-status" class="reader-ocr-status hidden" role="status"></p>
        @endif
    </main>

    @if ($supportsAnnotations ?? false)
        {{-- Appears where text is selected. Doubles as the edit menu when an
             existing highlight is tapped. --}}
        <div id="reader-selection-popover"
             class="reader-popover hidden"
             role="dialog"
             aria-label="Highlight">
            @foreach (['yellow' => '#f5c518', 'green' => '#5bc47a', 'blue' => '#5aa9e6', 'pink' => '#e87fa8'] as $key => $swatch)
                <button type="button"
                        data-highlight-color="{{ $key }}"
                        class="reader-swatch"
                        style="background: {{ $swatch }}"
                        aria-label="Highlight {{ $key }}"></button>
            @endforeach

            <span class="reader-popover-divider" aria-hidden="true"></span>

            <button type="button" id="reader-add-note" class="reader-popover-btn">
                Note
            </button>

            {{-- Only shown when an existing highlight was tapped; there is
                 nothing to remove from a fresh selection. --}}
            <button type="button" id="reader-remove-highlight"
                    class="reader-popover-btn reader-popover-danger hidden"
                    aria-label="Remove highlight">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M6 7h12M9 7V5h6v2M10 11v6M14 11v6M7 7l1 13h8l1-13" />
                </svg>
            </button>
        </div>

        {{-- Note editor --}}
        <div id="reader-note-editor" class="reader-note-editor hidden" role="dialog" aria-label="Note">
            <blockquote id="reader-note-excerpt" class="reader-note-quote"></blockquote>

            <label for="reader-note-input" class="sr-only">Note</label>
            <textarea id="reader-note-input" rows="5"
                      class="reader-note-input"
                      placeholder="Write a note&hellip;"></textarea>

            <div class="mt-3 flex items-center gap-2">
                <button type="button" id="reader-note-save" class="reader-note-primary">Save</button>
                <button type="button" id="reader-note-cancel" class="reader-note-secondary">Cancel</button>
                <button type="button" id="reader-note-delete" class="reader-note-danger ml-auto">Delete</button>
            </div>
        </div>

        {{-- Notes index --}}
        <aside id="reader-notes" class="reader-notes hidden" aria-label="Highlights and notes">
            <header class="reader-notes-header">
                <h2 class="text-sm font-semibold">Highlights &amp; notes</h2>
                <button type="button" id="reader-notes-close"
                        class="reader-btn rounded-md p-1.5" aria-label="Close">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <p id="reader-notes-empty" class="reader-notes-empty">
                Select any text to highlight it or attach a note. Everything you mark stays private to you.
            </p>

            <ul id="reader-notes-list" class="reader-notes-list"></ul>
        </aside>
    @endif
</div>

</body>
</html>

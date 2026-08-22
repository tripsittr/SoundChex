import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The sheet and the bar are separate Vite entry points, so neither can rely on
 * running first. The sheet defers until the bar announces the player, and both
 * re-run on every `livewire:navigated`.
 *
 * That combination is where the intermittent failure lived: the deferred wait
 * was registered with `{ once: true }` on every call, so a second call before
 * the player existed consumed the pending listener and queued another. The
 * click binding is guarded by a *global* flag, so whichever call eventually ran
 * could find the sheet already marked bound and skip binding entirely — leaving
 * the bar's title link an ordinary <a>, which navigates away instead of opening
 * the player.
 *
 * That is a real user-facing failure, not only a test one: tapping the bar
 * leaves the page.
 */
describe('now-playing sheet binding', () => {
    // The module binds one delegated listener on `document` and caches the
    // sheet's elements on `window`, both deliberately surviving a page swap.
    // Vitest shares a single jsdom document across the tests in a file, so
    // without a real reset the listener from one test keeps writing into the
    // previous test's detached nodes. Reloading the module per test is what
    // makes each one an honest first load.
    let bindNowPlayingSheet;

    beforeEach(async () => {
        vi.resetModules();

        document.body.innerHTML = '';
        document.head.innerHTML = '';
        document.body.style.overflow = '';

        // Not deleted: `soundchexSheet` is the object the delegated click
        // handler reads the live elements through. The handler from a previous
        // test is still attached to this shared document — the real page keeps
        // its listener across navigations too — so replacing the object would
        // leave that handler writing into detached nodes. Clearing it in place
        // keeps the one object identity every handler already holds.
        delete window.soundchexPlayer;
        delete window.soundchexSheetBound;
        delete window.soundchexSheetWaiting;

        if (window.soundchexSheet) {
            Object.keys(window.soundchexSheet).forEach((k) => delete window.soundchexSheet[k]);
        }

        document.body.innerHTML = markupFromComponent();

        ({ bindNowPlayingSheet } = await import('../../resources/js/now-playing-sheet.js'));
    });

    it('still binds when it runs twice before the player exists', () => {
        // Two navigations while the bar has not announced itself yet.
        bindNowPlayingSheet();
        bindNowPlayingSheet();

        // The bar comes up and announces the player, exactly as now-playing.js
        // does once it has one.
        window.soundchexPlayer = fakePlayer();
        document.dispatchEvent(new CustomEvent('soundchex:player-ready'));

        // The sheet must have bound despite the repeated deferral.
        expect(window.soundchexSheetBound).toBe(true);
    });

    it('opens the sheet from the bar rather than following the link', () => {
        bindNowPlayingSheet();
        bindNowPlayingSheet();

        window.soundchexPlayer = fakePlayer();
        document.dispatchEvent(new CustomEvent('soundchex:player-ready'));

        expect(window.soundchexSheetBound).toBe(true);

        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
        document.getElementById('np-link').dispatchEvent(event);

        // Preventing the default is what stops the browser navigating to the
        // item page — the whole point of the delegated handler.
        expect(event.defaultPrevented).toBe(true);
        // eslint-disable-next-line no-console
        console.log('SHEET IS UI SHEET:', window.soundchexSheet?.ui?.sheet === document.getElementById('np-sheet'));
        console.log('UI SHEET CLASS:', window.soundchexSheet?.ui?.sheet?.className);
        expect(document.getElementById('np-sheet').className).not.toContain('translate-y-full');
    });

    it('does not stack a listener for every deferred call', () => {
        bindNowPlayingSheet();
        bindNowPlayingSheet();
        bindNowPlayingSheet();

        // One flag, however many times it was asked to wait.
        expect(window.soundchexSheetWaiting).toBe(true);

        window.soundchexPlayer = fakePlayer();
        document.dispatchEvent(new CustomEvent('soundchex:player-ready'));

        expect(window.soundchexSheetWaiting).toBe(false);
        expect(window.soundchexSheetBound).toBe(true);
    });
});

function fakePlayer() {
    const listeners = {};

    return {
        el: { currentTime: 0, duration: 100, paused: false },
        queue: [],
        index: 0,
        shuffle: false,
        repeat: 'off',
        current: { id: 1, title: 'Test Tone', subtitle: 'Tester' },
        on(event, handler) {
            (listeners[event] ??= []).push(handler);
        },
        addEventListener(event, handler) {
            this.on(event, handler);
        },
        emit(event, payload) {
            (listeners[event] ?? []).forEach((h) => h(payload));
        },
        play() {},
        pause() {},
        next() {},
        previous() {},
        seek() {},
    };
}

/**
 * Builds the fixture from the real Blade component rather than a copy.
 *
 * The sheet reads two dozen elements by id, and a hand-written fixture drifts
 * the moment one is renamed — leaving a test that passes against markup the
 * application no longer has.
 */
function markupFromComponent() {
    const blade = readFileSync(
        resolve(__dirname, '../../resources/views/components/media/now-playing.blade.php'),
        'utf8',
    );

    const ids = [...new Set([...blade.matchAll(/id="(np-[a-z0-9-]+)"/g)].map((m) => m[1]))];

    const tagFor = (id) => {
        if (id === 'np-link') return `<a id="${id}" href="/app/item/1"></a>`;
        if (id.endsWith('queue')) return `<ul id="${id}"></ul>`;
        if (id.endsWith('pane')) return `<div id="${id}" hidden></div>`;
        if (id.endsWith('seek') || id === 'np-volume') return `<input id="${id}" type="range" />`;
        if (id.endsWith('artwork')) return `<img id="${id}" />`;
        if (/toggle|close|prev|next|shuffle|repeat|download|playlist/.test(id)) {
            return `<button id="${id}"></button>`;
        }

        return `<div id="${id}"></div>`;
    };

    // The bar and the sheet are siblings in the real component. Nesting the
    // bar inside the sheet would let a click on it reach the sheet's own
    // handlers as well, which the real layout never does.
    const inSheet = ids.filter((id) => id.startsWith('np-sheet') && id !== 'np-sheet');
    const inBar = ids.filter((id) => !id.startsWith('np-sheet'));

    return `<div id="now-playing">${inBar.map(tagFor).join('')}</div>`
        + `<div id="np-sheet" class="translate-y-full">${inSheet.map(tagFor).join('')}</div>`;
}

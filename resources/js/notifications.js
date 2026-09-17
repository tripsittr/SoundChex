// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Events the server recorded while this device was away.
 *
 * Pulled when the app opens rather than pushed: reaching a phone in someone's
 * pocket needs Apple's service and its own infrastructure. What arrives here is
 * what a push implementation would send, so building this first means the push
 * work is a delivery change rather than a rewrite.
 *
 * Nothing is shown unless the profile asked for it. The OS prompt is a one-off
 * per install, and firing notifications at someone who never turned them on is
 * how an app gets muted for good.
 */
import { notify } from './device-settings.js';
import { token } from './library/sync.js';

/** The last event id this device has seen. */
const CURSOR_KEY = 'soundchex.notifications.cursor';

function cursor() {
    try {
        const raw = localStorage.getItem(CURSOR_KEY);

        return raw === null ? null : Number(raw);
    } catch {
        return null;
    }
}

function setCursor(value) {
    try {
        localStorage.setItem(CURSOR_KEY, String(value));
    } catch {
        // Private browsing. Events replay next launch, which is better than
        // failing to show them at all.
    }
}

/**
 * What this profile wants to be told about.
 *
 * Read from the page rather than fetched: the server already rendered the
 * settings into the layout, and a request per launch to learn something that
 * rarely changes is a request wasted.
 */
function preferences() {
    const el = document.querySelector('[data-notification-prefs]');

    if (!el) return { enabled: false };

    try {
        return JSON.parse(el.dataset.notificationPrefs);
    } catch {
        return { enabled: false };
    }
}

/** Whether a given event type should be shown. */
function wanted(type, prefs) {
    if (!prefs.enabled) return false;

    return {
        episode_added: prefs.episodes !== false,
        scan_finished: prefs.scans === true,
        download_failed: true,
    }[type] ?? false;
}

/**
 * Fetches what is new and shows what was asked for.
 *
 * Silent on failure. This runs on every launch, including offline ones, and an
 * error here is not worth interrupting someone who opened the app to play music.
 */
export async function collect() {
    const prefs = preferences();

    if (!prefs.enabled) return { shown: 0 };

    try {
        const since = cursor();
        const url = since === null
            ? '/api/v1/notifications'
            : `/api/v1/notifications?since=${since}`;

        // Bearer, not cookie. The API is token-authenticated so a device can
        // sync whatever the session is doing — a plain fetch here returned
        // "Unauthenticated" and collected nothing, silently, forever.
        const bearer = token();

        if (!bearer) return { shown: 0 };

        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${bearer}`,
            },
        });

        if (!response.ok) return { shown: 0 };

        const { items = [], cursor: next } = await response.json();

        let shown = 0;

        for (const item of items) {
            if (!wanted(item.type, prefs)) continue;

            // Sequential rather than fired at once: several notifications
            // arriving in the same instant are collapsed by the OS into one
            // unreadable stack.
            // eslint-disable-next-line no-await-in-loop
            if (await notify(item.title, item.body ?? '')) shown += 1;
        }

        // Advanced past everything returned, including what was filtered out.
        // A filtered event is not one to offer again next launch.
        if (next) setCursor(next);

        return { shown, received: items.length };
    } catch {
        return { shown: 0 };
    }
}

/**
 * Collects on launch and when the app is brought back to the foreground.
 *
 * The second is what makes this feel like push on a phone: the app is opened,
 * and what happened while it was closed is waiting.
 */
export function watchForNotifications() {
    if (window.__soundchexNotificationsBound) return;

    window.__soundchexNotificationsBound = true;

    collect();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') collect();
    });
}

window.soundchexNotifications = { collect, cursor };

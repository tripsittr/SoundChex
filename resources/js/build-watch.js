// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Picking up a deploy without being reinstalled.
 *
 * Almost everything about this app is served rather than compiled in: the
 * Blade, the CSS, the JavaScript. A deploy reaches every device the moment the
 * assets are rebuilt — but only on the next page load, and an app left open on
 * a phone can sit on last week's build indefinitely.
 *
 * This notices and reloads. It matters most for a device that is not in the
 * same building as the server, where "just reinstall it" is not an option.
 */
const CHECK_INTERVAL = 60_000;

/** The build this page was loaded with. */
let loadedBuild = null;

/** Set once a reload is committed, so it cannot fire twice. */
let reloading = false;

/**
 * Whether a navigation is in flight.
 *
 * Livewire announces both ends, so this is exact rather than a guess at how
 * long a page takes.
 */
let navigating = false;

document.addEventListener('livewire:navigate', () => { navigating = true; });
document.addEventListener('livewire:navigated', () => { navigating = false; });

async function currentBuild() {
    try {
        const response = await fetch('/soundchex.json', { cache: 'no-store' });

        if (!response.ok) return null;

        const { build } = await response.json();

        return build ?? null;
    } catch {
        // Offline, or the server is down. Neither is a reason to reload — the
        // page in front of the user is the only working thing they have.
        return null;
    }
}

/**
 * Reloads, unless doing so would interrupt something.
 *
 * Playback is the case that matters: a reload mid-track is worse than running
 * a build behind, and the player survives navigation but not a hard reload of
 * a page whose assets have changed underneath it.
 */
function reloadWhenIdle() {
    if (reloading) return;

    const player = window.soundchexPlayer;
    const playing = player?.el && !player.el.paused;

    if (playing) {
        // Deferred rather than dropped: the next check will catch it, and the
        // one after that, until nothing is playing.
        return;
    }

    // Not while a page is being asked for.
    //
    // A reload during a navigation looks exactly like the app failing: the
    // screen goes white and comes back somewhere else. The device log for the
    // music page showed precisely this — reloading-for-build, mid-tap — and it
    // was mistaken for the page being broken.
    //
    // The next check catches it once the page has settled.
    if (document.visibilityState !== 'visible' || navigating) {
        return;
    }

    reloading = true;

    // Recorded before the reload, which destroys anything written after it.
    try {
        const events = JSON.parse(sessionStorage.getItem('soundchex.diagnostics') ?? '[]');

        events.push({
            kind: 'reloading-for-build',
            detail: { was: loadedBuild },
            at: Math.round(performance.now()),
            path: window.location.pathname,
        });

        sessionStorage.setItem('soundchex.diagnostics', JSON.stringify(events.slice(-40)));
    } catch {
        // Not worth failing the reload for.
    }

    window.location.reload();
}

export async function checkForUpdate() {
    const build = await currentBuild();

    if (build === null) return { changed: false };

    if (loadedBuild === null) {
        loadedBuild = build;

        return { changed: false, build };
    }

    if (build === loadedBuild) return { changed: false, build };

    reloadWhenIdle();

    return { changed: true, build, was: loadedBuild };
}

export function watchForBuilds() {
    if (window.__soundchexBuildWatch) return;

    window.__soundchexBuildWatch = true;

    checkForUpdate();

    // On returning to the app, which on a phone is when a deploy is most
    // likely to have happened since it was last looked at.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkForUpdate();
    });

    setInterval(checkForUpdate, CHECK_INTERVAL);
}

window.soundchexBuild = { checkForUpdate, current: () => loadedBuild };

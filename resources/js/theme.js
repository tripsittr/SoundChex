// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/**
 * Client theme customization for web + desktop (S-157).
 *
 * The same personalisation the iOS app has: a user-chosen accent, background
 * tone, and light/dark/system appearance, persisted per device and applied
 * live. The palette is already CSS custom properties (--sc-accent, --sc-base-*),
 * so applying a theme is just setting those on :root — no rebuild, and every
 * component that reads a token updates at once.
 *
 * Kept dependency-free and tiny so it can run early (see the inline bootstrap in
 * the layout) and avoid a flash of the default colours before hydration.
 */

const KEY = 'soundchex.theme';

const DEFAULTS = {
    appearance: 'dark', // 'system' | 'light' | 'dark'
    accent: '#e11d3a',
    backgroundDark: '#08080b',
    backgroundLight: '#f7f7f8',
};

export function readTheme() {
    try {
        const raw = localStorage.getItem(KEY);
        return raw ? { ...DEFAULTS, ...JSON.parse(raw) } : { ...DEFAULTS };
    } catch {
        return { ...DEFAULTS };
    }
}

export function saveTheme(theme) {
    try {
        localStorage.setItem(KEY, JSON.stringify(theme));
    } catch {
        /* private mode / disabled storage — apply for this session only */
    }
    applyTheme(theme);
}

/** Whether the effective scheme is light, resolving 'system'. */
function isLight(appearance) {
    if (appearance === 'light') return true;
    if (appearance === 'dark') return false;
    return window.matchMedia?.('(prefers-color-scheme: light)').matches ?? false;
}

/** Set the CSS custom properties on :root from a theme. */
export function applyTheme(theme = readTheme()) {
    const root = document.documentElement;
    const light = isLight(theme.appearance);

    root.style.setProperty('--sc-accent', theme.accent);
    root.style.setProperty('--sc-accent-hot', lighten(theme.accent, 0.12));

    // Background + the derived surface ramp. On light we step darker for the
    // raised surfaces; on dark, lighter — same idea as the iOS ThemeStore.
    const bg = light ? theme.backgroundLight : theme.backgroundDark;
    root.style.setProperty('--sc-base-900', bg);
    root.style.setProperty('--sc-base-800', shade(bg, light ? -0.03 : 0.04));
    root.style.setProperty('--sc-base-700', shade(bg, light ? -0.06 : 0.08));
    root.style.setProperty('--sc-base-600', shade(bg, light ? -0.10 : 0.13));
    root.style.setProperty('--sc-base-500', shade(bg, light ? -0.16 : 0.20));

    // Flip the ink ramp on light so text stays legible.
    if (light) {
        root.style.setProperty('--sc-ink-100', '#111114');
        root.style.setProperty('--sc-ink-300', '#3d3d44');
        root.style.setProperty('--sc-ink-500', '#6b6b78');
        root.classList.remove('scheme-dark');
        root.classList.add('scheme-light');
    } else {
        root.style.setProperty('--sc-ink-100', '#f4f4f5');
        root.style.setProperty('--sc-ink-300', '#b8b8c0');
        root.style.setProperty('--sc-ink-500', '#8a8a96');
        root.classList.remove('scheme-light');
        root.classList.add('scheme-dark');
    }
}

// --- small colour maths (no dependency) ------------------------------------

function parseHex(hex) {
    const h = hex.replace('#', '');
    const n = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
    return [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16));
}

function toHex([r, g, b]) {
    return '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
}

/** Blend toward white by amount (0..1). */
function lighten(hex, amount) {
    return toHex(parseHex(hex).map((v) => v + (255 - v) * amount));
}

/** Positive amount lightens, negative darkens. */
function shade(hex, amount) {
    return amount >= 0
        ? lighten(hex, amount)
        : toHex(parseHex(hex).map((v) => v * (1 + amount)));
}

// Apply immediately (the bootstrap already did a first pass to avoid a flash;
// this re-applies once the module loads, and reacts to a system-scheme change).
applyTheme();
window.matchMedia?.('(prefers-color-scheme: light)').addEventListener?.('change', () => {
    const theme = readTheme();
    if (theme.appearance === 'system') applyTheme(theme);
});

/**
 * Wire the Appearance panel on the settings page (S-157). Delegated on the
 * document so it survives SPA navigation, and re-synced whenever a panel appears.
 */
function syncPanel(panel) {
    if (!panel) return;
    const theme = readTheme();

    panel.querySelectorAll('[data-theme-appearance]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.themeAppearance === theme.appearance);
    });
    panel.querySelectorAll('[data-theme-accent]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.themeAccent.toLowerCase() === theme.accent.toLowerCase());
    });
    const custom = panel.querySelector('[data-theme-accent-custom]');
    if (custom) custom.value = theme.accent;
    const bgD = panel.querySelector('[data-theme-bg-dark]');
    if (bgD) bgD.value = theme.backgroundDark;
    const bgL = panel.querySelector('[data-theme-bg-light]');
    if (bgL) bgL.value = theme.backgroundLight;
}

function update(patch) {
    const theme = { ...readTheme(), ...patch };
    saveTheme(theme);
    syncPanel(document.querySelector('[data-theme-panel]'));
}

document.addEventListener('click', (event) => {
    const mode = event.target.closest('[data-theme-appearance]');
    if (mode) { update({ appearance: mode.dataset.themeAppearance }); return; }

    const accent = event.target.closest('[data-theme-accent]');
    if (accent) { update({ accent: accent.dataset.themeAccent }); return; }

    if (event.target.closest('[data-theme-reset]')) {
        saveTheme({ ...DEFAULTS });
        syncPanel(document.querySelector('[data-theme-panel]'));
    }
});

document.addEventListener('input', (event) => {
    if (event.target.matches('[data-theme-accent-custom]')) update({ accent: event.target.value });
    else if (event.target.matches('[data-theme-bg-dark]')) update({ backgroundDark: event.target.value });
    else if (event.target.matches('[data-theme-bg-light]')) update({ backgroundLight: event.target.value });
});

// Sync on load and after each SPA navigation.
syncPanel(document.querySelector('[data-theme-panel]'));
document.addEventListener('livewire:navigated', () => {
    applyTheme();
    syncPanel(document.querySelector('[data-theme-panel]'));
});

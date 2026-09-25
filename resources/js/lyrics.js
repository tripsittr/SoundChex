// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

import { logFailure } from './log.js';

/**
 * Lyrics for the now-playing sheet (S-301).
 *
 * Mirrors the iOS Now Playing lyrics view: plain words when that is all the
 * track has, and when time-synced LRC is present, the current line is
 * highlighted and kept in view, with a click on any line seeking to it.
 *
 * The parser deliberately matches `LRCLine.parse` on iOS line for line — the
 * same fixture should behave the same on both, and a second interpretation of
 * a timestamp format is a second set of bugs.
 */

/** `[mm:ss]`, `[mm:ss.xx]` or `[mm:ss.xxx]`. */
const TAG = /\[(\d{1,2}):(\d{2})(?:[.:](\d{1,3}))?\]/g;

/**
 * Parses LRC text into `{ time, text }` lines, sorted by time.
 *
 * A line may carry several timestamps (`[..][..] words`) — a chorus repeated
 * at different points — and each becomes its own entry. Returns `[]` for
 * empty or untimed input, which tells the caller to fall back to plain words.
 */
export function parseLrc(lrc) {
    if (!lrc) return [];

    const lines = [];

    for (const raw of lrc.split(/\r?\n/)) {
        const matches = [...raw.matchAll(TAG)];

        if (matches.length === 0) continue;

        // The words are whatever follows the *last* timestamp on the line.
        const last = matches[matches.length - 1];
        const text = raw.slice(last.index + last[0].length).trim();

        for (const match of matches) {
            const minutes = Number(match[1]) || 0;
            const seconds = Number(match[2]) || 0;

            lines.push({ time: minutes * 60 + seconds + fraction(match[3]), text });
        }
    }

    return lines.sort((a, b) => a.time - b.time);
}

/**
 * A fractional-second string as a 0–1 value, honouring its digit count.
 *
 * "5" is a tenth, "50" is half a second, "500" is half a second — centiseconds
 * and milliseconds are both used in the wild, and reading one as the other
 * puts every line up to a second out.
 */
function fraction(digits) {
    if (!digits) return 0;

    const value = Number(digits);

    return Number.isFinite(value) ? value / 10 ** digits.length : 0;
}

/**
 * The index of the line that should be highlighted at a position, or -1.
 *
 * The last line whose time has passed — not the nearest — so a long gap
 * between lines keeps the previous one lit rather than clearing the panel.
 */
export function currentLine(lines, position) {
    let index = -1;

    for (let i = 0; i < lines.length; i++) {
        if (lines[i].time <= position) index = i;
        else break;
    }

    return index;
}

/** Fetches a track's lyrics, or null when it has none / the request fails. */
export async function fetchLyrics(itemId) {
    try {
        const response = await fetch(`/app/item/${itemId}/lyrics`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) throw new Error(String(response.status));

        return await response.json();
    } catch (error) {
        // A track with no lyrics is a normal answer; a failed request is not,
        // and the panel should be able to tell the difference.
        logFailure('lyrics:fetch:failed', error, { item: String(itemId) });

        return null;
    }
}

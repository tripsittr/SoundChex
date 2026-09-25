# 200 — Lyrics in the web player

**Merged** 2026-09-24 · **Issues** S-301

The now-playing sheet has a lyrics panel. Where a track has time-synced words,
the current line is highlighted and kept in view, and clicking a line seeks to
it — the same behaviour as the iOS Now Playing view.

## What changed

A third pane beside artwork and the queue, with its own toggle in the sheet's
header. The toggle **stays hidden until the track has lyrics**: a button that
opens an empty panel is worse than no button.

Synced words scroll and highlight; plain words render as a static block; a
track with neither shows no button at all. On this library that matters —
**267 of 300 sampled tracks have synced LRC**, so the scrolling path is the
common one, not the exception.

### A route the player can actually call

`/api/v1/items/{item}/lyrics` needs a bearer token, and the web player has a
session instead. `GET /app/item/{item}/lyrics` returns the same
`{ lyrics, synced }` payload through the session guard and the same content
gate.

### The parser mirrors iOS deliberately

`parseLrc` is a line-for-line mirror of `LRCLine.parse` in the app. The same
file should behave the same on both, and a second interpretation of a
timestamp format is a second set of bugs. In particular:

- The fraction honours its **digit count** — `[00:10.5]` is 10.5s, `[00:10.05]`
  is 10.05s. Reading one as the other puts a line up to a second out.
- A line with several timestamps (`[..][..] Chorus`) becomes one entry per
  time, because that is how a repeated chorus is written.
- An empty timed line is **kept**, not dropped: it is a musical gap, and
  closing it up makes the highlight jump early.
- The highlighted line is the **last one whose time has passed**, not the
  nearest, so a long instrumental keeps the previous line lit.

## Worth knowing

- Lyrics load on track change whether or not the sheet is open, so the button
  is already correct when it is opened.
- A response that arrives after the track changed is discarded — otherwise the
  panel shows the previous song's words.
- Panel state lives on the persisted sheet object, so an SPA navigation does
  not refetch what is already loaded.
- The three panes are driven by one function rather than a toggle each: two
  independent toggles eventually show both at once.

## Still wrong

Nothing found. Not attempted: per-word (enhanced LRC) timing, which the
parser ignores the way the iOS one does.

## Tests

Vitest · `tests/js/lyrics.test.js` 15/15 — fraction digit counts, multiple
timestamps, sort order, empty and untimed input, metadata headers, and a guard
against the module-level `/g` regex leaking `lastIndex` between calls. Whole JS
suite 118/118.

PHP · `LyricsServiceTest` 6/6, three new for the web route: it serves a signed-in
listener, redirects a stranger, and answers nulls rather than an error for a
track with no words.

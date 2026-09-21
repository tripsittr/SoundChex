# 149 — API endpoints for subtitles and book reading (S-160, S-161)

The native app can play video and, soon, read books — but the subtitle and
reader routes were **session-authed only**, so the token-authed app could not
reach them. This adds the token-authed equivalents. Server-side only; the iOS
wiring is a separate change.

## Subtitles (S-160)

`Api\SubtitleController`:

- `GET /api/v1/items/{item}/subtitles` — the video's caption tracks (id, label,
  language, forced/SDH/default flags, and a URL for each track's content).
- `GET /api/v1/items/{item}/subtitles/{subtitle}` — one track's **WebVTT**, with
  `Content-Type: text/vtt`, for the player to render.

## Book reading (S-161)

`Api\ReaderController`:

- `GET /api/v1/items/{item}/reader` — the book's format (epub/pdf/cbz/cbr) and
  the resume point (an opaque location token + percent).
- `GET /api/v1/items/{item}/book` — the book file itself, inline, for the reader
  to render.
- `POST /api/v1/items/{item}/reader/progress` — saves the reading position,
  keyed to the current profile, with the same offline-staleness guard the web
  reader uses (a late queued write older than what is stored is refused, so
  replaying an old position cannot undo reading done since).

Text-per-page and annotations (the web reader's extras) are not exposed yet — the
app renders EPUB/PDF from the file directly; those can follow when the native
reader needs them.

## Access

Every endpoint runs through the same `ContentGate` as streaming: a capped profile
gets a 404 (not a 403) for a video it may not play, so it cannot pull a film's
subtitles — or learn the film exists — past the gate. (Books carry no rating in
this library, so the gate does not block them by rating; the check still runs, so
any rule it grows applies here too.)

## Tests

`Api\SubtitleApiTest` (5) and `Api\ReaderApiTest` (5): listing and serving
content, the not-mine/404 and wrong-type cases, the reading-progress staleness
guard, and the content-gate leak for a capped profile. API/media suites green (86).

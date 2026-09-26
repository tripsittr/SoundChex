# Continue watching and reading, over the API

S-414 (server half).

The web home has had a "continue watching" row for a long time. It is the row
that makes a home page feel like *yours* rather than a catalogue — and it was
never exposed to the apps, so the phone's home page could only ever show
"Recently added" and one row per media type. Identical on day one and day five
hundred.

`GET /api/v1/library/continue` returns both shelves in one response:

```json
{ "watching": [...], "reading": [...] }
```

Films, shows and books together, because the home page wants one "continue"
shelf and should not make three round trips to build it.

## Why the server and not the device

Resume position lives in `media_plays`, and the library mirror the device holds
does not carry it. Sending every play row to every device so each could work
this out itself would be a lot of history to sync for one row.

## Reusing what was there

The logic is `MediaBrowser::continueWatching()` and `continueReading()`,
unchanged — already profile-scoped, rating-gated, and deduplicated to one row
per item. This only exposes them. Duplicating the rules for the API would have
meant two definitions of "in progress" drifting apart.

Both keep their existing thresholds: a minute in for video, a page turned for
books. A few seconds is a mis-tap, not a viewing, and offering it back is how a
home page fills with things nobody watched.

## Testing

7 tests, 1,107 passing. They cover what the row must never do: offer a finished
film, offer one barely started, offer one never opened, or show another
person's progress — two people share a login here, so that last one is a small
privacy failure as well as a useless row.

## A note on this library

Run against the real catalogue, both shelves come back empty — there are only
two video items in it, and the CLI has no profile to scope by. The endpoint is
right; the library simply has little video. This row will earn its place as
that grows, and for books sooner.

# 031 — "Not here yet" is not a codec fault

**Merged** 2026-08-23 · **Issues** S-111, GitHub #51

The owner tapped a song on their phone and got *"unsupported source"*. Nothing
was unsupported: the file had not been copied to that server yet, and would
arrive a few hours later.

## What changed

### The server says which kind of missing it is

`stream()` returned a bare `404` whether a row did not exist, was blocked by
the content gate, or was catalogued and waiting for its file. The browser
reports any failed load as `MEDIA_ERR_SRC_NOT_SUPPORTED`, so all three read as
a codec problem.

A catalogued row whose file has not arrived now answers **409** — the row is
real and the condition is temporary. A row with no path at all is still a
`404`, because nothing was ever promised.

### The player asks, instead of guessing

The media element does not expose the HTTP status, so the player makes a `HEAD`
request when a load fails and emits `unavailable` on a 409. Best effort: a
failed explanation must not become a second failure.

### And the person holding the phone is told

`now-playing.js` shows *"That file has not arrived on this server yet."*

## Why it matters at this size

A transfer imports the catalogue in **one** import and then copies files for
**nine hours**. Measured on `a5` mid-copy: 13.7% of catalogued paths present,
so roughly **7,500 items** looked completely normal — artwork, metadata,
`processing_status: complete` — and every one of them failed with a message
blaming the decoder.

## Worth knowing

- **The message is fixed here; the underlying oddity is not.** A library that
  advertises what it does not hold is the real problem, and belongs with S-90.
  Filtering or marking absent items is a bigger change than this one.
- Both halves are tested and the server test fails without the fix.
- No migration.

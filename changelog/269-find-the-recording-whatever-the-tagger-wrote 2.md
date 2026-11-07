# 269 — Find the recording, whatever the tagger wrote

**Merged** 2026-10-06 · **Issues** part of #466

MusicBrainz matches a query literally, so an edition suffix or a second
credited artist made a recording it plainly knows about unfindable.

## What changed

Measured against the live API before writing anything:

```
"The Modern Age - Rough Trade Version" / The Strokes  -> nothing
"The Modern Age"                       / The Strokes  -> FOUND
"In Spite of Ourselves" / "Viagra Boys, Amy Taylor"   -> nothing
"In Spite of Ourselves" / "Viagra Boys"               -> FOUND
```

Of this library's 642 genuinely unmatched rows, **360 carry a comma-joined
artist** and **155 an edition suffix** — so this is most of what is left after
#485 showed the headline problem was stale data rather than unreadable tags.

`resolveRecording()` now tries up to four shapes of the same query, most
faithful to the file first:

1. **Exactly what the file says.** Right for a well-tagged file, and first so a
   precise match is never passed over for a looser one.
2. **The primary artist alone.** `Viagra Boys, Amy Taylor` is one credit string
   for a collaboration MusicBrainz indexes under the first artist.
3. **The title without its edition.** `The Modern Age - Rough Trade Version` is
   a release's wording for a recording MusicBrainz calls `The Modern Age`.
4. **Both dropped.**

Deduplicated, so a file with neither problem costs **exactly one search** and
nothing is queried twice. At 1 req/s that is the difference between a one-hour
run and a two-hour one.

### The edition is dropped from the query, never from the title

`Psycho Killer - Acoustic` and `1979 - Remastered 2012` are distinct
recordings, and collapsing them is the loss #476 forbids. A test asserts the
stored title keeps its suffix after a match.

## Worth knowing

- A match found this way is `Fuzzy`, not `Exact` — it came from a text search
  rather than an identifier, which is the distinction #459 established.
- Verified on two real unmatched rows, which both moved `none → fuzzy`.
- Six of the eight new tests fail without the change. The two that pass either
  way are the ones that must not break: a genuinely unknown recording still
  matches nothing, and the stored title keeps its edition.
- This does not re-run over existing rows. `music:reenrich` does that, and the
  642 unmatched rows are the set worth pointing it at.

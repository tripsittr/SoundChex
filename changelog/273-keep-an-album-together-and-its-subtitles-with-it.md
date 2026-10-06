# 273 — Keep an album together, and its subtitles with it

**Merged** 2026-10-06 · **Issues** #468, #488

Two filing bugs, both measured on the real library before being fixed.

## A compilation scattered into one folder per track

Music filed under `Artist/Album/Track` using the **track** artist. Measured:

```
160 albums would spread across 429 artist folders
Stranger Things (Soundtrack)   14 artist folders for 14 tracks
Greatest Hits                  19 artist folders for 37 tracks
```

The album-artist tag exists to answer this and taggers have written it for
twenty years. Nothing read it except as a *fallback* when the track artist was
missing, which is backwards: the track artist names who played, the album
artist names the shelf.

`music_metadata.album_artist` is new, read from `album_artist`, `albumartist`,
`album artist` and `band`. The filer uses it, **falling back to the track
artist** — which is why most of the library is unaffected, since a single
artist's album has no album-artist tag and needs none.

The filename still strips the **track** artist, not the album artist: on a
compilation the performer is the one thing the filename should keep.

## Subtitles were orphaned by filing

The organizer had no sidecar handling at all. Filing a film left its captions
in the inbox, and the player looks for them beside the video.

`.srt .ass .ssa .vtt .sub .idx .lrc .nfo .cue` now travel with the file, keeping
any language or flag suffix: `Alien.en.forced.srt` → `Alien (1979).en.forced.srt`.

**Matched on the exact stem, not a prefix glob.** `SubtitleImporter` uses
`base*.srt`, which lets `Alien.mkv` claim `Aliens.en.srt` — a test asserts that
cannot happen here. A sidecar already at the target is left alone, since it is
more likely the right one than the stray being carried in.

## A plan claim that was wrong

§4.2 says multi-disc albums cause the `(2)` suffixes, and that disc folders
would fix them. Measured: **zero multi-disc albums in this library.**

The real cause is **787 groups of rows sharing artist + album + track + title**
— duplicate pairs, which the version model (#457) now classifies. So a `(2)`
suffix is a symptom of an unresolved duplicate, and disc folders would not have
touched it. Deferred as #488 rather than built blind.

## Worth knowing

- **No migration backfills `album_artist`.** It is read on each file's next
  enrichment, so a compilation stays scattered until then. `library:organize`
  will move them once it is populated.
- Nothing is re-filed by this. It changes paths computed from here on.
- The three sidecar tests that pass *without* the fix are the over-reach
  guards: not stealing a neighbour's subtitle, not overwriting, not touching
  unrelated files.

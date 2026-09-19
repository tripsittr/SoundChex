# 114 — Bake cover art into the audio files

*2026-09-19.* · **Issue** S-274

## Done

The catalogue knew the right cover, but the audio file itself often carried the
wrong embedded art (a compilation cover) or none — so it looked wrong in any
other player and a fresh re-scan read the bad art straight back in. Covers are
now written into the file's own tags.

- **`CoverEmbedder`** uses ffmpeg (already a dependency) to write the item's
  local cover into the audio file. It **stream-copies** the audio (`-c copy`) so
  nothing is re-encoded and no quality is lost, and writes to a sibling temp file
  that is only renamed into place on success — a failure or a kill mid-run never
  leaves the user's track truncated. It only ever embeds a cover we hold locally
  (never a remote URL) and only for an embeddable container (mp3, m4a/mp4, flac,
  aiff).
- **At enrichment time:** `EnrichMediaItemJob` bakes the cover in after the
  pipeline resolves it — but **only for an Exact match**, so a shaky Fuzzy/None
  match can't write a wrong cover into the file.
- **On demand:** `artwork:refresh --embed` bakes the freshly-fetched cover into
  each file it updates (skips the step, with a warning, if ffmpeg is absent).

## Worth knowing

- **Order matters:** embed *after* the cover is correct. Run `artwork:refresh`
  (fetch the right cover) then re-enrich or `artwork:refresh --embed`; embedding
  a track that still has the wrong `cover_image_url` bakes in the wrong art.
- Rewriting the file changes its bytes, so its content hash changes — expected;
  it's the user's file being corrected.
- ffmpeg required for the embed; without it covers still update in the catalogue,
  they're just not written into the files.

## Tests

PHP: **660 passing** (+5). `CoverEmbedderTest` (ffmpeg faked) asserts the embed
command (`-map`, `attached_pic`, `-c copy`), and the guards: no local cover, a
remote URL, a non-embeddable extension, and — the important one — the original
file is left byte-for-byte untouched when ffmpeg fails. Pint clean.

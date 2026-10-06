# 259 — Music files on its own tags, not on a guess

**Merged** 2026-10-05 · **Issues** #460

Music was exempt from the confidence gate entirely, so a file could be moved
into the library under an artist no one had confirmed — including a file
sitting in the review queue.

## What changed

### The exemption now means what it says

`LibraryOrganizer::isConfidentEnoughToMove()` returned `true` for *all* music.
The stated reason (AGENTS.md rule 2) is that a music file's artist and album
come from its own embedded tags, which are authoritative about the file
whatever an online source thinks.

That reasoning is sound. It is not what the code did. MusicBrainz enrichment
*writes* `artist` and `album`, and after a run nothing distinguished a real
embedded tag from an API text match — so "music is exempt" meant "music is
filed on whatever the last source guessed".

The rule is now the one the exemption always described: music may be filed when
**its own embedded tags named the artist its path is built from**, or when the
match is `Exact`. API data alone never moves a file.

### Only FileTagger can tell a tag from a guess, so it records it

`FileTagger` is the single source that reads the file itself, and it already
separates `taggedFields()` (the embedded tags) from `parseFilename()` (the
fallback). It now notes whether the embedded tags supplied the artist, as
`tagged_artist` in `enrichment_report`, read back through
`MediaItem::hasTaggedArtist()`.

`MetadataPipeline::run()` rewrites `enrichment_report` once every source has
finished, which would have discarded that key milliseconds after it was
written. It now merges what is already stored instead of replacing it, with the
pipeline's own account winning on any shared key.

This is a narrow stand-in for per-field provenance, which arrives with the
`FieldWriter` and `field_sources` work in #466 and will answer the same
question for every field and every source.

### Nothing awaiting review is filed

An item whose `processing_status` is `NeedsReview` no longer moves, whatever
its confidence — moving a file the user has been asked to judge pre-empts the
answer. An item they have already judged (`reviewed_at` set) does file, because
holding a reviewed item back forever is the opposite mistake, and the one that
made reviewed songs keep reappearing (S-302).

## Worth knowing

- **No migration, but behaviour changes for existing rows.** `tagged_artist` is
  absent on everything enriched before this, which reads as `false`. Such an
  item now needs `Exact` to be filed, where before any music row would move.
  That is the cautious direction, and it is deliberate: the alternative is
  trusting a flag that was never recorded.
- Music enriched from here on records the flag during its next enrichment, so
  the looser rule returns for genuinely tagged files without any backfill.
- `test_music_is_exempt_from_the_confidence_gate` is renamed and split in two.
  It asserted the unconditional exemption — the bug — while its own comment
  described the correct rule. It now tests what it describes, plus the inverse.

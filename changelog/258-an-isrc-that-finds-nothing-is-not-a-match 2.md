# 258 — An ISRC that finds nothing is not a match

**Merged** 2026-10-05 · **Issues** #459

A music file carrying an ISRC was recorded as an exact match even when the
ISRC lookup found nothing and the recording came from a text search.

## What changed

### Confidence comes from how the recording was found, not from what the file carries

`MusicBrainz::enrich()` decided this up front:

```php
$matchedByIdentifier = filled($item->musicMetadata?->musicbrainz_recording_id)
    || filled($item->musicMetadata?->isrc);
```

That asks whether the *file* has an identifier, not whether the identifier
found anything. `resolveRecording()` tries the ISRC, and when the search comes
back empty it **falls through** to an artist-and-title text search — but the
confidence had already been fixed as `Exact`. So a loose text match on a file
that happened to carry an ISRC was recorded as unambiguous.

A dead or mistyped MBID had the same effect.

`resolveRecording()` already knows which route it took, so it now reports it
back through a by-reference flag, set only when a lookup actually returned a
recording. `enrich()` starts from `false` and trusts the resolver.

## Worth knowing

- No migration. Existing rows keep their recorded confidence; this stops new
  text matches being written as `Exact`.
- Re-scoring the ones already stored wrong belongs with the real scorer in
  #466, which can compare titles and durations rather than trusting a flag.
- The companion test asserts the case that must keep working: an ISRC that
  *does* resolve the recording is still `Exact`.

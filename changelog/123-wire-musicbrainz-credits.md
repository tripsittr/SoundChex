# 123 — Wire MusicBrainz's identified credits into enrichment (S-38)

`MusicCredits::fromMusicBrainz()` was written and tested but **never called in
production**. Enrichment always parsed the joined artist string
(`fromCreditString()`), so even when MusicBrainz matched a recording, the
credits it returned — each artist's own name *and* stable MBID — were thrown
away and re-derived from text. The better source was built and unused.

## Fix

- **MusicBrainz now writes credits when it matches.** Its `enrich()` already
  held the recording's `artist-credit` array (names + ids); it now passes that
  to `fromMusicBrainz()`, so the credits carry MusicBrainz artist ids. That
  keeps two artists who share a name separate, and one artist under a renamed
  record single — the whole point of matching on id.

- **The string parser no longer clobbers them.** The enrichment job's credit
  step ran `fromCreditString()` unconditionally, which detaches and re-attaches
  the credited people — and would have replaced the id-carrying entries with the
  same names and no ids. It now falls back to string parsing only when the item
  has no identified (MBID-carrying) credits, i.e. when MusicBrainz did not match.

## Tests

- `EnrichmentCreditWiringTest` — identified credits survive the job's credit
  step; the string parser still runs when there was no MusicBrainz match.
- Full suite: 726 passed.

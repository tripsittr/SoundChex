# 135 — Cover sources are a plugin seam (S-264, #280)

Cover-art fetching was hardcoded to iTunes and Deezer. A plugin can now
contribute a cover source, tried as a fallback after those — reaching an artwork
provider the core does not, without disturbing the built-in path.

## What this adds

- **A `CoverSource` contract** (`App\Plugins\Contracts\CoverSource`) — `name()`,
  `priority()`, and `coverUrlFor($artist, $album)` returning a verified cover URL
  or null.
- **`Registry::coverSource($class, $priority)`** — the seam a plugin registers on.
- **`CoverArtFetcher` consults plugin sources** after iTunes and Deezer, in
  priority order, until one returns a URL. A source that throws is logged and
  skipped, so one plugin cannot break cover fetching. The built-in path is
  unchanged — this is purely additive.
- **A worked example** (`plugins/examples/coverart-archive`) — a real cover
  source backed by the Cover Art Archive via MusicBrainz, keyless, in ~40 lines,
  validating the release group's artist so a loose match cannot return the wrong
  cover.

## Tests

- `PluginCoverSourceTest` — a plugin cover source is tried as a fallback when the
  built-ins find nothing; a throwing source is skipped and the next still runs;
  no plugin source leaves the result null. The 34 existing cover tests are
  unchanged.
- Full suite: 788 passed.

## Arc

This is the first of the two seam-building conversions. #281 (notification
targets) is next — the same shape, a registry seam plus the destinations moved
onto it. #282 (built-in metadata sources as plugins) was deferred as low-value
churn.

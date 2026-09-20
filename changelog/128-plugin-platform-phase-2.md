# 128 — Plugin platform, Phase 2: metadata sources as a plugin seam (S-264)

Phase 1 built the loader; this makes it do something real. The metadata pipeline
now runs plugin-contributed sources alongside its built-in ones — the first
dogfooded extension point, and the pattern the deferred metadata sources of S-39
ship through.

## What this adds

- **The pipeline reads plugin sources.** `MetadataPipeline::sourcesFor()` merges
  the built-in sources from `config/metadata_sources.php` with any a plugin
  registered on the `Registry` for that media type. A plugin source is an
  ordinary `MetadataSource` — it lands in the same list, is ordered by its own
  `priority()`, and nothing downstream can tell it from a core source. One helper
  builds the merged list so the run and the skip report never disagree.

- **A worked example plugin — Year Tagger.** A real, bundled plugin under
  `plugins/examples/year-tagger`: a manifest, an entry class, and a keyless
  `MetadataSource` that fills a film or show's release year from its filename
  when no provider supplied one. It runs late, so it only fills a gap. This is
  the smallest thing that is still a complete plugin — the shape an author copies,
  and the shape a richer source (Last.fm, Discogs) takes with an HTTP client
  added.

## Closing S-39

The 11 metadata sources S-39 listed were deferred into the plugin system rather
than hardcoded. This phase makes that concrete: metadata sources are now a
working plugin seam, proven end-to-end by the Year Tagger installing and
enriching for real. Each of those sources ships as a plugin using this exact
pattern, as they are actually wanted — none belong in core.

## Tests

- `PluginMetadataSourceTest` — a plugin source joins the pipeline for its type,
  is ordered by its own priority among the built-ins, and does not run for other
  types.
- `ExamplePluginTest` — the bundled Year Tagger, discovered from disk, enabled,
  autoloaded at runtime, and actually filling `release_year: 2018` from a
  filename through a full pipeline run; and leaving a provider-set year alone.
- Full suite: 748 passed.

## Next

Phase 3 introduces the hook/event layer (named actions and pipeline filters at
scan, import, playback and metadata points) and the remaining registry seams —
cover/subtitle sources, routes, and the per-plugin settings page.

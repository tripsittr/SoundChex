# Measure files that were catalogued before anything measured them

Capability badges — 4K, Dolby Vision, 5.1, CC — are derived from the media
probe. A film with no probe row shows none of them, which reads as a feature
that does not work rather than a file nobody has read.

`ProbeStage` probes everything that goes through the pipeline, so anything
added since it existed has a probe row. **Nothing ever went back for the rest,
and nothing ever would.** The command to do it has existed all along and skips
already-probed items, so it was always safe to run — there was simply no reason
for an owner to know it existed.

Measured on this machine: **0 of 5 video items** had a probe row, two of them
real films sitting at `pipeline_state = done`. Probing them produced `HD` and
`360p` immediately — the badges worked the whole time, with nothing to read.

The same gap silently disabled the quality checks that find a truncated file.
Probing those two films found one: item 8318 is truncated.

## How it runs

Hourly, capped at 200 items, `withoutOverlapping`. Probing reads every file
header and a large library is hours of work, so an uncapped hourly command
would saturate the disk in one pass. A capped one converges over a night
instead, because already-probed items are skipped — which is also why
`--reprobe` must never be the scheduled form: it would re-measure the whole
library every hour and never finish.

Each of those four properties has a test, and each was confirmed to fail when
the property was removed.

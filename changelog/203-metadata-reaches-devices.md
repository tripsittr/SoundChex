# 203 — A metadata change reaches the phone

**Merged** 2026-09-25 · **Issues** S-386

A correction made on the server never arrived. The album keys fixed in #202
were still wrong on iOS afterwards, and the reason was not the fix.

## What was wrong

The delta sync asks for items whose **`media_items.updated_at`** has moved.
But almost everything a person edits lives in a child row — album, artist,
rating — and none of the metadata models touched their parent. So:

```
music_metadata.updated_at   2026-09-25 05:08   (the rekey)
media_items.updated_at      2026-09-20 01:24   (untouched)
```

The delta returned nothing, every device kept its stale copy, and there was no
way to ask for it again short of reinstalling.

**3,708 items** in this library were behind their own metadata.

## What changed

`$touches = ['mediaItem']` on all four metadata models, so a child write bumps
the parent and the delta reports it. Saving an unchanged value is still a
no-op, so a rescan that finds nothing does not hand every device the whole
library again.

`library:touch-stale-metadata` bumps the rows written before this existed —
needed once, and a dry run by default.

## Worth knowing

- **Run on this machine's library**, after a backup
  (`soundchex-2026-09-25_051805.sqlite.gz`): 3,708 items touched.
- No client change needed for this half: the device asks the same question and
  now gets an answer.
- The scan path was already covered — a scan creates items and enrichment
  writes metadata, and both now move the parent.

## Still wrong

Nothing found here. The related iOS change (refresh on staleness, and
gathering album-less tracks as "Singles") ships separately.

## Tests

PHP · `MetadataTouchesParentTest` 3/3: a metadata change bumps the item, the
delta endpoint actually returns it, and an unchanged save does not.

Related suites 174/174.

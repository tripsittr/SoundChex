# 124 — Snapshot a file's metadata the moment it arrives (S-21)

The state a file arrived in existed nowhere. `metadata_versions` held ~5,800
rows, but every one had `reason = enrichment` — the pipeline snapshots *before
each run*, never at intake. So the original filename, the path it landed at, and
the tags it carried were overwritten by the organiser and enrichment and never
kept. S-44 is what that costs: it had to infer a file's original name from the
shape of its title, because the real one was gone.

## What this adds

- **`MetadataHistory::recordIntake()`** writes one `import` version when a file
  is first catalogued, capturing the normal snapshot *plus* an `intake` section
  the regular snapshot deliberately omits: the original `file_path`, `file_name`,
  `file_size`, `content_hash` (when already computed) and an `arrived_at`.
- **The scanner calls it in `catalog()`**, right after the row is created and
  before the organiser renames the file or enrichment rewrites the title — the
  only moment the arrival state is still present.
- **Idempotent.** A re-scan of the same path does not add a second `import` row;
  the intake is the baseline, recorded once.

## Notes

- The regular snapshot omits `file_path` on purpose (restoring an old path would
  point the row at a moved file), which is exactly why the arrival path needed
  its own `intake` section rather than riding along in the versioned item fields.
- `content_hash` is null at intake for a fresh file (the duplicate detector
  computes it just after), and is captured whenever it is already present. The
  irreplaceable data — the original name, path and size — is always kept.
- Existing rows have no intake version; this applies from the next scan onward.

## Tests

- `LibraryScannerTest` — a scan records an `import` version keeping the original
  filename, path and size; a re-scan does not add a second.
- Full suite: 728 passed.

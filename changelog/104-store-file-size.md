# 104 — Store each file's size (S-119)

*2026-09-18.*

## Done

No file size was stored, so library-size figures were a random 200-item sample
scaled up — a real total meant one `filesize()` per item (thousands) on the page
that loads most. Now the size is stored.

- **`file_size` column** on `media_items` (nullable bigint), captured at
  **catalogue time** by the scanner (`sizeOf()`), so new items always have it.
- **`php artisan library:backfill-sizes`** fills it for existing rows — chunked,
  idempotent (only null rows), `saveQuietly` so it bumps no timestamps. Ran it on
  the real library: **8,326 of 8,328 filled** (2 unreadable, left null).
- **`LibraryOverview`** now sums the stored `file_size` for an **exact** total,
  sampling only the shrinking set that isn't backfilled yet — the real library is
  now reported at an exact **46.4 GB** instead of a scaled estimate.

## Tests

Three: scanning captures the size; backfill fills only missing rows; an
unreadable file stays null. 115 library/scanner/overview tests pass.

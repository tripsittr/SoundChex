# 028 — Trimming that counts characters

**Merged** 2026-08-23 · **Issues** S-109, GitHub #39

Found by the log watcher on `a5` rather than by anyone looking at a title,
which is the point: the damage was invisible until it broke JSON encoding
downstream.

## What changed

### `trim()` with a multi-byte mask no longer eats characters

```php
$title = trim($title, " -–—_");
```

reads as "strip spaces, dashes and underscores". PHP's `trim()` takes a **byte**
mask, and `–` and `—` are three bytes each in UTF-8, so the real mask is:

```
0x20  0x2D  0xE2  0x80  0x93  0x94  0x5F
```

`E2` and `80` are in it. Every character in the General Punctuation block
begins `E2 80`, so a leading curly quote, ellipsis or bullet lost its first two
bytes and left the third stranded:

```
’Cause I’m a Man     →  <0x99>Cause I’m a Man      not valid UTF-8
…baby one more time  →  <0xA6>baby one more time   not valid UTF-8
“Heroes”             →  <0x9C>Heroes”              not valid UTF-8
```

A new `Titles::trim()` matches a character class with the `u` flag, so it
cannot land mid-sequence.

### Four sites, not one

The issue reported `cleanTitle()`. The same mask was in three more places:

- `LibraryScanner::cleanTitle()` — titles
- `LibraryScanner` author cleaner — `" ._-–—"`
- `LibraryScanner` — a third cleaner
- `EpisodeParser` — `" -–—_."`

All four now go through the same helper.

## Worth knowing

- **No rows were rewritten.** This stops new corruption; it does not repair
  existing rows.
- **The Mac's catalogue is clean.** Checked every text field of all 8,315
  items — `title`, `artist`, `album`, `primary_artist` — and found **zero**
  invalid UTF-8. The corruption reported on `a5` is not present here, contrary
  to the expectation in #39 that it would be.
- Repairing `a5`'s 27 rows is a separate job, and the titles there are corrupt
  in the database rather than only on screen.
- A test deliberately asserts the *old* behaviour is broken, so if a future PHP
  makes `trim()` character-aware the helper can be reconsidered rather than
  kept out of superstition.

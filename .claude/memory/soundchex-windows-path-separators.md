---
name: soundchex-windows-path-separators
description: "Storage::path() and realpath() return mixed separators on Windows, and every path comparison in this project that used DIRECTORY_SEPARATOR was silently broken there"
metadata: 
  node_type: memory
  type: project
  originSessionId: ad369037-1b3f-4737-96d3-96dfa841d301
  modified: 2026-08-23T08:42:08.920Z
---

**Normalise to forward slashes before comparing any two paths.** On Windows
this project produces both spellings of the same path and they never match as
strings:

```
Storage::path('media/library')  →  C:\…\storage\app/private\media/library
realpath($absolute)             →  C:\…\storage\app\private\media\library
$file->getRealPath()            →  C:\…\media\library\x.avi
```

Backslashes for the root, forward slashes for whatever was appended. Four real
bugs on 23 August 2026, all invisible until measured:

- **The scanner catalogued the whole library on every scan.** Its "already
  known" list was keyed on one spelling and looked up with the other, so every
  file looked new every time. One film had **nine rows**, one per scan, growing
  for ever (S-98).
- **`LibraryOrganizer` stored `media\library\…`** while everything else stored
  `media/library/…`, which is the other end of the same mismatch (S-86).
- **`ConversionFiler`'s guard against flattening an organised library did
  nothing.** It compared with `DIRECTORY_SEPARATOR` against a `Storage::path()`
  that mixes both, decided the file was "outside the library", and moved it —
  a guard over real files, silently absent on one platform (S-86).
- **A transfer wrote a mirror of the source's filesystem inside the library**,
  because an absolute path from another machine was joined onto the local
  storage root (S-91).

**Why:** three of the four had been failing as tests for weeks and were read as
a path-separator quibble in the assertions. They were describing real bugs.
`DIRECTORY_SEPARATOR` looks like the careful, portable choice and is exactly
what breaks, because the strings being compared are not consistently native.

**How to apply:** `str_replace('\\', '/', $path)` on both sides before any
comparison, `str_starts_with`, or map key. Store relative paths forward-slashed
whatever the platform. Fold case only where the filesystem ignores it — on
Linux `Song.mp3` and `song.mp3` are two files and folding merges them. Never
trust a remote path at all: reject `..` and anything absolute
([[soundchex-never-touch-db-without-permission]] is the same instinct applied
to data).

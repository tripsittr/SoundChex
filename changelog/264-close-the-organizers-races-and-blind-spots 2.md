# 264 — Close the organizer's races and blind spots

**Merged** 2026-10-05 · **Issues** #462

Three ways filing a file could go wrong quietly: a corrupt copy taken for a
good one, a failed cleanup nobody heard about, and two workers claiming one
filename.

## What changed

### A cross-volume copy is verified by content, not by length

The copy fallback — used when `rename()` fails across filesystems, so an
external drive or a NAS — compared `filesize()` and then deleted the original.
A copy interrupted and resumed, or written to a failing disk, can be the right
length and the wrong bytes. The original was then deleted and the library held
a corrupt file as its only copy.

Size is still checked first, because it is free and rules out the common
truncation without hashing a 40 GB remux twice. When it matches, the content
hash decides.

### A failed cleanup is reported

The source `unlink()` after a verified copy was unchecked. When it fails the row
points at the new path while the old file is still on disk, and the next scan
catalogues it as a second item — the library grows a duplicate with nothing
anywhere saying why. It now logs, naming both paths.

### Choosing a filename and taking it is one step

`uniquePath()` found a free name and `rename()` took it, with nothing between
them. Two workers filing different recordings that want the same name would both
see it free, both pick it, and the second rename would replace the first
worker's file.

The two are now inside a `Cache::lock` keyed on the **target directory**, so
filing into different albums still runs in parallel. A worker that cannot get
the lock within 10s leaves the item for the next pass, which loses nothing.

### `uniquePath()` no longer returns a path that is taken

After 999 attempts it returned `$path` — the occupied path, that being the
reason it was called — handing the caller something it would then overwrite. It
now throws, and the organizer logs and refuses. A thousand same-named files in
one folder is a real problem worth surfacing, not something to paper over by
destroying one of them.

## Worth knowing

- No migration.
- **Filing is now slower across volumes**, by one hash of the copy. Within a
  volume `rename()` is still used and nothing changed.
- The lock needs a working cache. On this deployment that is the database
  store, which is fine; a `null` cache driver would make `block()` throw and
  filing would defer forever rather than race — the safe direction, but worth
  knowing if filing silently stops.
- The remaining crash window from the audit is **not** closed here: a crash
  between `rename()` and the row update still leaves the row on the old path,
  and the sweep marks it `file_missing`. Closing that needs the `file_moves`
  journal, which is #465.

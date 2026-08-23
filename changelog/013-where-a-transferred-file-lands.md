# 013 — Where a transferred file is allowed to land

**Merged** pending · **Issues** S-91

The file copy started, and 31 files in, this is where they were going:

```
C:\Users\blaze\…\storage\app\private\Users\tripsittr\Documents\GitHub\SoundChex\storage\app\private\media\unsorted\…
```

The transfer was paused at 31 files rather than 8,309.

## What changed

### The manifest sends a path that means something on the other machine

`SourceController::manifest()` sent `$item->file_path`, with a comment saying
it was relative "so the receiver files it under its own root". On the source it
is **absolute**. The receiver joined it onto its own storage root and began
rebuilding the sender's filesystem inside its media folder.

The path is now derived from the storage root rather than trusted. A file that
lives outside that root has no relative location to offer, so it is left out
and logged, rather than invented.

### The receiver stops trusting a remote path

It wrote wherever it was told. That handed a remote machine the choice of where
to write a file on this one: `../../` walks out of the media folder entirely.
Nothing about the earlier bug was hostile, but the same door was open to
something that was.

Paths are now refused unless they land under the media root. A source running
older code still sends its own absolute path, so the media root is recovered
from it where possible — the alternative is a transfer that cannot run until
both machines are updated, which is a poor answer to "your files went to the
wrong place".

### An imported catalogue is rewritten for the machine it lands on

This is the part that made the whole transfer pointless. The catalogue carries
the *source's* paths, so every row pointed at `/Users/…`.
`MediaItem::absoluteFilePath()` returns an absolute path as-is when readable
and null when not — and `/Users/…` is not readable on Windows — so **every item
in the imported library resolved to nothing**. The files could all have arrived
correctly and the catalogue would still not have found one of them.

The import rewrites them now. `library:relative-paths` does the same for a
catalogue imported before this existed: reports by default, `--apply` writes,
and it moves no files.

## Worth knowing

- **The two failures before the pause were not this.** `cURL error 28:
  Connection timed out after 10016ms` — the connect timeout, twice, out of 33
  attempts. Transient, and both files are still pending. Worth watching over a
  relayed tailnet; not worth changing on two samples.
- Fixing the manifest needs the **source** running this code. The receiver-side
  recovery is what makes the transfer work before that happens.

## Tests

**418 PHP · 87 Vitest.** 11 new, all passing.

Removing the receiver's path guard turns 9 of the 11 red. The refusals are the
point here and are tested hardest: `..` in the middle, `..` at the start, an
absolute path with no media root, a Windows absolute path, a UNC share, and an
empty string.

One bug found by the tests rather than after them: counting *kept* items
against the manifest total meant a single refused item left the loop asking for
the same page for ever, and it exhausted memory instead of finishing. It counts
what the source offered now.

**Still failing, and not from this change:** the same 6 failures and 1 error,
all S-86.

**What is unverified:** the corrected paths have not yet been through a live
transfer — the run that found this was paused before the fix existed.

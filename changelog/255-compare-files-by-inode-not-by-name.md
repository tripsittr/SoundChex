# 255 — Compare files by inode, not by name

**Merged** 2026-10-05 · **Issues** #454

A file whose only change was its capitalisation could be deleted while being
filed, leaving no copy. This compares files the way the filesystem does.

## What changed

### One file reached by two names is now recognised as one file

`LibraryOrganizer` decided whether a file was already in the right place by
comparing the source and target paths as *strings*. On a case-insensitive
volume — the default on macOS and Windows — `03 Chicago.mp3` and
`03 CHICAGO.mp3` are different strings naming one file, and re-casing a title
just before filing produces exactly that pair.

So the organizer concluded the file was not yet filed, and reached the branch
that handles "something is already at the target". That branch hashed both
paths, found the hashes equal — *because they were one file* — decided the
source was a redundant byte-identical copy, and unlinked it. The copy it
deleted was the only one.

`DuplicateDetector::merge()` and `resolveKeeping()` had the same flaw: both
have a "two rows, one file" branch guarded by a string compare, and both fall
through to a delete when it misses.

All three now ask the filesystem instead, through a new `FileIdentity`:

- `FileIdentity::same()` — are these two paths one file?
- `FileIdentity::sameInode()` — one file reached by two spellings?

Identity is device + inode. On Windows, where PHP reports inode 0 for every
file, it falls back to comparing resolved real paths case-insensitively —
device+inode there would call *every* pair identical, which for a check that
gates deletes would be far worse than the bug being fixed.

**So on Windows this is not inode safety, and the title overstates it there.**
PHP gives no inode to compare, so identity is `realpath()` + a
case-insensitive string compare. That is strictly better than the original bug
— it normalises case, `.`, `..` and symlinked parents, which is exactly the
class of collision that deleted a file — but it is still path-based, and
Windows is a primary target platform. Two genuinely distinct files that
`realpath()` resolves to one string would still be read as one file.

What makes that acceptable rather than merely better is #464, in this same
phase: a delete is a move to the trash. A wrong identity call on Windows now
costs a file being trashed instead of destroyed, recoverable for 30 days.
Closing the gap properly needs a Windows file id (`GetFileInformationByHandle`'s
`nFileIndexHigh`/`Low`), which PHP does not expose without FFI.

This also covers two cases the string compare never handled: a symlinked
library root, and a hardlink.

### A case-only rename is now performed, not treated as a duplicate

When the organizer finds the source and target are one file under two
spellings, it renames the file to the canonical spelling via a temporary name
in the same directory (`a.mp3` → `a.mp3.sc-tmp` → `A.mp3`), because a direct
rename between two spellings of one name is a no-op on some filesystems and an
error on others. Both steps stay within one directory, so neither can cross a
volume.

If either step fails the file keeps a valid name and the row is pointed at
whichever name exists, so the catalogue never points at nothing. The stored
`content_hash` is cleared on the path write, as rule 2 requires.

## Worth knowing

- No migration.
- The case-only tests skip themselves on a case-sensitive volume, where the
  premise cannot arise. They run on macOS and Windows, which is where the bug
  lives.
- Files already lost to this cannot be recovered. This stops it recurring; it
  does not undo it.
- `LIBRARY_AUTO_ORGANIZE=false` was set on the Mac as a stop-gap while this was
  unfixed (the admin toggle is inert — #456). It can go back to `true` once
  #455 and #456 also ship; until then the brake is still doing real work,
  because a wrong match can still move a file.

# 272 — Paths the filesystem will actually accept

**Merged** 2026-10-06 · **Issues** part of #468

Two ways a filename could be rejected by the filesystem, both measured before
being fixed.

## Truncation counted characters, not bytes

The limit filesystems impose per path component is **bytes** — 255 on ext4,
APFS and NTFS — and `mb_substr` counts characters. Measured:

```
CJK title: 150 chars / 450 bytes
after segment(): 120 chars / 360 bytes   <- past the 255-byte limit
```

So a CJK or emoji album name produced a segment the filesystem would refuse,
and the move failed with an error naming permissions rather than length.

`mb_strcut` now cuts to a byte budget without splitting a character in half,
and the result is re-trimmed — truncation can leave a trailing space or dot,
which Windows rejects for its own separate reason.

## Windows reserved names passed straight through

`CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9` and `LPT1`–`LPT9` are device names
reserved since DOS. A file called `NUL.mp3` **cannot be created on Windows at
all** — the call fails rather than producing a badly-named file — so a band
called AUX or a track called Con would simply never file, with the failure
logged as something else.

An underscore is appended rather than the name replaced, so the result still
reads as what it was: `CON` → `CON_`, `con.mp3` → `con_.mp3`.

Matched on the **stem**, with word boundaries, so `Concrete`, `Auxiliary` and
`Communion` are untouched — those three are in the test set precisely because a
looser pattern would catch them.

## Worth knowing

- Eight of the eleven new tests fail without the fix. The three that pass
  either way are the false-positive guards.
- Only `segment()` changed, so every existing path stays as it is. Nothing is
  renamed by this; it only affects paths computed from here on.

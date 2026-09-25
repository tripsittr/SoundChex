# 205 — A slash in a title no longer breaks the track

**Merged** 2026-09-25 · **Issues** S-393

"AM/PM" by `$uicideboy$` would not play and would not download. Nor would any
of the **33 tracks** in this library whose title contains a slash.

## What was wrong

The title goes into the `Content-Disposition` header as a filename, and
Symfony **refuses** a filename containing `/` or `\` — it throws rather than
escaping:

```
The filename and the fallback cannot contain the "/" and "\" characters.
```

So `response()->file()` raised before a single byte was sent. The client saw a
500 and reported the track as simply not playing, with nothing to suggest the
title was the cause.

Reproduced directly: item #1646 returned **HTTP 500** where a normal track
returned 200.

## What changed

`MediaItem::downloadFilename()` sanitises the title for the header, and both
stream routes use it — the API one and the web one.

Separators become a **dash** rather than being dropped: "AM/PM" reads as
"AM-PM", where "AMPM" reads as a typo. A title that sanitises to nothing falls
back to the item id, because an empty filename is its own kind of broken.

Control characters are stripped in the same pass.

## Worth knowing

- This is the same class of bug the header was already hardened against for
  CRLF injection — a different character, the same untrusted source. The
  comment there said newlines could not inject a header, which was true and
  incomplete.
- No data change: the stored title keeps its slash. Only the header is
  sanitised, so the track still reads "AM/PM" everywhere it is displayed.

## Still wrong

Nothing found here.

## Tests

PHP · `HeaderInjectionTest` 7/7, four new: a slash does not break the stream,
nor a backslash, the filename stays readable as "AM-PM", and a title that
sanitises to nothing still has a filename. Verified all four fail when the fix
is reverted.

Related suites 30/30.

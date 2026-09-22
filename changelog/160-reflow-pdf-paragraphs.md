# 160 — Book pages read as flowing paragraphs, not ragged lines

PDF books read jumbled. `pdftotext` keeps every visual line of the PDF as its
own line, so a narrow column arrived pre-wrapped — "pursuing the / negative
generates the positive. The pain / you pursue in the gym" — and the reflowable
reader, which wraps the text itself, re-wrapped already-wrapped lines into a
ragged, jumbled mess. This affected every PDF, not one book.

## Changed

- **PDF text is unwrapped into paragraphs at extraction.** Soft-wrapped lines
  (a line that just continues the sentence) are joined with a space; a blank line
  is kept as a real paragraph break; a word hyphenated across a line break is
  rejoined ("some-\nthing" → "something"). The reader then wraps clean prose to
  its own width, at any font size, with no ragged breaks.
- EPUB is unaffected — its chapters already come as paragraphs.

## Testing

- `ReflowParagraphsTest` (4 pass): wrapped lines join into a paragraph; a blank
  line stays a break; hyphenated words rejoin; lines never glue together without
  a space.
- `OcrCleaningTest` and `ReaderContentApiTest` unaffected (19 pass together).
- Re-extracted the library's PDFs (The Hobbit, Thinking Fast and Slow, The Subtle
  Art, …) — pages now read as clean flowing paragraphs.

## Note

Existing books get the reflow when re-extracted; the library's PDFs were
re-extracted here. A rare PDF whose source text runs an attribution straight into
the next sentence with no space at all (a layout quirk, not a line break) is out
of scope — that is a missing space in the source, not a wrapping artifact.

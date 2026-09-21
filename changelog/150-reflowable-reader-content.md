# 150 — Books as reflowable text, parsed on the server (S-295)

Rather than each client parsing book files (and needing a zip library for EPUB),
the server extracts a book to structured text and serves it; every client then
renders a reflowable Kindle-style reader from that data, the same on iOS, Android
and web. This is server-side; the reader UI is a separate change.

## What it does

- **`BookTextExtractor`** reduces a book to ordered text units:
  - **PDF** — `pdftotext` pulls every page's embedded text in one pass; a page
    with no usable text is a scan, and its OCR (via the existing `OcrService`)
    fills in, so a **scanned book reads like any other**.
  - **EPUB** — read with PHP's built-in `ZipArchive` (**no new dependency**): the
    container's OPF spine gives the reading order, and each chapter's XHTML is
    stripped to plain text, with its first heading kept as the chapter title.
- **`book_contents`** stores the result (one row per chapter/page, in order),
  extracted once and cached.
- **`ExtractBookContentJob`** runs extraction in the background — a scanned book
  is minutes of OCR, too slow for a request.
- **`GET /api/v1/items/{item}/reader/content`** returns the ordered chapters when
  ready; if a book has not been extracted, it kicks off the job and answers
  `processing` (the reader polls), or `empty` for a book with no extractable text.

## Verified against the real library

The extractor was run against actual books: an EPUB ("MONEY Master the Game")
came out as 85 spine-ordered chapters; a PDF ("Anarchy Works") as 160 pages of
text. Both formats extract with no client dependency.

## Access

The content endpoint runs through the same `ContentGate` as the rest of the
reader — a capped profile gets a 404, not the text.

## Tests

`Api\ReaderContentApiTest` (4): processing kicks off the job, ready returns
ordered chapters, non-book 404s, and the extractor parses a real (in-memory)
EPUB into titled chapters. Reader/book/API suites green (79).

## Next

The reflowable reader UI (iOS first) consumes this. The old per-format on-device
reader (PDFKit) can stay as a "view original pages" option or be replaced; that
is the UI change, not this one.

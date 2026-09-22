# 155 — The reader no longer hangs on "preparing this book"

Opening some books stuck on **"Preparing this book…"** forever. The cause was
the queue: a book the extractor judged *not* fast (needs OCR) was handed to a
background job and the reader polled for the result — but if no worker is
watching that queue (here the app's queue is `database` while the running
worker is Horizon on `redis`), the job never ran and the poll never ended.
Worse, the "is this fast?" check samples a few early pages, so a text book with
lots of front matter or an unusual text layer could be misjudged as a scan and
sent to that queue.

## Changed

- **A book's text is always extracted in the request, never gated on a
  heuristic.** A text PDF or EPUB comes back `ready` in a fraction of a second.
  Opening a book no longer depends on a background worker at all.
- **OCR stays on the queue**, but only genuine scans reach it: if the in-request
  text pass finds nothing and the book has page images, those are shown
  immediately (readable now) and OCR is queued to add selectable text later.
- **A book that can never be read settles as `empty`, not `processing`.** With no
  text, no images, and no OCR that could help (an EPUB, or a PDF with OCR off),
  the reader is told there is nothing rather than left polling a job that cannot
  produce anything.

This is the shared reader-content path, so the desktop/web reader benefits from
the same reliability.

## Testing

- `ReaderContentApiTest`: 10 pass, including a new test that a text book opens
  `ready` in-request with **no** `ExtractBookContentJob` queued, and that a
  no-text/no-OCR book settles `empty`.

## Note (environment)

The deeper trigger — the `reader` queue having no worker on this machine because
Horizon runs on `redis` while `QUEUE_CONNECTION=database` — is a runtime
mismatch, not fixed here. This change makes book-opening independent of it;
scanned-PDF OCR still needs a worker on the book queue to add selectable text.

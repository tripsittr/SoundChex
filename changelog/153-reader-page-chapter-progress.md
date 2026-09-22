# 153 — Reader: page, chapter and a live reading percentage

The reflowable reader put a bold **"Page N"** heading atop every page of text.
On a normal book that meant a "Page 1 / Page 2 / Page 3 …" ladder running down
the screen with the actual text between — it read as jumbled and out of order,
and the blank front-matter pages showed as empty "Page N" blocks with nothing
under them.

## Changed

- **No more per-page "Page N" headings.** A reflowed book flows continuously;
  page boundaries are not chapter titles. The extractor now titles a unit only
  when the page actually opens a chapter (e.g. "CHAPTER 1: Don't Try", "Part
  II"), detected from the page's first line — never mid-chapter prose.
- **Blank pages are dropped**, not emitted as empty units, so covers and section
  breaks no longer leave gaps in the read.
- **The source page rides with each unit.** `book_contents` gains a `page`
  column (the physical PDF page; null for EPUB, which has no fixed pages), and
  the reader content API carries it per chapter.
- **The top now shows where you are.** Under the book title, the reader shows
  the current **page**, the current **chapter** (carried forward from the last
  heading so a mid-chapter page still names it), and a live **reading
  percentage** — "Page 42 · Chapter 3: Don't Try · 18%".
- **The percentage tracks the actual scroll position**, not just which chapter
  is on screen, so it moves smoothly as you read (iOS 18+ via scroll geometry;
  iOS 17 falls back to chapter-based progress). Progress is saved as it changes,
  keeping the resume point and the percent up to date.

## Self-healing

Books already extracted under the old "Page N" scheme (no page numbers) are
re-extracted once on next open — a PDF whose cached rows carry no page is
treated as stale. No mass reprocessing; each book fixes itself when opened.

## Testing

- `ReaderContentApiTest`: 9 pass, including the new `page` field on chapters and
  EPUB units having a null page.
- Chapter-heading detection verified: real headings caught, prose ignored.
- iOS build succeeds.

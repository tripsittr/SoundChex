# 159 — Stop OCR gibberish landing in books

A book's decorative pages read as garbage. The Hobbit's title page came through
as `THE HOBBIT J.R.R. TOLKIEN` mixed with `XSite iPh NY BRID Bh
REPMRM-BSX-BRAN` and `ee ———X—_=_[_.__` — OCR reading the cover *artwork* as if
it were text. (281 of the book's 282 pages were already clean embedded text; only
the image-only cover was OCR'd, and only it was wrong.)

## Changed

- **Embedded text is trusted over OCR.** A page is only OCR'd when it has
  essentially no embedded text at all (a true image page), so a title or part
  page that carries a little real text keeps it instead of being OCR'd over.
- **OCR output is cleaned line by line.** OCR of artwork mixes real lines ("THE
  HOBBIT") with gibberish read off the design. Each line is now judged on its
  own and the garbled ones dropped — a line is kept when it reads like language
  (mostly letters, made of real words), and dropped when it is symbol soup,
  vowelless fragments ("BRD", "BSX"), or mid-word-capital noise ("XSite", "iPh").
  The real title survives; the junk is gone.

## Answer to "are we grabbing PDF text, not just OCR?"

Yes — the extractor pulls each page's **embedded** text with `pdftotext` first and
only OCRs a page with no text layer. The Hobbit issue was a genuinely image-only
cover being OCR'd, and OCR mangling the artwork; both are addressed above.

## Testing

- `OcrCleaningTest` (5 pass): the Hobbit cover keeps its title and drops the
  gibberish; prose, headings and clean captions are untouched; all-symbol,
  vowelless and mixed-case-fragment lines are dropped.
- `ReaderContentApiTest` (10 pass) unaffected.
- Verified live: The Hobbit's page 1 now reads "THE HOBBIT / J.R.R. TOLKIEN".

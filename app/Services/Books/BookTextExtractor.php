<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Books;

use App\Models\BookContent;
use App\Models\MediaItem;
use App\Services\OcrService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Reduces a book to reflowable text units for the reader (S-295).
 *
 * The device does not parse the file; the server does, once, and stores the
 * result as ordered `BookContent` rows the reader stitches into a continuous
 * book. Two source formats:
 *
 *  - **PDF** — `pdftotext` pulls the embedded text of every page in one pass
 *    (pages arrive form-feed separated). A page with no usable embedded text is a
 *    scan; its OCR (via OcrService, already used by the web reader) fills in, so a
 *    scanned book reads like any other.
 *  - **EPUB** — a zip of XHTML. PHP's built-in ZipArchive reads it (no new
 *    dependency); the OPF spine gives the reading order, and each chapter's markup
 *    is stripped to plain text.
 *
 * Idempotent: extracting again replaces the book's rows in one transaction.
 */
class BookTextExtractor
{
    /**
     * Below this many characters of embedded text, a page is treated as having
     * no text layer and is a candidate for OCR. Used to *classify* a book as fast
     * (has a text layer) vs a scan.
     */
    private const EMBEDDED_TEXT_THRESHOLD = 24;

    /**
     * A page is only OCR'd when its embedded text is at or below this — i.e. it is
     * genuinely image-only. Kept deliberately low: a title or part page carries a
     * little real embedded text amid ornamental artwork, and OCR turns that
     * artwork into gibberish ("XSite iPh NY BRID Bh…"). Any real embedded text
     * means the page has a text layer, so we keep it rather than OCR over it
     * (S-305).
     */
    private const OCR_ONLY_BELOW = 3;

    public function __construct(private OcrService $ocr) {}

    /**
     * Whether a book already has *current* extracted content.
     *
     * False when it has none, and also when what it has predates the page-aware
     * extraction (S-298): a PDF whose cached rows carry no page number is from
     * the old "Page N"-titled scheme, so it re-extracts once on next open and
     * self-heals — no mass reprocessing needed. EPUB rows have no page by design,
     * so they are judged current as long as any row exists.
     */
    public function hasContent(MediaItem $item): bool
    {
        $rows = BookContent::where('media_item_id', $item->id);

        if (! $rows->exists()) {
            return false;
        }

        $path = $item->absoluteFilePath();
        $isPdf = $path !== null && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';

        // A PDF extracted under the old scheme has pages null throughout; re-run.
        if ($isPdf && ! (clone $rows)->whereNotNull('page')->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Whether a book can be extracted without OCR — a text PDF or an EPUB, which
     * take a fraction of a second and can run in the request. A scanned PDF
     * cannot: it needs OCR, which is slow and belongs on the queue.
     */
    public function isFast(MediaItem $item): bool
    {
        $path = $item->absoluteFilePath();

        if ($path === null) {
            return false;
        }

        $format = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($format === 'epub') {
            return true;
        }

        if ($format !== 'pdf') {
            return false;
        }

        // A PDF is fast when it has embedded text — i.e. it is not a scan needing
        // OCR. Sample several early pages, not just the first: a cover or title
        // page is often blank, which would wrongly mark an ordinary book as a
        // scan and send it to the (slow) OCR queue. Any page with real text means
        // the book has a text layer and extracts quickly.
        foreach ([1, 2, 3, 5, 8] as $page) {
            if ($this->ocr->embeddedTextLength($path, $page) >= self::EMBEDDED_TEXT_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts a book into ordered BookContent rows, replacing any it already
     * has. Returns the number of units written, or 0 when nothing could be read.
     *
     * `allowOcr` false skips the OCR fallback for scanned pages, so a text book
     * can be extracted quickly in a request; the scanned pages come back empty
     * and a later full run (on the queue) fills them.
     */
    public function extract(MediaItem $item, bool $allowOcr = true): int
    {
        $path = $item->absoluteFilePath();

        if ($path === null) {
            return 0;
        }

        $units = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => $this->fromPdf($item, $path, $allowOcr),
            'epub' => $this->fromEpub($path),
            default => [],
        };

        if ($units === []) {
            return 0;
        }

        return DB::transaction(function () use ($item, $units): int {
            BookContent::where('media_item_id', $item->id)->delete();

            foreach ($units as $index => $unit) {
                BookContent::create([
                    'media_item_id' => $item->id,
                    'position' => $index + 1,
                    'page' => $unit['page'] ?? null,
                    'title' => $unit['title'],
                    'text' => $unit['text'],
                ]);
            }

            return count($units);
        });
    }

    // MARK: - PDF

    /**
     * Every page's text — embedded where present, OCR where the page is a scan.
     *
     * Each unit carries the source `page` number (so the reader can show it) and,
     * when the page opens a chapter, that chapter's heading as `title`. It does
     * *not* title every page "Page N": a bold "Page N" atop every screen of a
     * reflowed book is noise, and reads as jumbled. Blank pages (covers, section
     * breaks) are dropped, not emitted as empty units.
     *
     * @return array<int, array{page: int, title: ?string, text: string}>
     */
    private function fromPdf(MediaItem $item, string $path, bool $allowOcr = true): array
    {
        $pages = $this->pdfPages($path);

        if ($pages === []) {
            return [];
        }

        $units = [];

        foreach ($pages as $number => $text) {
            $text = trim($text);
            $embeddedLength = mb_strlen(preg_replace('/\s+/', '', $text) ?? '');

            // OCR only a page that is genuinely image-only (essentially no
            // embedded text). A page with even a few real characters has a text
            // layer — a decorative title page, a part divider — and OCR-ing it
            // replaces real text with gibberish read off the artwork (S-305), so
            // we keep the embedded text instead. Skipped entirely in the fast,
            // in-request pass, which leaves scans for the queue.
            if ($allowOcr && $embeddedLength <= self::OCR_ONLY_BELOW && $this->ocr->isAvailable()) {
                $ocr = $this->cleanOcr((string) ($this->ocr->recognizePage($item, $number)?->text ?? ''));

                // Only take OCR when it actually produced usable text — after
                // dropping the garbled lines OCR reads off artwork (S-305), so a
                // bad OCR pass can never make a page worse than leaving it blank.
                if (mb_strlen($ocr) > $embeddedLength) {
                    $text = $ocr;
                }
            }

            // A blank page (a cover, a section break) contributes nothing to a
            // reflowed read — skip it rather than leave an empty unit that shows
            // as a gap. Its page number is simply not represented.
            if ($text === '') {
                continue;
            }

            $units[] = [
                'page' => $number,
                'title' => $this->chapterHeading($text),
                'text' => $text,
            ];
        }

        return $units;
    }

    /**
     * Cleans an OCR page, dropping the garbled lines OCR reads off artwork while
     * keeping the real text (S-305).
     *
     * OCR of a decorative cover mixes genuine lines ("THE HOBBIT", "J.R.R.
     * TOLKIEN") with gibberish read off the artwork ("XSite iPh NY BRID Bh
     * REPMRM-BSX-BRAN", "ee ———X—_=_[_.__"). Judging the page as a whole keeps or
     * drops both together; judging line by line keeps the title and removes the
     * junk. A line is kept when it reads like language: mostly letters, and made
     * of plausible words rather than symbol soup.
     */
    private function cleanOcr(string $text): string
    {
        $kept = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);

            if ($line === '' || $this->isGarbledLine($line)) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Whether a single OCR line is gibberish rather than language.
     *
     * Real lines are mostly letters and spaces and made of ordinary words; a
     * garbled line is short symbol-and-caps soup with stray punctuation.
     */
    private function isGarbledLine(string $line): bool
    {
        $length = mb_strlen($line);

        // The share of letters and spaces. A real line is high; artwork noise is
        // full of symbols and underscores.
        $letters = preg_match_all('/[\p{L}\s]/u', $line);

        if ($length > 0 && ($letters / $length) < 0.7) {
            return true;
        }

        // Tokens on the line, and how many read like real words (a run of letters
        // long enough, not a lone stray capital or a two-letter fragment).
        $tokens = preg_split('/\s+/', $line) ?: [];
        $tokens = array_filter($tokens, fn (string $t): bool => $t !== '');

        if ($tokens === []) {
            return true;
        }

        $wordish = array_filter($tokens, fn (string $t): bool => $this->looksLikeWord($t));

        // A line is language when at least half its tokens read like real words.
        // Otherwise it is gibberish — even if it is "letters", like the OCR of
        // artwork ("XSite iPh NY BRID Bh REPMRM-BSX-BRAN").
        return (count($wordish) / count($tokens)) < 0.5;
    }

    /**
     * Whether a token reads like an ordinary word rather than OCR noise.
     *
     * A real word (or a normal all-caps word like "THE") has a vowel and normal
     * casing. OCR gibberish off artwork is vowelless fragments ("BRD", "BSX") or
     * words with capitals in the middle ("XSite", "iPh", "REPMRM-BSX") — patterns
     * ordinary text does not have.
     */
    private function looksLikeWord(string $token): bool
    {
        // Strip surrounding punctuation (quotes, a trailing colon, a period).
        $token = trim($token, ".,;:!?\"'()[]—-");

        // Short tokens are fine as long as they are a real short word or initial.
        if (mb_strlen($token) <= 2) {
            return preg_match('/^(a|i|an|as|at|be|by|do|go|he|if|in|is|it|me|my|no|of|on|or|so|to|up|us|we)$/iu', $token) === 1
                || preg_match('/^\p{Lu}\.?$/u', $token) === 1; // an initial, e.g. "J"
        }

        // Must be letters (with an inner apostrophe/hyphen allowed) …
        if (preg_match('/^\p{L}[\p{L}\'-]*\p{L}$/u', $token) !== 1) {
            return false;
        }

        // … contain a vowel (English words do; "BRD", "BSX" do not) …
        if (preg_match('/[aeiouyAEIOUY]/u', $token) !== 1) {
            return false;
        }

        // … and not have a capital in the middle (XSite, iPh, REPMRM read wrong;
        // ALL-CAPS and Capitalised and lowercase are all fine).
        $isAllCaps = $token === mb_strtoupper($token);
        $isCapitalised = preg_match('/^\p{Lu}?[\p{Ll}\'-]+$/u', $token) === 1;

        return $isAllCaps || $isCapitalised;
    }

    /**
     * A chapter heading if the page opens one — e.g. "CHAPTER 1: Don't Try" or a
     * bare "Chapter Five" — so the reader can show which chapter is being read.
     * Null when the page is mid-chapter (most pages), so no false headings appear.
     */
    private function chapterHeading(string $text): ?string
    {
        // Look only at the first non-empty line: a chapter starts at the top of a
        // page, not buried in its body (where "chapter" may just be a word).
        $firstLine = trim(strtok($text, "\n") ?: '');

        if ($firstLine === '' || mb_strlen($firstLine) > 80) {
            return null;
        }

        if (preg_match('/^(chapter|part|book)\b[\s.:\-—]*(\d+|[ivxlcdm]+|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)?\b.*/iu', $firstLine)) {
            return $firstLine;
        }

        return null;
    }

    /**
     * A PDF's pages as [pageNumber => text], via one pdftotext pass. Pages are
     * emitted form-feed (\f) separated, which is how the boundaries are known.
     *
     * @return array<int, string>
     */
    private function pdfPages(string $path): array
    {
        $process = new Process([
            (string) config('ocr.pdftotext_path', 'pdftotext'),
            '-enc', 'UTF-8',
            $path,
            '-',
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $pages = [];
        foreach (explode("\f", $process->getOutput()) as $index => $text) {
            $pages[$index + 1] = $text;
        }

        return $pages;
    }

    // MARK: - EPUB

    /**
     * An EPUB's chapters in spine order, each stripped to plain text.
     *
     * EPUB has no fixed pages, so `page` is null; the reader shows the chapter.
     *
     * @return array<int, array{page: null, title: ?string, text: string}>
     */
    private function fromEpub(string $path): array
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $opfPath = $this->epubOpfPath($zip);

            if ($opfPath === null) {
                return [];
            }

            $opf = $zip->getFromName($opfPath);

            if ($opf === false) {
                return [];
            }

            $baseDir = trim(dirname($opfPath), '.');
            $baseDir = $baseDir === '' ? '' : $baseDir.'/';

            $units = [];

            foreach ($this->epubSpineHrefs($opf) as $href) {
                $content = $zip->getFromName($baseDir.$href);

                if ($content === false || $content === '') {
                    continue;
                }

                $text = $this->htmlToText($content);

                if ($text === '') {
                    continue;
                }

                $units[] = [
                    'page' => null,
                    'title' => $this->firstHeading($content),
                    'text' => $text,
                ];
            }

            return $units;
        } finally {
            $zip->close();
        }
    }

    /**
     * The OPF path from the EPUB's container.xml (its manifest lives there).
     */
    private function epubOpfPath(\ZipArchive $zip): ?string
    {
        $container = $zip->getFromName('META-INF/container.xml');

        if ($container === false) {
            return null;
        }

        return preg_match('/full-path="([^"]+)"/', $container, $match)
            ? $match[1]
            : null;
    }

    /**
     * The spine's document hrefs, in reading order.
     *
     * @return array<int, string>
     */
    private function epubSpineHrefs(string $opf): array
    {
        // manifest: id => href
        $manifest = [];
        if (preg_match_all('/<item\b[^>]*\bid="([^"]+)"[^>]*\bhref="([^"]+)"/i', $opf, $items, PREG_SET_ORDER)) {
            foreach ($items as $item) {
                $manifest[$item[1]] = urldecode($item[2]);
            }
        }
        // Some files order the attributes href-before-id; catch those too.
        if (preg_match_all('/<item\b[^>]*\bhref="([^"]+)"[^>]*\bid="([^"]+)"/i', $opf, $items, PREG_SET_ORDER)) {
            foreach ($items as $item) {
                $manifest[$item[2]] ??= urldecode($item[1]);
            }
        }

        $hrefs = [];
        if (preg_match_all('/<itemref\b[^>]*\bidref="([^"]+)"/i', $opf, $refs, PREG_SET_ORDER)) {
            foreach ($refs as $ref) {
                if (isset($manifest[$ref[1]])) {
                    $hrefs[] = $manifest[$ref[1]];
                }
            }
        }

        return $hrefs;
    }

    /**
     * A chapter's first heading, for its title in the reader's chapter list.
     */
    private function firstHeading(string $html): ?string
    {
        if (preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $match)) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5));

            return $title === '' ? null : mb_substr($title, 0, 200);
        }

        return null;
    }

    /**
     * XHTML to readable plain text: drop scripts/styles, turn block ends into
     * line breaks, strip the rest, and collapse whitespace.
     */
    private function htmlToText(string $html): string
    {
        // The body only — skip head metadata.
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $match)) {
            $html = $match[1];
        }

        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        // Paragraph and break boundaries become newlines so the text stays
        // readable rather than running together.
        $html = preg_replace('/<\/(p|div|h[1-6]|li|br)\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        // Collapse runs of blank lines and trailing spaces.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

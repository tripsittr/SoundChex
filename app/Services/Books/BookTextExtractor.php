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
    /** A scan is a page whose embedded text is shorter than this. */
    private const EMBEDDED_TEXT_THRESHOLD = 24;

    public function __construct(private OcrService $ocr) {}

    /**
     * Whether a book already has extracted content.
     */
    public function hasContent(MediaItem $item): bool
    {
        return BookContent::where('media_item_id', $item->id)->exists();
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
     * @return array<int, array{title: ?string, text: string}>
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

            // A scan: no usable embedded text. Fall back to OCR, which the reader
            // already uses for these on the web — unless this is the fast,
            // in-request pass, which leaves the scanned pages for the queue.
            if ($allowOcr
                && mb_strlen(preg_replace('/\s+/', '', $text) ?? '') < self::EMBEDDED_TEXT_THRESHOLD) {
                $ocr = $this->ocr->isAvailable()
                    ? $this->ocr->recognizePage($item, $number)?->text
                    : null;
                $text = trim((string) ($ocr ?? $text));
            }

            $units[] = [
                'title' => 'Page '.$number,
                'text' => $text,
            ];
        }

        // Drop trailing blank pages so the reader does not end on emptiness.
        while (! empty($units) && $units[count($units) - 1]['text'] === '') {
            array_pop($units);
        }

        return $units;
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
     * @return array<int, array{title: ?string, text: string}>
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

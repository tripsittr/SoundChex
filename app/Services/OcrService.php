<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use App\Models\PageText;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Optical character recognition for scanned book pages.
 *
 * A scanned PDF is a picture of a page: there is no text to select, search, or
 * highlight. This rasterises the page and runs Tesseract over it, storing both
 * the reading text and per-word boxes — the boxes are what let the reader lay
 * a real, selectable text layer over the image, so a scan behaves like any
 * other page.
 *
 * Requires `tesseract` and `pdftoppm` (poppler). Both are checked for rather
 * than assumed; without them OCR reports itself unavailable instead of failing
 * page by page.
 */
class OcrService
{
    /**
     * Pages with at least this many characters of embedded text are left
     * alone. A handful of characters usually means a page number and a running
     * head over a scanned body, which still needs recognising.
     */
    private const EMBEDDED_TEXT_THRESHOLD = 100;

    /**
     * Rasterisation resolution. 300dpi is the usual floor for reliable
     * recognition of body text; below about 200 accuracy falls off sharply.
     */
    private const DPI = 300;

    /** A single page should never take this long; something has gone wrong. */
    private const PAGE_TIMEOUT_SECONDS = 120;

    public function __construct(private LibrarySettings $settings) {}

    /**
     * Whether the host has the binaries this needs.
     */
    public function isAvailable(): bool
    {
        return $this->binaryExists($this->tesseractPath())
            && $this->binaryExists($this->pdftoppmPath());
    }

    /**
     * @return array<int, string> Language codes Tesseract has data for.
     */
    public function availableLanguages(): array
    {
        $process = new Process([$this->tesseractPath(), '--list-langs']);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];

        // The first line is a header ("List of available languages…"); the
        // rest are codes, minus the orientation/script helpers which aren't
        // languages anyone reads in.
        return array_values(array_filter(
            array_slice($lines, 1),
            fn (string $line): bool => $line !== '' && ! in_array($line, ['osd', 'snum'], true),
        ));
    }

    /**
     * Pages in a PDF, or null when it can't be read.
     */
    public function pageCount(string $pdfPath): ?int
    {
        $process = new Process([$this->pdfinfoPath(), $pdfPath]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $match)
            ? (int) $match[1]
            : null;
    }

    /**
     * Characters of embedded text on a page.
     *
     * This is what decides whether a page needs OCR at all. Running it over a
     * page that already has text would be slower and less accurate than the
     * text the publisher embedded.
     */
    public function embeddedTextLength(string $pdfPath, int $page): int
    {
        $process = new Process([
            $this->pdftotextPath(),
            '-f', (string) $page,
            '-l', (string) $page,
            $pdfPath,
            '-',
        ]);

        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return 0;
        }

        return strlen(preg_replace('/\s+/', '', $process->getOutput()) ?? '');
    }

    public function pageNeedsOcr(string $pdfPath, int $page): bool
    {
        return $this->embeddedTextLength($pdfPath, $page) < self::EMBEDDED_TEXT_THRESHOLD;
    }

    /**
     * Recognises one page and stores the result.
     *
     * Returns the stored row, or null when the page couldn't be processed.
     */
    public function recognizePage(MediaItem $item, int $page, bool $force = false): ?PageText
    {
        $pdfPath = $item->absoluteFilePath();

        if ($pdfPath === null || ! is_file($pdfPath)) {
            return null;
        }

        $existing = PageText::where('media_item_id', $item->id)->where('page', $page)->first();

        // Already done, and nothing has changed — recognition is deterministic,
        // so there is nothing to gain from running it again.
        if (! $force && $existing?->status === 'complete') {
            return $existing;
        }

        $row = $existing ?? new PageText([
            'media_item_id' => $item->id,
            'page' => $page,
        ]);

        // A page that already carries real text doesn't need recognising, and
        // saying so explicitly stops it being retried on every open.
        if (! $force && ! $this->pageNeedsOcr($pdfPath, $page)) {
            $row->fill(['status' => 'skipped', 'engine' => null])->save();

            return $row;
        }

        $row->fill(['status' => 'running'])->save();

        try {
            $result = $this->runOcr($pdfPath, $page);
        } catch (ProcessTimedOutException) {
            $row->fill(['status' => 'failed'])->save();

            return $row;
        } catch (\Throwable $e) {
            Log::warning('OCR failed', [
                'item' => $item->id,
                'page' => $page,
                'error' => $e->getMessage(),
            ]);

            $row->fill(['status' => 'failed'])->save();

            return $row;
        }

        if ($result === null) {
            $row->fill(['status' => 'failed'])->save();

            return $row;
        }

        // 'blank' is a finished answer, not a failure: the page was read and
        // there was nothing on it. Recorded distinctly so the reader can say
        // so rather than retrying and reporting an error.
        $row->fill([
            'text' => $result['text'],
            'words' => $result['words'],
            'confidence' => $result['confidence'],
            'status' => ($result['blank'] ?? false) ? 'blank' : 'complete',
            'engine' => 'tesseract',
        ])->save();

        return $row;
    }

    /**
     * Rasterise → recognise → parse.
     *
     * @return array{text: string, words: array<int, array<string, mixed>>, confidence: int}|null
     */
    private function runOcr(string $pdfPath, int $page): ?array
    {
        $workDir = $this->makeWorkDir();

        try {
            $image = $this->rasterize($pdfPath, $page, $workDir);

            if ($image === null) {
                return null;
            }

            return $this->recognize($image, $workDir);
        } finally {
            // Rasterised pages are large; they must not survive the request
            // whether it succeeded or not.
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Renders one PDF page to PNG.
     */
    private function rasterize(string $pdfPath, int $page, string $workDir): ?string
    {
        $prefix = $workDir . '/page';

        $process = new Process([
            $this->pdftoppmPath(),
            '-f', (string) $page,
            '-l', (string) $page,
            '-r', (string) self::DPI,
            '-png',
            // Anti-aliasing helps recognition on thin serif type.
            '-aa', 'yes',
            '-aaVector', 'yes',
            $pdfPath,
            $prefix,
        ]);

        $process->setTimeout(self::PAGE_TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        // pdftoppm appends a zero-padded page number whose width depends on
        // the document length, so the file is found rather than predicted.
        $matches = glob($prefix . '*.png') ?: [];

        return $matches[0] ?? null;
    }

    /**
     * Runs Tesseract and keeps whichever segmentation read the page best.
     *
     * Mode 1 asks Tesseract to detect orientation first. That works well on
     * body text but its orientation detector is unreliable on anything
     * stylised — on a book cover it reported near-zero confidence, decided the
     * script was Cyrillic, and flipped an upright page upside down, turning
     * "MARK MANSON" into "NOSNVW SUVW" at 56% confidence. The same page reads
     * at 92% under mode 6.
     *
     * Neither mode is right for every page, so both are run and the more
     * confident result wins. A page is only rasterised once, so the extra pass
     * costs recognition time rather than rendering time.
     *
     * @return array{text: string, words: array<int, array<string, mixed>>, confidence: int}|null
     */
    private function recognize(string $imagePath, string $workDir): ?array
    {
        $best = null;

        // 6 first: it treats the page as a uniform block and is the safer
        // default. 1 adds orientation detection, which earns its place on a
        // genuinely rotated scan.
        foreach (['6', '1'] as $index => $psm) {
            $result = $this->recognizeWith($imagePath, $workDir . '/psm' . $psm, $psm);

            if ($result === null) {
                continue;
            }

            if ($best === null || $result['confidence'] > $best['confidence']) {
                $best = $result;
            }

            // A confident first read is not worth a second opinion.
            if ($index === 0 && $result['confidence'] >= 85) {
                break;
            }
        }

        return $best;
    }

    /**
     * One Tesseract pass at a given page-segmentation mode.
     *
     * @return array{text: string, words: array<int, array<string, mixed>>, confidence: int}|null
     */
    private function recognizeWith(string $imagePath, string $outputBase, string $psm): ?array
    {
        $process = new Process([
            $this->tesseractPath(),
            $imagePath,
            $outputBase,
            '-l', $this->language(),
            '--psm', $psm,
            'tsv',
        ]);

        $process->setTimeout(self::PAGE_TIMEOUT_SECONDS);
        $process->run();

        $tsvPath = $outputBase . '.tsv';

        if (! $process->isSuccessful() || ! is_file($tsvPath)) {
            return null;
        }

        return $this->parseTsv((string) file_get_contents($tsvPath));
    }

    /**
     * Turns Tesseract's TSV into word boxes in page fractions.
     *
     * Columns are: level, page_num, block_num, par_num, line_num, word_num,
     * left, top, width, height, conf, text.
     *
     * @return array{text: string, words: array<int, array<string, mixed>>, confidence: int}|null
     */
    private function parseTsv(string $tsv): ?array
    {
        $rows = preg_split('/\R/', trim($tsv)) ?: [];

        if (count($rows) < 2) {
            return null;
        }

        // Level 1 is the page box, which is where the pixel dimensions come
        // from. Without it the fractions can't be computed.
        $pageWidth = 0;
        $pageHeight = 0;

        $words = [];
        $confidences = [];
        $lines = [];

        foreach (array_slice($rows, 1) as $row) {
            $cols = explode("\t", $row);

            if (count($cols) < 12) {
                continue;
            }

            [$level, , , , $lineNum, , $left, $top, $width, $height, $conf] = $cols;
            $text = $cols[11];

            if ((int) $level === 1) {
                $pageWidth = (int) $width;
                $pageHeight = (int) $height;

                continue;
            }

            if (trim($text) === '') {
                continue;
            }

            $confidence = (float) $conf;

            // -1 marks a box Tesseract produced no reading for.
            if ($confidence < 0) {
                continue;
            }

            $confidences[] = $confidence;

            if ($pageWidth > 0 && $pageHeight > 0) {
                $words[] = [
                    't' => $text,
                    'x' => round((int) $left / $pageWidth, 5),
                    'y' => round((int) $top / $pageHeight, 5),
                    'w' => round((int) $width / $pageWidth, 5),
                    'h' => round((int) $height / $pageHeight, 5),
                    'c' => (int) round($confidence),
                ];
            }

            // Grouped by line so the reading text keeps its line breaks
            // instead of becoming one long run of words.
            $lines[$lineNum][] = $text;
        }

        $text = implode("\n", array_map(
            fn (array $parts): string => implode(' ', $parts),
            $lines,
        ));

        // A page with no words is usually blank — a section break, a plate
        // page, the back of a cover. That is a legitimate result, not a
        // failure, and reporting it as one made the reader retry it forever
        // and show an error over a page that simply has nothing on it.
        return [
            'text' => $text,
            'words' => $words,
            'confidence' => $confidences === []
                ? 0
                : (int) round(array_sum($confidences) / max(count($confidences), 1)),
            'blank' => $words === [],
        ];
    }

    private function language(): string
    {
        return $this->settings->ocrLanguage();
    }

    private function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/soundchex-ocr-' . bin2hex(random_bytes(6));

        mkdir($dir, 0700, true);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    private function binaryExists(string $path): bool
    {
        // An absolute path configured by the user is checked directly; a bare
        // name is resolved through the shell's lookup.
        if (str_contains($path, '/')) {
            return is_executable($path);
        }

        $process = new Process(['which', $path]);
        $process->run();

        return $process->isSuccessful();
    }

    private function tesseractPath(): string
    {
        return (string) config('ocr.tesseract_path', 'tesseract');
    }

    private function pdftoppmPath(): string
    {
        return (string) config('ocr.pdftoppm_path', 'pdftoppm');
    }

    private function pdftotextPath(): string
    {
        return (string) config('ocr.pdftotext_path', 'pdftotext');
    }

    private function pdfinfoPath(): string
    {
        return (string) config('ocr.pdfinfo_path', 'pdfinfo');
    }
}

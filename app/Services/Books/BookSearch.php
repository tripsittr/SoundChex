<?php

namespace App\Services\Books;

use App\Models\MediaItem;
use App\Models\PageText;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

/**
 * Search inside books.
 *
 * Reuses the page_texts table OCR already fills. The difference is coverage:
 * OCR only ever touches pages that need it, so pages with their own embedded
 * text were recorded as `skipped` with no text stored. Search needs all of it,
 * so indexing pulls the embedded text in too.
 */
class BookSearch
{
    private const PROCESS_TIMEOUT = 300;

    /** Characters of context shown either side of a hit. */
    private const SNIPPET_PADDING = 90;

    /**
     * Stores the text of every page so the book becomes searchable.
     *
     * Pages OCR has already recognised are left alone — that text is better
     * than anything re-extraction would produce, and redoing it would be slow.
     *
     * @return int Pages indexed.
     */
    public function index(MediaItem $item): int
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path)) {
            return 0;
        }

        $pages = $this->extractAllText($path);

        if ($pages === []) {
            return 0;
        }

        $existing = PageText::where('media_item_id', $item->id)
            ->get()
            ->keyBy('page');

        $indexed = 0;

        foreach ($pages as $page => $text) {
            $text = trim($text);

            if ($text === '') {
                continue;
            }

            $row = $existing->get($page);

            // A recognised page already holds better text than the embedded
            // layer would give — that's why it was recognised.
            if ($row !== null && in_array($row->status, ['complete', 'blank'], true)) {
                continue;
            }

            PageText::updateOrCreate(
                ['media_item_id' => $item->id, 'page' => $page],
                [
                    'text' => $text,
                    // 'skipped' is still correct: it means this page needs no
                    // OCR. Its text is now stored so search can reach it.
                    'status' => 'skipped',
                ],
            );

            $indexed++;
        }

        return $indexed;
    }

    /**
     * Finds a phrase inside one book.
     *
     * @return Collection<int, array{page: int, snippet: string, source: string}>
     */
    public function search(MediaItem $item, string $query, int $limit = 60): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        return PageText::query()
            ->where('media_item_id', $item->id)
            ->whereNotNull('text')
            // LIKE rather than a full-text index: SQLite's FTS needs a
            // separate virtual table, and a personal library is small enough
            // that a scan over a few hundred rows is instant.
            ->where('text', 'like', '%' . $this->escapeLike($query) . '%')
            ->orderBy('page')
            ->limit($limit)
            ->get(['page', 'text', 'status', 'confidence'])
            ->map(fn (PageText $row): array => [
                'page' => $row->page,
                'snippet' => $this->snippet((string) $row->text, $query),
                // Recognised text can be wrong, so a hit from a scan says so.
                'source' => $row->status === 'complete' ? 'ocr' : 'text',
                'confidence' => $row->confidence,
            ])
            ->values();
    }

    /**
     * Searches every book at once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function searchAll(string $query, int $limit = 40): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        return PageText::query()
            ->whereNotNull('text')
            ->where('text', 'like', '%' . $this->escapeLike($query) . '%')
            ->with('mediaItem:id,title,cover_image_url')
            ->orderBy('media_item_id')
            ->orderBy('page')
            ->limit($limit)
            ->get()
            ->filter(fn (PageText $row): bool => $row->mediaItem !== null)
            ->map(fn (PageText $row): array => [
                'item_id' => $row->media_item_id,
                'title' => $row->mediaItem->title,
                'page' => $row->page,
                'snippet' => $this->snippet((string) $row->text, $query),
            ])
            ->values();
    }

    /**
     * Pulls the text of every page in one pass.
     *
     * `-bbox-layout` is deliberately not used: it would let us keep column
     * order, but it's far slower and search doesn't care about layout.
     *
     * @return array<int, string> Keyed by page number.
     */
    private function extractAllText(string $pdfPath): array
    {
        $process = new Process([
            $this->pdftotextPath(),
            // Preserves the reading order of the original layout, which keeps
            // a two-column page from interleaving into nonsense.
            '-layout',
            $pdfPath,
            '-',
        ]);

        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        // pdftotext separates pages with a form feed.
        $pages = explode("\f", $process->getOutput());

        $result = [];

        foreach ($pages as $index => $text) {
            $result[$index + 1] = $text;
        }

        return $result;
    }

    /**
     * A readable fragment around the first match, with the term marked.
     */
    private function snippet(string $text, string $query): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $position = mb_stripos($text, $query);

        if ($position === false) {
            return mb_substr($text, 0, self::SNIPPET_PADDING * 2) . '…';
        }

        $start = max(0, $position - self::SNIPPET_PADDING);
        $length = mb_strlen($query) + (self::SNIPPET_PADDING * 2);

        $snippet = mb_substr($text, $start, $length);

        if ($start > 0) {
            $snippet = '…' . $snippet;
        }

        if ($start + $length < mb_strlen($text)) {
            $snippet .= '…';
        }

        // Marked with a sentinel rather than HTML: the caller escapes the
        // whole snippet before turning these into markup, so a book that
        // contains angle brackets can't inject anything.
        return preg_replace(
            '/(' . preg_quote($query, '/') . ')/iu',
            "\x02$1\x03",
            $snippet,
        ) ?? $snippet;
    }

    /** Escapes LIKE wildcards so a query containing % doesn't match everything. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function pdftotextPath(): string
    {
        return (string) config('ocr.pdftotext_path', 'pdftotext');
    }
}

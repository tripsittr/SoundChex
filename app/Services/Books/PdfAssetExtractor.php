<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Books;

use App\Models\BookAsset;
use App\Models\BookChapter;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Recovers a PDF's illustrations and chapter outline.
 *
 * A book file carries more than its text — maps, plates, diagrams, and the
 * outline the publisher built for navigation. All of it is already in the file
 * and none of it is otherwise reachable from the reader.
 *
 * Uses Poppler (`pdfimages`, `pdftohtml`), which is already required for OCR.
 */
class PdfAssetExtractor
{
    /**
     * Below this, an image is decoration: a rule, a bullet, a logo. Books are
     * full of them and a gallery of 13×13px fragments is worse than none.
     */
    private const MIN_DIMENSION = 200;

    /** Total pixels, so a wide-but-short banner is excluded too. */
    private const MIN_PIXELS = 90000;

    /**
     * A shape this extreme is a rule or a border, whatever its size.
     */
    private const MAX_ASPECT_RATIO = 8.0;

    private const PROCESS_TIMEOUT = 300;

    public function isAvailable(): bool
    {
        return $this->binaryExists($this->pdfimagesPath())
            && $this->binaryExists($this->pdftohtmlPath());
    }

    /**
     * Pulls out images and the outline.
     *
     * @return array{images: int, significant: int, chapters: int}
     */
    public function extract(MediaItem $item): array
    {
        $path = $item->absoluteFilePath();

        if ($path === null || ! is_file($path) || ! $this->isAvailable()) {
            return ['images' => 0, 'significant' => 0, 'chapters' => 0];
        }

        $images = $this->extractImages($item, $path);
        $chapters = $this->extractOutline($item, $path);

        return [
            'images' => count($images),
            'significant' => count(array_filter($images, fn (BookAsset $a): bool => $a->is_significant)),
            'chapters' => $chapters,
        ];
    }

    /**
     * Extracts embedded images and records the ones worth showing.
     *
     * @return array<int, BookAsset>
     */
    public function extractImages(MediaItem $item, string $pdfPath): array
    {
        $relativeDir = $this->assetDirectory($item);
        $absoluteDir = Storage::path($relativeDir);

        if (! is_dir($absoluteDir) && ! mkdir($absoluteDir, 0775, true) && ! is_dir($absoluteDir)) {
            return [];
        }

        // Re-extracting replaces: an image that is no longer in the file
        // shouldn't linger in the gallery.
        $this->clearDirectory($absoluteDir);
        BookAsset::where('media_item_id', $item->id)->delete();

        $listed = $this->listImages($pdfPath);

        if ($listed === []) {
            return [];
        }

        // -p writes the page number into each filename, which is the only way
        // to know afterwards which page an image came from. -j keeps JPEGs in
        // their original encoding rather than re-encoding to PPM, which would
        // balloon a 700KB photo into 6MB of raw pixels.
        $process = new Process([
            $this->pdfimagesPath(),
            '-p',
            '-j',
            '-png',
            $pdfPath,
            $absoluteDir . '/img',
        ]);

        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $assets = [];

        foreach (glob($absoluteDir . '/img-*') ?: [] as $file) {
            $asset = $this->recordImage($item, $relativeDir, $file);

            if ($asset !== null) {
                $assets[] = $asset;
            }
        }

        $this->promoteCover($item, $assets);

        return $assets;
    }

    /**
     * Reads the publisher's outline into a chapter list.
     *
     * pdftohtml emits it as nested <outline> elements with page numbers —
     * parsing the PDF object tree by hand would be far more fragile than
     * letting Poppler do it.
     */
    public function extractOutline(MediaItem $item, string $pdfPath): int
    {
        $process = new Process([
            $this->pdftohtmlPath(),
            '-stdout',
            '-xml',
            // One page is enough: the outline is document-level, and asking
            // for all of them would render the entire book to XML.
            '-f', '1',
            '-l', '1',
            $pdfPath,
        ]);

        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            return 0;
        }

        $entries = $this->parseOutline($process->getOutput());

        BookChapter::where('media_item_id', $item->id)->delete();

        foreach ($entries as $index => $entry) {
            BookChapter::create([
                'media_item_id' => $item->id,
                'title' => $entry['title'],
                'page' => $entry['page'],
                'depth' => $entry['depth'],
                'sort_order' => $index,
            ]);
        }

        return count($entries);
    }

    /**
     * @return array<int, array{title: string, page: int, depth: int}>
     */
    private function parseOutline(string $xml): array
    {
        if (! preg_match('#<outline>(.*)</outline>#s', $xml, $match)) {
            return [];
        }

        $entries = [];
        $depth = 0;

        // Walked as a token stream rather than parsed as a tree: the nesting
        // is only ever rendered as an indented list, and <outline> elements
        // here are not well-formed enough for a DOM parser to be reliable.
        preg_match_all(
            '#<(/?)outline>|<item\s+page="(\d+)"\s*>(.*?)</item>#s',
            $match[1],
            $tokens,
            PREG_SET_ORDER,
        );

        foreach ($tokens as $token) {
            if (($token[1] ?? '') === '/') {
                $depth = max(0, $depth - 1);

                continue;
            }

            if (str_starts_with($token[0], '<outline>')) {
                $depth++;

                continue;
            }

            $title = trim(html_entity_decode(
                strip_tags($token[3] ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ));

            if ($title === '') {
                continue;
            }

            $entries[] = [
                'title' => mb_substr($title, 0, 255),
                'page' => max(1, (int) ($token[2] ?? 1)),
                'depth' => min($depth, 4),
            ];
        }

        return $entries;
    }

    /**
     * Stores one extracted image, judging whether it's worth showing.
     */
    private function recordImage(MediaItem $item, string $relativeDir, string $file): ?BookAsset
    {
        $size = @getimagesize($file);

        if ($size === false) {
            @unlink($file);

            return null;
        }

        [$width, $height] = $size;

        // pdfimages names files "img-<page>-<index>.<ext>".
        preg_match('/img-(\d+)-/', basename($file), $match);
        $page = (int) ($match[1] ?? 1);

        $significant = $this->isSignificant($width, $height);

        // Fragments are discarded rather than stored and hidden — a book can
        // contain hundreds, and they'd be dead weight on disk forever.
        if (! $significant) {
            @unlink($file);

            return null;
        }

        return BookAsset::create([
            'media_item_id' => $item->id,
            'page' => max(1, $page),
            'path' => $relativeDir . '/' . basename($file),
            'width' => $width,
            'height' => $height,
            'bytes' => @filesize($file) ?: null,
            'format' => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            'is_significant' => true,
        ]);
    }

    /**
     * Whether an image is content rather than decoration.
     */
    private function isSignificant(int $width, int $height): bool
    {
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            return false;
        }

        if ($width * $height < self::MIN_PIXELS) {
            return false;
        }

        $ratio = max($width, $height) / max(1, min($width, $height));

        return $ratio <= self::MAX_ASPECT_RATIO;
    }

    /**
     * Uses the first page's largest image as the cover when there isn't one.
     *
     * Only when the item has no artwork already: a cover resolved from Open
     * Library or TMDB is a better likeness than whatever the file opens with.
     *
     * @param array<int, BookAsset> $assets
     */
    private function promoteCover(MediaItem $item, array $assets): void
    {
        if (filled($item->cover_image_url) || $assets === []) {
            return;
        }

        $candidates = array_filter($assets, fn (BookAsset $a): bool => $a->page <= 2);

        if ($candidates === []) {
            return;
        }

        usort(
            $candidates,
            fn (BookAsset $a, BookAsset $b): int => ($b->width * $b->height) <=> ($a->width * $a->height),
        );

        $cover = $candidates[0];

        // Portrait-ish, or it isn't a cover — a wide banner on page 1 is a
        // header image.
        if ($cover->width > $cover->height) {
            return;
        }

        $cover->forceFill(['is_cover' => true])->saveQuietly();

        $item->forceFill(['cover_image_url' => $cover->path])->saveQuietly();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listImages(string $pdfPath): array
    {
        $process = new Process([$this->pdfimagesPath(), '-list', $pdfPath]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];

        // Two header rows precede the data.
        return array_slice($lines, 2);
    }

    private function assetDirectory(MediaItem $item): string
    {
        return trim((string) config('books.asset_path', 'media/book-assets'), '/') . '/' . $item->id;
    }

    private function clearDirectory(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function binaryExists(string $path): bool
    {
        if (str_contains($path, '/')) {
            return is_executable($path);
        }

        $process = new Process(['which', $path]);
        $process->run();

        return $process->isSuccessful();
    }

    private function pdfimagesPath(): string
    {
        return (string) config('books.pdfimages_path', 'pdfimages');
    }

    private function pdftohtmlPath(): string
    {
        return (string) config('books.pdftohtml_path', 'pdftohtml');
    }
}

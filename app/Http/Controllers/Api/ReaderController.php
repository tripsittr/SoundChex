<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Enums\MediaItemType;
use App\Http\Controllers\Controller;
use App\Jobs\ExtractBookContentJob;
use App\Models\BookAsset;
use App\Models\MediaItem;
use App\Models\ReadingProgress;
use App\Services\Books\BookTextExtractor;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Reading books in the native app (S-161).
 *
 * The web reader is served through session-authed routes the app cannot reach;
 * this is the token-authed equivalent. It gives the app what a reader needs to
 * open a book and resume it: the book's format and resume point, the file bytes
 * to render, a place to save progress, and — for the reflowable Kindle-style
 * reader (S-295) — the book as ordered text the device renders itself. Every
 * access passes the same ContentGate the rest of the library uses.
 */
class ReaderController extends Controller
{
    private const READABLE = ['epub', 'pdf', 'cbz', 'cbr'];

    /**
     * A book's format and where reading was left off, so the app can open it to
     * the right place.
     */
    public function show(MediaItem $item): JsonResponse
    {
        $this->assertReadable($item);

        $progress = $item->progressFor();

        return response()->json([
            'id' => $item->id,
            'title' => $item->title,
            'format' => $this->format($item),
            'fileUrl' => route('api.items.book', $item),
            'progress' => $progress === null ? null : [
                'location' => $progress->location,
                'percent' => $progress->percent,
                'finished' => (bool) $progress->finished,
            ],
        ]);
    }

    /**
     * A book as reflowable text — ordered chapters/pages the reader renders as a
     * continuous, resizable book (S-295), the same on every platform.
     *
     * If the book has not been extracted yet, this kicks off extraction in the
     * background and answers `processing`; the reader polls until it is `ready`.
     * A book with no extractable text (an image-only PDF with OCR unavailable)
     * settles as `empty` so the reader stops asking.
     */
    public function content(MediaItem $item): JsonResponse
    {
        $this->assertReadable($item);

        $extractor = app(BookTextExtractor::class);
        $images = $this->images($item);

        if (! $extractor->hasContent($item)) {
            // A text PDF or an EPUB extracts in a fraction of a second, so do it
            // now rather than making the reader wait on a queue. Crucially with
            // OCR *off*: a text book may still have a few blank scanned pages
            // (covers), and OCR-ing them in the request pushes it from ~0.4s to
            // several seconds — long enough that the app's poll times out and
            // loops forever. Those pages come back empty (they were blank); a
            // later queued run OCRs them if they turn out to hold anything.
            if ($extractor->isFast($item)) {
                $extractor->extract($item, allowOcr: false);
            } elseif (! empty($images)) {
                // A scanned book: its pages *are* the images, and they are already
                // extracted. Show them now — one page per image — so the book
                // opens straight away, and queue the (slow) OCR to add selectable
                // text to those pages on a later open.
                ExtractBookContentJob::dispatch($item->id);

                return response()->json([
                    'status' => 'ready',
                    'format' => $this->format($item),
                    'chapters' => collect($images)->map(fn (array $image): array => [
                        'position' => $image['page'],
                        'title' => null,
                        'text' => '',
                    ])->values(),
                    'images' => $images,
                ]);
            } else {
                // No text layer and no images to fall back on — OCR is the only
                // hope, on the queue.
                ExtractBookContentJob::dispatch($item->id);

                return response()->json(['status' => 'processing']);
            }
        }

        $units = $item->bookContents()
            ->orderBy('position')
            ->get(['position', 'title', 'text']);

        if ($units->isEmpty()) {
            // Text extraction produced nothing. If there are images, the book is
            // still readable as pages; otherwise there is nothing to show.
            if (empty($images)) {
                return response()->json(['status' => 'empty']);
            }

            $units = collect($images)->map(fn (array $image): object => (object) [
                'position' => $image['page'],
                'title' => null,
                'text' => '',
            ]);
        }

        return response()->json([
            'status' => 'ready',
            'format' => $this->format($item),
            'chapters' => $units->map(fn ($u): array => [
                'position' => $u->position,
                'title' => $u->title,
                'text' => $u->text,
            ])->values(),
            // The book's illustrations, keyed to the page they sit on, so the
            // reader can place each one inline with that page's text — the same
            // text-and-images read the desktop reader gives (S-295). This is what
            // carries a scanned or illustrated book (its pages are images).
            'images' => $images,
        ]);
    }

    /**
     * The significant images of a book, keyed by page, for inline placement.
     *
     * @return array<int, array{page: int, url: string, width: ?int, height: ?int}>
     */
    private function images(MediaItem $item): array
    {
        return BookAsset::where('media_item_id', $item->id)
            ->where('is_significant', true)
            ->orderBy('page')
            ->get()
            ->filter(fn (BookAsset $asset): bool => $asset->exists())
            ->map(fn (BookAsset $asset): array => [
                'page' => $asset->page,
                'url' => route('api.items.reader.asset', ['item' => $item, 'asset' => $asset]),
                'width' => $asset->width,
                'height' => $asset->height,
            ])
            ->values()
            ->all();
    }

    /**
     * One book image, for the reader to place inline. Token-authed equivalent of
     * the web reader's asset route.
     */
    public function asset(MediaItem $item, BookAsset $asset): Response
    {
        $this->assertReadable($item);

        abort_unless($asset->media_item_id === $item->id, 404);

        $path = $asset->absolutePath();

        abort_unless($path !== null, 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => match ($asset->format) {
                'png' => 'image/png',
                'tif', 'tiff' => 'image/tiff',
                default => 'image/jpeg',
            },
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * The book file itself, inline, for the reader to render.
     */
    public function file(MediaItem $item): BinaryFileResponse
    {
        $this->assertReadable($item);

        $path = $item->absoluteFilePath();

        abort_unless($path !== null, 404);

        return response()->file($path, [
            'Content-Type' => $this->mimeFor($this->format($item)),
            // A title carrying CRLF would otherwise inject a header; titles come
            // from file tags, not from here.
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                (string) $item->title,
                'book',
            ),
        ]);
    }

    /**
     * Saves the reading position — an opaque resume token (an EPUB CFI, a PDF
     * page, a comic index) and a percent — keyed to the current profile.
     *
     * A late-arriving offline write that is older than what is stored is refused
     * as stale, so replaying an old position cannot undo reading done since.
     */
    public function saveProgress(Request $request, MediaItem $item): JsonResponse
    {
        $this->assertReadable($item);

        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:512'],
            'percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $profileId = app(CurrentProfile::class)->id();

        $keys = $profileId !== null
            ? ['media_item_id' => $item->id, 'profile_id' => $profileId]
            : ['media_item_id' => $item->id, 'user_id' => Auth::id()];

        // Checked before the row is touched — creating it first would stamp
        // updated_at with "now" and make every queued write look stale.
        $existing = ReadingProgress::query()->where($keys)->first();

        $recordedAt = isset($data['recorded_at'])
            ? Carbon::parse($data['recorded_at'])
            : now();

        if ($existing?->updated_at !== null && $recordedAt->lt($existing->updated_at)) {
            return response()->json([
                'percent' => $existing->percent,
                'finished' => (bool) $existing->finished,
                'stale' => true,
            ]);
        }

        $progress = ReadingProgress::updateOrCreate($keys, [
            'user_id' => Auth::id(),
            'location' => $data['location'] ?? null,
            'percent' => $data['percent'] ?? 0,
            // 98% rather than 100: end matter means most books never report a
            // true 100.
            'finished' => ($data['percent'] ?? 0) >= 98,
        ]);

        return response()->json([
            'percent' => $progress->percent,
            'finished' => (bool) $progress->finished,
        ]);
    }

    /**
     * A book the current profile may read, in a format the reader handles.
     * 404 (not 403) for a gated item, so a cap does not confirm it exists.
     */
    private function assertReadable(MediaItem $item): void
    {
        abort_unless($item->type === MediaItemType::Book, 404);
        abort_unless(app(ContentGate::class)->allows($item), 404);
        abort_unless($item->hasReadableFile(), 404);
        abort_unless(in_array($this->format($item), self::READABLE, true), 404);
    }

    private function format(MediaItem $item): string
    {
        return strtolower(pathinfo((string) $item->file_path, PATHINFO_EXTENSION));
    }

    private function mimeFor(string $format): string
    {
        return match ($format) {
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'cbz' => 'application/vnd.comicbook+zip',
            'cbr' => 'application/vnd.comicbook-rar',
            default => 'application/octet-stream',
        };
    }
}

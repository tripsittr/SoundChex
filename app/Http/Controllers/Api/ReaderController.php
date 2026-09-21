<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Enums\MediaItemType;
use App\Http\Controllers\Controller;
use App\Jobs\ExtractBookContentJob;
use App\Models\MediaItem;
use App\Models\ReadingProgress;
use App\Services\Books\BookTextExtractor;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if (! $extractor->hasContent($item)) {
            // A text PDF or an EPUB extracts in a fraction of a second, so do it
            // now rather than making the reader wait on a queue that may be busy.
            // A scanned PDF needs OCR — slow — so that goes to the queue, and the
            // reader polls (the job is unique, so repeated polls don't pile up).
            if ($extractor->isFast($item)) {
                $extractor->extract($item);
            } else {
                ExtractBookContentJob::dispatch($item->id);

                return response()->json(['status' => 'processing']);
            }
        }

        $units = $item->bookContents()
            ->orderBy('position')
            ->get(['position', 'title', 'text']);

        if ($units->isEmpty()) {
            return response()->json(['status' => 'empty']);
        }

        return response()->json([
            'status' => 'ready',
            'format' => $this->format($item),
            'chapters' => $units->map(fn ($u): array => [
                'position' => $u->position,
                'title' => $u->title,
                'text' => $u->text,
            ])->values(),
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

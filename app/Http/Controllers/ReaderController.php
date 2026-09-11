<?php

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Jobs\OcrPageJob;
use App\Models\BookAsset;
use App\Models\BookChapter;
use App\Models\MediaItem;
use App\Models\PageText;
use App\Models\ReadingProgress;
use App\Services\Books\BookSearch;
use App\Services\CurrentProfile;
use App\Services\LibrarySettings;
use App\Services\OcrService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The in-app book reader.
 *
 * Files live on the private disk with no public URL, so both the reader page
 * and the file itself are served through here behind the auth middleware.
 */
class ReaderController extends Controller
{
    /**
     * Formats that render in the browser. Anything else is download-only —
     * MOBI and AZW3 have no browser renderer worth shipping.
     */
    private const READABLE = ['epub', 'pdf', 'cbz', 'cbr'];

    public function show(MediaItem $item): View
    {
        abort_unless($item->type === MediaItemType::Book, 404);
        abort_unless($item->hasReadableFile(), 404);

        $format = $this->format($item);

        abort_unless(in_array($format, self::READABLE, true), 404);

        return view('media.reader', [
            'item' => $item,
            'format' => $format,
            'progress' => $item->progressFor(),
            'counts' => [],
            // Highlighting is only wired up for formats with a text layer.
            // Comics are images; there is nothing to select.
            'supportsAnnotations' => in_array($format, ['pdf', 'epub'], true),
        ]);
    }

    /**
     * Streams the book file itself.
     *
     * Inline rather than as an attachment, so epub.js and the PDF viewer can
     * read it directly instead of triggering a download.
     */
    public function file(MediaItem $item): BinaryFileResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $path = $item->absoluteFilePath();

        abort_unless($path !== null, 404);

        // See MediaCenterController::stream() — a title carrying CRLF would
        // otherwise inject a header, and titles come from file tags and
        // metadata providers rather than from here.
        return response()->file($path, [
            'Content-Type' => $this->mimeFor($this->format($item)),
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                (string) $item->title,
                'book',
            ),
        ]);
    }

    /**
     * Saves where the reader got to.
     *
     * Called periodically from the browser, so it's deliberately cheap: one
     * upsert keyed on (book, user), no events, no validation beyond bounds.
     */
    public function saveProgress(Request $request, MediaItem $item): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $data = $request->validate([
            // Opaque resume token — an EPUB CFI, a PDF page, a comic index.
            'location' => ['nullable', 'string', 'max:512'],
            'percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            // When the reader recorded this, for writes that were queued
            // offline and are arriving late. See the staleness check below.
            'recorded_at' => ['nullable', 'date'],
        ]);

        // Keyed on the profile so two readers keep separate places; falls
        // back to the account for rows written before profiles existed.
        $profileId = app(CurrentProfile::class)->id();

        $keys = $profileId !== null
            ? ['media_item_id' => $item->id, 'profile_id' => $profileId]
            : ['media_item_id' => $item->id, 'user_id' => Auth::id()];

        // A queued write is older than it looks: someone reads three chapters
        // on a train, then opens the book on another device before the phone
        // reconnects. Replaying the train's last position would silently undo
        // reading that has already happened.
        //
        // Checked before the row is touched, not after — creating it first
        // stamps `updated_at` with "now" and makes every queued write look
        // stale against a row this same request just made.
        $existing = ReadingProgress::query()->where($keys)->first();

        $recordedAt = isset($data['recorded_at'])
            ? Carbon::parse($data['recorded_at'])
            : now();

        if ($existing?->updated_at !== null && $recordedAt->lt($existing->updated_at)) {
            return response()->json([
                'percent' => $existing->percent,
                'finished' => $existing->finished,
                'stale' => true,
            ]);
        }

        $progress = ReadingProgress::updateOrCreate(
            $keys,
            [
                'user_id' => Auth::id(),
                'location' => $data['location'] ?? null,
                'percent' => $data['percent'] ?? 0,
                // 98% rather than 100: end matter means most books never
                // report a true 100.
                'finished' => ($data['percent'] ?? 0) >= 98,
            ],
        );

        return response()->json([
            'percent' => $progress->percent,
            'finished' => $progress->finished,
        ]);
    }

    /**
     * Recognised text for one page of a scan.
     *
     * The reader asks for this when a page turns out to have no text of its
     * own. If it hasn't been recognised yet, recognition is queued and the
     * caller is told to come back — the alternative is holding the request
     * open for the several seconds OCR takes.
     */
    public function pageText(Request $request, MediaItem $item, int $page): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);
        abort_unless($page >= 1, 404);

        $row = PageText::where('media_item_id', $item->id)
            ->where('page', $page)
            ->first();

        if ($row?->status === 'complete') {
            return response()->json([
                'status' => 'complete',
                'text' => $row->text,
                'words' => $row->words,
                'confidence' => $row->confidence,
                'lowConfidence' => $row->isLowConfidence(),
            ]);
        }

        // Read, and there was nothing on it. A settled answer, so the reader
        // stops asking rather than retrying a blank page forever.
        if ($row?->status === 'blank') {
            return response()->json(['status' => 'blank']);
        }

        // This page has its own text; the reader should use pdf.js's layer.
        if ($row?->status === 'skipped') {
            return response()->json(['status' => 'skipped']);
        }

        if ($row?->status === 'failed') {
            return response()->json(['status' => 'failed']);
        }

        $ocr = app(OcrService::class);

        if (! $ocr->isAvailable()) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'OCR is not installed on this server.',
            ]);
        }

        if (! app(LibrarySettings::class)->ocrOnDemand()) {
            return response()->json(['status' => 'disabled']);
        }

        OcrPageJob::dispatch($item->id, $page);

        // Reading forward is the common case, so the next pages are queued
        // too — otherwise every page turn stalls waiting for recognition.
        $ahead = (int) config('ocr.prefetch_pages', 2);

        for ($offset = 1; $offset <= $ahead; $offset++) {
            OcrPageJob::dispatch($item->id, $page + $offset);
        }

        return response()->json(['status' => 'queued']);
    }

    /**
     * A continuous run of reading text, with the illustrations that belong in it.
     *
     * Text mode used to render one PDF page at a time, but a page of a typical
     * trade paperback holds barely a hundred words — a stub in a tall viewport,
     * and a page turn every few seconds. Stitching a range together makes it
     * read like a book instead of a slideshow.
     */
    public function readingText(Request $request, MediaItem $item): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $from = max(1, (int) $request->query('from', 1));
        $count = min(40, max(1, (int) $request->query('pages', 12)));
        $to = $from + $count - 1;

        $pages = PageText::query()
            ->where('media_item_id', $item->id)
            ->whereBetween('page', [$from, $to])
            ->whereNotNull('text')
            ->orderBy('page')
            ->get(['page', 'text', 'status']);

        $images = BookAsset::query()
            ->where('media_item_id', $item->id)
            ->where('is_significant', true)
            ->whereBetween('page', [$from, $to])
            ->orderBy('page')
            ->get()
            ->filter(fn (BookAsset $asset): bool => $asset->exists())
            ->groupBy('page');

        $total = $item->page_count
            ?? PageText::where('media_item_id', $item->id)->max('page');

        return response()->json([
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'hasMore' => $total !== null && $to < $total,
            'pages' => $pages->map(fn (PageText $row): array => [
                'page' => $row->page,
                'text' => $row->text,
                // Recognised text can be wrong; the reader marks the run
                // once rather than captioning every page.
                'ocr' => $row->status === 'complete',
                'images' => ($images[$row->page] ?? collect())
                    ->map(fn (BookAsset $asset): array => [
                        'url' => route('media.read.asset', ['item' => $item, 'asset' => $asset]),
                        'width' => $asset->width,
                        'height' => $asset->height,
                    ])
                    ->values(),
            ])->values(),
        ]);
    }

    /**
     * The book's illustrations and chapter outline.
     *
     * Loaded once when the reader opens the contents panel, rather than with
     * the page — most reading sessions never open it.
     */
    public function contents(MediaItem $item): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $assets = BookAsset::where('media_item_id', $item->id)
            ->where('is_significant', true)
            ->orderBy('page')
            ->get()
            ->filter(fn (BookAsset $asset): bool => $asset->exists())
            ->map(fn (BookAsset $asset): array => [
                'id' => $asset->id,
                'page' => $asset->page,
                'width' => $asset->width,
                'height' => $asset->height,
                'portrait' => $asset->isPortrait(),
                'url' => route('media.read.asset', ['item' => $item, 'asset' => $asset]),
            ])
            ->values();

        $chapters = BookChapter::where('media_item_id', $item->id)
            ->orderBy('sort_order')
            ->get(['title', 'page', 'depth']);

        return response()->json([
            'chapters' => $chapters,
            'images' => $assets,
        ]);
    }

    /**
     * Searches inside this book.
     */
    public function search(Request $request, MediaItem $item, BookSearch $search): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => [], 'query' => $query]);
        }

        return response()->json([
            'query' => $query,
            'results' => $search->search($item, $query),
        ]);
    }

    /**
     * Serves an extracted illustration.
     *
     * Read through the app because assets live on the private disk alongside
     * the book they came from.
     */
    public function asset(MediaItem $item, BookAsset $asset): Response
    {
        abort_unless($asset->media_item_id === $item->id, 404);

        $path = $asset->absolutePath();

        abort_unless($path !== null, 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => match ($asset->format) {
                'png' => 'image/png',
                'tif', 'tiff' => 'image/tiff',
                default => 'image/jpeg',
            },
            // Extracted once and never changed.
            'Cache-Control' => 'private, max-age=604800',
        ]);
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
            // Comics are zip/rar archives unpacked client-side.
            'cbz' => 'application/vnd.comicbook+zip',
            'cbr' => 'application/vnd.comicbook-rar',
            default => 'application/octet-stream',
        };
    }
}

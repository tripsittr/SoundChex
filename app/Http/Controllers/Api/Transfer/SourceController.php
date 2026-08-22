<?php

namespace App\Http\Controllers\Api\Transfer;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\TransferRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving a library to an approved receiver.
 *
 * Read-only, all of it. A transfer never writes to the source, so a mistake at
 * the receiving end cannot damage the machine being copied — which matters
 * when the thing being copied is the only copy.
 */
class SourceController extends Controller
{
    /**
     * What is here.
     *
     * Paginated because 8,315 rows is not one response, and ordered by id so
     * pagination is stable while the library carries on changing underneath.
     */
    public function manifest(Request $request): JsonResponse
    {
        $this->authorizeTransfer($request, 'metadata');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = 500;

        $query = MediaItem::query()
            ->whereNotNull('file_path')
            ->orderBy('id');

        $total = (clone $query)->count();

        $items = $query->forPage($page, $perPage)->get()
            ->map(function (MediaItem $item): ?array {
                $path = $item->absoluteFilePath();

                // A row whose file has gone is not offered. The receiver
                // cannot fetch it and would only record a failure it can do
                // nothing about.
                if ($path === null || ! is_file($path)) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'type' => $item->type->value,
                    'title' => $item->title,
                    // Relative, so the receiver files it under its own root
                    // rather than inheriting an absolute path from another
                    // machine — which on Windows would not even be valid.
                    'path' => $item->file_path,
                    'hash' => $item->content_hash,
                    'bytes' => filesize($path),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'items' => $items,
        ]);
    }

    /**
     * One file, resumable.
     *
     * Range support is what makes an interrupted 4 GB film continue at the
     * byte rather than starting again — Symfony handles the header, and this
     * only has to not get in its way.
     */
    public function file(Request $request, MediaItem $item): BinaryFileResponse
    {
        $this->authorizeTransfer($request, 'files');

        $path = $item->absoluteFilePath();

        abort_if($path === null || ! is_file($path), 404);

        return response()->file($path);
    }

    /**
     * The catalogue as a gzipped SQLite file.
     *
     * Measured: 24 MB becomes 3.6 MB, an 85% saving — which is why the
     * database is compressed and the media is not.
     */
    public function database(Request $request): StreamedResponse
    {
        $this->authorizeTransfer($request, 'metadata');

        $source = database_path('database.sqlite');

        abort_unless(is_file($source), 404);

        // Copied before reading, so a write part way through does not produce
        // a torn file. SQLite's own backup would be better; a copy of a WAL
        // database with a checkpoint first is close enough and needs no
        // extension.
        $snapshot = tempnam(sys_get_temp_dir(), 'soundchex-db-');

        \DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        copy($source, $snapshot);

        return response()->stream(function () use ($snapshot): void {
            $handle = gzopen('php://output', 'wb6');
            $in = fopen($snapshot, 'rb');

            while (! feof($in)) {
                gzwrite($handle, fread($in, 1024 * 512));
            }

            fclose($in);
            gzclose($handle);
            @unlink($snapshot);
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="soundchex.sqlite.gz"',
        ]);
    }

    /** Profiles, history and resume points — the part rescanning cannot rebuild. */
    public function profiles(Request $request): JsonResponse
    {
        $this->authorizeTransfer($request, 'profiles');

        return response()->json([
            'profiles' => Profile::with(['plays', 'watchlist'])->get(),
        ]);
    }

    /**
     * Whether this token belongs to a live request that asked for this.
     *
     * Checked per call rather than once at the start: a 46 GB transfer runs
     * for hours, and revoking it half way has to actually stop it.
     */
    private function authorizeTransfer(Request $request, string $want): void
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token?->can(TransferRequest::ABILITY), 403, 'Not a transfer token.');

        $transferRequest = TransferRequest::where('token_id', $token->id)->first();

        abort_unless($transferRequest?->isUsable(), 403, 'This transfer is no longer approved.');

        abort_unless(
            in_array($want, $transferRequest->wants ?? [], true),
            403,
            'This transfer did not ask for that.',
        );
    }
}

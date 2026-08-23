<?php

namespace App\Http\Controllers\Api\Transfer;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\TransferRequest;
use App\Services\CatalogueArchive;
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

                // Relative, so the receiver files it under its own root rather
                // than inheriting an absolute path from another machine.
                //
                // Derived rather than taken from `file_path`, which said this
                // was already relative and was not: a real transfer sent
                // `/Users/…/storage/app/private/media/…`, and the receiver
                // joined that onto its own root and began rebuilding the
                // source's entire filesystem inside its media folder.
                $relative = $this->relativePath($path);

                if ($relative === null) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'type' => $item->type->value,
                    'title' => $item->title,
                    'path' => $relative,
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
     * Where a file sits relative to this server's storage root.
     *
     * Null when it sits outside that root altogether — a watched folder
     * somewhere else — because there is then no relative location to offer and
     * inventing one would file it somewhere the receiver's catalogue does not
     * point. Logged rather than dropped silently: a file that is never offered
     * and never mentioned is the hardest kind of missing.
     */
    private function relativePath(string $absolute): ?string
    {
        $root = rtrim(str_replace('\\', '/', \Storage::path('')), '/') . '/';
        $normal = str_replace('\\', '/', $absolute);

        if (str_starts_with($normal, $root)) {
            return substr($normal, strlen($root));
        }

        \Log::warning('A file outside the storage root was left out of the manifest', [
            'path' => $absolute,
        ]);

        return null;
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

        // From the connection rather than a hardcoded path: an instance
        // configured to keep its database elsewhere would otherwise serve a
        // file it is not using, or none at all.
        $source = config('database.connections.' . config('database.default') . '.database');

        abort_unless(is_string($source) && is_file($source), 404);

        // Copied before reading, so a write part way through does not produce
        // a torn file. SQLite's own backup would be better; a copy of a WAL
        // database with a checkpoint first is close enough and needs no
        // extension.
        $snapshot = tempnam(sys_get_temp_dir(), 'soundchex-db-');

        // A full temp directory returns false here, and passing that on gives
        // a copy() failure whose message says nothing about the cause.
        abort_if($snapshot === false, 503, 'This server has nowhere to stage the catalogue.');

        \DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        copy($source, $snapshot);

        // Compressed to a file rather than streamed through gzopen().
        //
        // gzopen('php://output') fails with "could not make seekable" — the
        // handle needs to seek and output does not, so the first real transfer
        // got a 500 where the catalogue should have been. Compressing to disk
        // first costs a few seconds and a few megabytes, against a database
        // that shrinks 24 MB to 3.6.
        //
        // In CatalogueArchive rather than inline so the test for it calls this
        // code instead of a copy of it.
        $archive = app(CatalogueArchive::class)->compress($snapshot);

        @unlink($snapshot);

        return response()->stream(function () use ($archive): void {
            readfile($archive);
            @unlink($archive);
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Length' => (string) filesize($archive),
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

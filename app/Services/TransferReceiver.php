<?php

namespace App\Services;

use App\Models\MediaItem;
use App\Models\Transfer;
use App\Models\TransferItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pulling a library from another server.
 *
 * The receiver pulls rather than the source pushing: it knows what it already
 * has, so it decides what to ask for, which is what makes resuming cheap and
 * repeating free. It also controls its own disk and can stop when it is full.
 */
class TransferReceiver
{
    /**
     * The algorithm `content_hash` is stored in.
     *
     * xxh128 rather than sha256, and it has to match DuplicateDetector exactly
     * — verifying with a different algorithm would mark every file corrupt,
     * delete it, and report a transfer where nothing arrived.
     */
    public const HASH = 'xxh128';

    /** Asks a server for permission, and records what it said. */
    public function request(Transfer $transfer): bool
    {
        try {
            $response = $this->http()->acceptJson()->timeout(20)->post(
                $this->url($transfer, 'transfer/requests'),
                [
                    'device_name' => gethostname() ?: null,
                    'platform' => PHP_OS_FAMILY,
                    'wants' => $transfer->wants,
                ],
            );

            if (! $response->successful()) {
                $transfer->forceFill([
                    'state' => Transfer::FAILED,
                    'last_error' => 'The server refused the request (' . $response->status() . ').',
                ])->save();

                return false;
            }

            $transfer->forceFill([
                'remote_request_id' => $response->json('id'),
                'state' => Transfer::REQUESTED,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $transfer->forceFill([
                'state' => Transfer::FAILED,
                'last_error' => $this->explain($e),
            ])->save();

            return false;
        }
    }

    /**
     * Turns a connection failure into something worth reading.
     *
     * cURL's own messages are accurate and useless: "error 60: unable to get
     * local issuer certificate" is exactly right and says nothing about what
     * to do. It was the first thing a real transfer hit, and it reads as a
     * network problem when it is a missing file on the machine doing the
     * asking.
     */
    private function explain(\Throwable $error): string
    {
        $message = $error->getMessage();

        // Windows PHP ships with no CA bundle, so every outbound HTTPS request
        // fails this way until php.ini is pointed at one.
        if (str_contains($message, 'local issuer certificate')
            || str_contains($message, 'certificate verify failed')
            || str_contains($message, 'error 60')) {
            return 'This machine cannot verify certificates, so it cannot reach that '
                . 'server over HTTPS. PHP needs a CA bundle — set curl.cainfo and '
                . 'openssl.cafile in php.ini. See docs/SettingUpOnWindows.md.';
        }

        if (str_contains($message, 'Could not resolve host')) {
            return 'That address does not resolve. Check the name, and that this '
                . 'machine is on the same tailnet.';
        }

        if (str_contains($message, 'Connection refused')
            || str_contains($message, 'Failed to connect')) {
            return 'That server refused the connection. Check it is running and '
                . 'that the address includes the right port.';
        }

        if (str_contains($message, 'Operation timed out') || str_contains($message, 'timed out')) {
            return 'That server did not answer in time. It may be asleep, or the '
                . 'address may be reaching something else.';
        }

        // Not a network problem at all, and it reads like one. On Windows a
        // file unlinked while something still holds it open keeps its name in
        // a delete-pending state, and every later open of that name is refused
        // — so a transfer fails here having never left the machine.
        if (str_contains($message, 'Permission denied')
            || str_contains($message, 'Failed to open stream')) {
            return 'This machine could not write the file it downloads into. '
                . 'Something is still holding the previous one open — restart '
                . 'the queue worker, then try again.';
        }

        return 'Could not reach that server: ' . $message;
    }

    /**
     * Calls a transfer off, and tells the source it is over.
     *
     * Pausing leaves the request approved and the token live on the other
     * machine — fine for a lunch break, wrong for "I did not mean to start
     * this", because it leaves a machine able to read this one for as long as
     * the token lasts. Cancelling ends it at both ends.
     *
     * Stopped here first, then reported there. A source that cannot be
     * reached must not leave this machine still transferring: the local stop
     * is the one that matters, and the remote one is what makes it tidy.
     *
     * @return bool whether the source was told. False still means cancelled.
     */
    public function cancel(Transfer $transfer): bool
    {
        $token = $transfer->token;

        $transfer->forceFill([
            'state' => Transfer::CANCELLED,
            'token' => null,
            'finished_at' => now(),
        ])->save();

        // Never approved, so there is no token and nothing on the source to
        // end. It expires there on its own — four hours, unapproved and
        // unusable in the meantime.
        if (blank($token)) {
            Log::info('A transfer was cancelled before it was approved', [
                'transfer' => $transfer->id,
                'source' => $transfer->source_url,
            ]);

            return true;
        }

        try {
            $response = $this->http()->withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->delete($this->url($transfer, 'transfer/requests/mine'));

            // 401 and 403 mean the token is already dead — revoked from the
            // other end, or expired. The request is over either way, which is
            // what was being asked for.
            $told = $response->successful()
                || in_array($response->status(), [401, 403, 404], true);

            if (! $told) {
                $transfer->forceFill([
                    'last_error' => $this->truncate(
                        'Cancelled here, but that server answered ' . $response->status()
                        . ' and may still hold the request open.',
                    ),
                ])->save();

                Log::warning('A cancelled transfer could not be called off at the source', [
                    'transfer' => $transfer->id,
                    'source' => $transfer->source_url,
                    'status' => $response->status(),
                ]);
            }

            return $told;
        } catch (\Throwable $e) {
            $transfer->forceFill([
                'last_error' => $this->truncate(
                    'Cancelled here, but that server could not be told: ' . $this->explain($e),
                ),
            ])->save();

            Log::warning('A cancelled transfer could not be called off at the source', [
                'transfer' => $transfer->id,
                'source' => $transfer->source_url,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Ends a transfer whose permission has gone.
     *
     * Stopped rather than retried, because nothing about it is retryable: the
     * request has to be approved again on the other machine, by a person, and
     * no amount of asking changes that. The state and what is already copied
     * are left in place so resuming after a fresh approval picks up where this
     * stopped rather than starting over.
     */
    private function authorisationLost(Transfer $transfer, int $status): void
    {
        if ($transfer->state === Transfer::FAILED) {
            return;
        }

        $transfer->forceFill([
            'state' => Transfer::FAILED,
            'last_error' => $this->truncate(
                'That server answered ' . $status . ' — the transfer is no longer approved. '
                . 'Tokens last four hours, so it has most likely expired. Ask again and approve '
                . 'it on that machine; what has already copied is kept.',
            ),
        ])->save();

        Log::warning('A transfer lost its authorisation part way through', [
            'transfer' => $transfer->id,
            'source' => $transfer->source_url,
            'status' => $status,
        ]);
    }

    /**
     * Removes a transfer and everything it left behind.
     *
     * Refused while one is running rather than quietly stopping it: the row is
     * what queued file jobs read to decide whether to carry on, and deleting
     * it under them would leave them fetching into a transfer that no longer
     * exists. Clearing the list should not be a way to abandon a transfer half
     * way — cancelling is, and it is one button along.
     *
     * @return bool false when it was refused, so a caller can say why
     */
    public function discard(Transfer $transfer): bool
    {
        if ($transfer->state === Transfer::RUNNING) {
            return false;
        }

        // The part-downloaded catalogue for this transfer. Nothing will ever
        // come back for it, and it is 3 MB of the source's database.
        @unlink($this->archivePath($transfer));

        Log::info('A transfer was removed from the list', [
            'transfer' => $transfer->id,
            'source' => $transfer->source_url,
            'state' => $transfer->state,
        ]);

        // Explicitly as well as by cascade: the constraint is declared, and
        // SQLite only enforces it when foreign keys are switched on.
        $transfer->items()->delete();
        $transfer->delete();

        return true;
    }

    /**
     * Whether the request has been approved yet.
     *
     * Polled rather than pushed, because the source cannot reach back into a
     * machine that may be behind NAT — which is the usual case.
     */
    public function poll(Transfer $transfer): string
    {
        try {
            $response = $this->http()->acceptJson()->timeout(15)->get(
                $this->url($transfer, 'transfer/requests/' . $transfer->remote_request_id),
            );

            $state = $response->json('state', 'unknown');

            if ($state === 'approved' && $response->json('token')) {
                $transfer->forceFill([
                    'token' => $response->json('token'),
                    'state' => Transfer::APPROVED,
                ])->save();
            }

            if (in_array($state, ['denied', 'expired', 'revoked'], true)) {
                $transfer->forceFill([
                    'state' => Transfer::FAILED,
                    'last_error' => 'The other server ' . $state . ' this transfer.',
                ])->save();
            }

            return $state;
        } catch (\Throwable $e) {
            return 'unreachable';
        }
    }

    /**
     * Builds the work list from the source's manifest.
     *
     * Everything is written down before anything is fetched, so an interrupted
     * transfer resumes by asking the same question it asked at the start
     * rather than starting the conversation again.
     */
    public function buildManifest(Transfer $transfer): bool
    {
        $page = 1;
        $files = 0;

        // Every item the source offered, refused ones included. The loop below
        // stops when it has seen as many as the source said it had, and an
        // item skipped for an unusable path is still one the source counted —
        // measuring progress by what was *kept* means a single refusal asks
        // for the same page for ever.
        $seen = 0;
        $bytes = 0;

        do {
            $response = $this->get($transfer, 'transfer/manifest', ['page' => $page]);

            if ($response === null) {
                return false;
            }

            foreach ($response->json('items', []) as $item) {
                $seen++;

                $path = $this->safePath($item['path'] ?? '');

                // Not fetched rather than fetched somewhere else. A path this
                // machine cannot place safely is not a file it should be
                // writing at all.
                if ($path === null) {
                    Log::warning('A transfer item was skipped for an unusable path', [
                        'transfer' => $transfer->id,
                        'remote' => $item['id'] ?? null,
                        'path' => $item['path'] ?? null,
                    ]);

                    continue;
                }

                TransferItem::updateOrCreate(
                    ['transfer_id' => $transfer->id, 'remote_id' => $item['id']],
                    [
                        'path' => $path,
                        'expected_hash' => $item['hash'] ?? null,
                        'expected_bytes' => $item['bytes'] ?? 0,
                        'state' => TransferItem::PENDING,
                    ],
                );

                $files++;
                $bytes += $item['bytes'] ?? 0;
            }

            $total = (int) $response->json('total', 0);
            $page++;
        } while ($seen < $total && $response->json('items') !== []);

        $transfer->forceFill([
            'total_files' => $files,
            'total_bytes' => $bytes,
        ])->save();

        return true;
    }

    /**
     * Where a manifest entry may be written, or null if nowhere.
     *
     * The receiver writes whatever path the source sends, joined onto its own
     * storage root. That trusted a remote machine with the location of a file
     * on this one: `../../` walks out of the media folder, and an absolute
     * path rebuilds the sender's filesystem inside it — which is what a real
     * transfer did, 31 files into a mirror of `/Users/…/storage/app/private/`.
     *
     * A source running the current code sends a relative path already. One
     * running older code sends its own absolute path, and the media root is
     * the part of it that means anything here, so that much is recovered
     * rather than refused — the alternative is a transfer that cannot run
     * until both machines have been updated.
     */
    private function safePath(string $path): ?string
    {
        $normal = trim(str_replace('\\', '/', $path));

        // Before anything else. No amount of trimming makes `..` safe, and a
        // path is not worth rescuing if it was trying to leave.
        foreach (explode('/', $normal) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        // The media root is what both machines have in common.
        if (preg_match('#(?:^|/)(media/.+)$#', $normal, $match) === 1) {
            $normal = $match[1];
        }

        // Anything still absolute — a drive letter, a leading slash, a UNC
        // share — has no place under this machine's storage root.
        if ($normal === ''
            || str_starts_with($normal, '/')
            || preg_match('#^[A-Za-z]:#', $normal) === 1) {
            return null;
        }

        return $normal;
    }

    /**
     * Fetches one file, verifying before and after.
     *
     * Before, because a file already present with the right hash needs no
     * fetching — which is what makes a resumed transfer cheap and a repeated
     * one free. After, because a truncated file that looks present is worse
     * than one plainly absent.
     */
    /**
     * Tells the source how far this copy has got.
     *
     * The receiver is the only machine that knows, and the source had no way
     * to ask. Both guessed instead, and both were wrong — one read tailnet
     * byte counters that go quiet between files and called a running copy
     * stalled, the other reported a queue count it had reset by hand. See
     * docs/WorkingWithTwoAgents.md.
     *
     * Best-effort by design: this is a courtesy to the other end, and a
     * transfer that cannot report is still a transfer. Every failure is
     * swallowed after being logged, because a copy of 46 GB should not stop
     * over a status update.
     */
    public function reportProgress(Transfer $transfer): void
    {
        if ($transfer->token === null) {
            return;
        }

        $progress = $transfer->progress();
        $total = $transfer->items()->count();

        try {
            $this->http()->withToken($transfer->token)
                ->timeout(15)
                ->post($this->url($transfer, 'transfer/progress'), [
                    'items_total' => $total,
                    'items_complete' => $progress['complete'],
                    'items_failed' => $progress['failed'],
                    'items_skipped' => $progress['skipped'],
                    'items_pending' => $progress['pending'],
                    // What landed, not what left: summed from bytes actually
                    // written and verified, and an item only becomes complete
                    // after the rename succeeds. Bytes on the wire were the
                    // misleading number in every false alarm today - a file can
                    // transfer in full and still fail to be placed.
                    'bytes_complete' => $progress['done_bytes'],
                    'bytes_total' => (int) $transfer->items()->sum('expected_bytes'),
                    // This job is running, so the worker that runs it is
                    // alive by construction.
                    'worker_alive' => true,
                    'state' => $transfer->state,
                    'note' => $transfer->last_error,
                ]);
        } catch (\Throwable $e) {
            // Logged rather than silent: a source that never hears anything
            // should be able to find out why from the receiving end.
            Log::info('Could not report transfer progress to the source', [
                'transfer' => $transfer->id,
                'error' => $this->truncate($e->getMessage()),
            ]);
        }
    }

    public function fetch(TransferItem $item): bool
    {
        $transfer = $item->transfer;
        $destination = Storage::path($item->path);

        if ($this->alreadyHave($item, $destination)) {
            $item->forceFill(['state' => TransferItem::SKIPPED])->save();

            return true;
        }

        $item->forceFill([
            'state' => TransferItem::TRANSFERRING,
            'attempts' => $item->attempts + 1,
        ])->save();

        $temporary = $destination . '.part';

        if (! is_dir(dirname($destination)) && ! @mkdir(dirname($destination), 0775, true)) {
            $item->markFailed('Could not create the folder for it.');

            return false;
        }

        try {
            // Resumed at the byte rather than the file: an interrupted 4 GB
            // film continues where it stopped.
            $from = is_file($temporary) ? filesize($temporary) : 0;

            // A `.part` that is already the whole file needs placing, not
            // fetching. This is what the released-handle bug left behind on
            // Windows: the bytes all arrived and only the move failed, so
            // re-requesting from EOF would ask for a range past the end and be
            // refused. Every one of those names was otherwise unreachable for
            // good.
            if ($item->expected_bytes > 0 && $from === (int) $item->expected_bytes) {
                return $this->verifyAndPlace($item, $temporary, $destination);
            }

            $response = $this->http()->withToken($transfer->token)
                ->withHeaders($from > 0 ? ['Range' => "bytes={$from}-"] : [])
                ->timeout(600)
                ->sink($from > 0 ? fopen($temporary, 'ab') : $temporary)
                ->get($this->url($transfer, 'transfer/file/' . $item->remote_id));

            if (! $response->successful() && $response->status() !== 206) {
                $item->markFailed('The server answered ' . $response->status() . '.');

                // 401 and 403 are not about this file. The token has expired —
                // they last four hours from approval — or the transfer was
                // revoked at the other end, and every remaining file will
                // answer the same way. Left alone it retried a dead token
                // through 6,964 more items, none of which could ever arrive.
                if (in_array($response->status(), [401, 403], true)) {
                    $this->authorisationLost($transfer, $response->status());
                }

                return false;
            }
        } catch (\Throwable $e) {
            $item->markFailed($e->getMessage());

            return false;
        }

        // Before the file is touched. The sink holds an open handle on
        // `$temporary`, and Windows refuses to move or reopen a file another
        // handle still has — the download completes, every byte arrives, and
        // the placement fails with "Permission denied". The name is then
        // poisoned: the `.part` is left behind and that file can never arrive.
        //
        // `importDatabase()` already did this for the catalogue archive. The
        // per-file path did not, which is the same bug one layer down.
        $this->releaseSink($response);

        return $this->verifyAndPlace($item, $temporary, $destination);
    }

    /**
     * Replaces this machine's catalogue with the source's.
     *
     * The whole database rather than row-by-row: it is one file, it compresses
     * by 85%, and it arrives atomically — a half-imported catalogue is a much
     * worse thing to be left holding than a failed download.
     *
     * The existing database is backed up first, without asking. It holds play
     * history, playlists and profiles that rescanning cannot rebuild, and
     * someone who chose "the catalogue" has almost certainly not thought about
     * losing theirs.
     */
    public function importDatabase(Transfer $transfer): bool
    {
        $backup = $this->backupExisting();

        if ($backup === null) {
            $transfer->forceFill([
                'last_error' => 'Could not back up this machine\'s database, so nothing was replaced.',
            ])->save();

            return false;
        }

        $temporary = $this->archivePath($transfer);

        try {
            $response = $this->http()->withToken($transfer->token)
                ->timeout(300)
                ->sink($temporary)
                ->get($this->url($transfer, 'transfer/database'));

            // Before anything reads, unlinks or renames the archive. The
            // response holds the sink file open for as long as it is alive,
            // and on Windows unlinking a file that still has a handle open
            // does not remove the name — it leaves it in a delete-pending
            // state where every later open fails with "Permission denied".
            // That is how one failed transfer made every transfer after it
            // fail before it had started.
            $this->releaseSink($response);

            if (! $response->successful()) {
                $transfer->forceFill([
                    'last_error' => $this->truncate(
                        'The catalogue could not be read (' . $response->status() . ').'
                        . $this->reasonFrom($temporary),
                    ),
                ])->save();

                Log::error('A catalogue transfer failed', [
                    'transfer' => $transfer->id,
                    'source' => $transfer->source_url,
                    'status' => $response->status(),
                    // The body went to the sink rather than into memory, so
                    // this is read back from the file — bounded, because what
                    // arrived is only known to be an error, not to be small.
                    'reason' => trim($this->reasonFrom($temporary)) ?: null,
                ]);

                @unlink($temporary);

                return false;
            }
        } catch (\Throwable $e) {
            $transfer->forceFill([
                'last_error' => $this->truncate('The catalogue transfer failed: ' . $this->explain($e)),
            ])->save();

            Log::error('A catalogue transfer failed', [
                'transfer' => $transfer->id,
                'source' => $transfer->source_url,
                'archive' => $temporary,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        // Taken before the swap, because the swap destroys it. This
        // transfer's row and its work list live in the database being
        // replaced, so the arriving catalogue overwrites the record of the
        // transfer that is fetching it — with the *source's* transfer
        // bookkeeping, which describes copies the source was making and means
        // nothing here.
        $ourRecord = $this->recordOf($transfer);

        if (! $this->unpackDatabase($transfer, $temporary, $backup)) {
            return false;
        }

        $this->restoreRecord($ourRecord);
        $this->makeCataloguePathsRelative();

        return true;
    }

    /**
     * Rewrites an imported catalogue's file paths to this machine's shape.
     *
     * A catalogue from another server carries that server's paths, and on the
     * transfer this was written for they were absolute:
     * `/Users/…/storage/app/private/media/…`. `MediaItem::absoluteFilePath()`
     * returns an absolute path as-is when it is readable and null when it is
     * not — and `/Users/…` is not readable on Windows — so **every item in the
     * imported library resolved to nothing**. The files would have arrived and
     * the catalogue would still not have found one of them.
     *
     * Only rows that cannot be read as they stand are touched, and only where
     * the media root can be recovered from them, so a catalogue that already
     * holds relative paths passes through untouched.
     *
     * @return int how many were rewritten
     */
    public function makeCataloguePathsRelative(): int
    {
        $changed = 0;

        MediaItem::query()
            ->whereNotNull('file_path')
            ->select(['id', 'file_path'])
            ->chunkById(500, function ($items) use (&$changed): void {
                foreach ($items as $item) {
                    $relative = $this->safePath((string) $item->file_path);

                    if ($relative === null || $relative === $item->file_path) {
                        continue;
                    }

                    MediaItem::whereKey($item->id)->update(['file_path' => $relative]);

                    $changed++;
                }
            });

        if ($changed > 0) {
            Log::warning('An imported catalogue had its paths rewritten for this machine', [
                'rewritten' => $changed,
            ]);
        }

        return $changed;
    }

    /**
     * This transfer and its work list, as plain rows.
     *
     * @return array{transfer: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function recordOf(Transfer $transfer): array
    {
        return [
            'transfer' => (array) \DB::table('transfers')->where('id', $transfer->id)->first(),
            'items' => \DB::table('transfer_items')
                ->where('transfer_id', $transfer->id)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }

    /**
     * Puts this transfer back into the catalogue that just replaced it.
     *
     * Without this the transfer cannot continue and cannot be resumed: the
     * row carries the token, and the items are the work list — 8,309 of them
     * for the transfer this was found on, all of which the import discarded
     * before a single file had been fetched.
     *
     * Anything already occupying those ids came from the source and is its
     * own record of its own transfers, so ours replaces it.
     *
     * @param array{transfer: array<string, mixed>, items: array<int, array<string, mixed>>} $record
     */
    private function restoreRecord(array $record): void
    {
        if ($record['transfer'] === []) {
            return;
        }

        $id = $record['transfer']['id'];

        try {
            \DB::table('transfer_items')->where('transfer_id', $id)->delete();
            \DB::table('transfers')->where('id', $id)->delete();

            \DB::table('transfers')->insert($record['transfer']);

            // In chunks: a full library is thousands of rows and SQLite has a
            // limit on how many variables one statement may bind.
            foreach (array_chunk($record['items'], 200) as $chunk) {
                \DB::table('transfer_items')->insert($chunk);
            }

            Log::info('A transfer was carried across the catalogue it imported', [
                'transfer' => $id,
                'items' => count($record['items']),
            ]);
        } catch (\Throwable $e) {
            // Said plainly: the catalogue is in place but the transfer cannot
            // continue, and the reason is not something the next step can
            // discover for itself.
            Log::error('A transfer could not be carried across its own catalogue import', [
                'transfer' => $id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Where this transfer's catalogue archive is written.
     *
     * Per transfer rather than one shared name. The shared name meant a single
     * archive left behind — or worse, left in a delete-pending state by
     * `releaseSink()`'s absence — blocked every later transfer with a
     * permission error before it had asked the source for anything.
     */
    public function archivePath(Transfer $transfer): string
    {
        return storage_path('app/transfer-' . $transfer->id . '-incoming.sqlite.gz');
    }

    /**
     * Closes the file the response was streamed into.
     *
     * The handle would close on its own when the response is collected, which
     * is too late: the archive is unlinked and reopened while the response is
     * still in scope, and on Windows that is the difference between a working
     * transfer and a permission error that survives it.
     */
    private function releaseSink(\Illuminate\Http\Client\Response $response): void
    {
        try {
            $response->toPsrResponse()->getBody()->close();
        } catch (\Throwable $e) {
            // Not worth abandoning a catalogue over: the handle still closes
            // with the response, just later than we would like.
            Log::warning('A transfer sink could not be closed early', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * What the source said, when it said anything.
     *
     * With `sink()` the body is on disk rather than in memory, so a failure
     * that the other machine explained in full arrived and was thrown away —
     * and the only way to read the explanation was to go to that machine's
     * log. Read bounded: an error body is normally a sentence of JSON, but
     * nothing guarantees it.
     */
    private function reasonFrom(string $archive): string
    {
        $head = @file_get_contents($archive, false, null, 0, 2048);

        if (! is_string($head) || trim($head) === '') {
            return '';
        }

        $decoded = json_decode($head, true);

        $message = is_array($decoded)
            ? ($decoded['message'] ?? $decoded['error'] ?? null)
            // Not JSON: an HTML error page or a plain string. Worth keeping,
            // but only the readable part of it.
            : trim(strip_tags($head));

        if (! is_string($message) || trim($message) === '') {
            return '';
        }

        return ' The server said: ' . trim(preg_replace('/\s+/', ' ', $message));
    }

    /** `last_error` is a 255-column, and a truncated reason beats a lost one. */
    private function truncate(string $message): string
    {
        return mb_strlen($message) > 255
            ? mb_substr($message, 0, 252) . '...'
            : $message;
    }

    /**
     * Decompresses and swaps in the received catalogue.
     *
     * Written beside the live database and moved into place, so a failure part
     * way leaves the existing one untouched rather than truncated.
     */
    private function unpackDatabase(Transfer $transfer, string $archive, string $backup): bool
    {
        $target = $this->databaseFile();

        if ($target === null) {
            $transfer->forceFill([
                'last_error' => 'This instance has no database file to replace.',
            ])->save();

            return false;
        }

        $staged = $target . '.incoming';

        $in = gzopen($archive, 'rb');
        $out = fopen($staged, 'wb');

        if ($in === false || $out === false) {
            $transfer->forceFill(['last_error' => 'Could not unpack the catalogue.'])->save();

            return false;
        }

        while (! gzeof($in)) {
            fwrite($out, gzread($in, 1024 * 512));
        }

        gzclose($in);
        fclose($out);
        @unlink($archive);

        // A SQLite file starts with a known string. Checked before anything is
        // replaced, because the alternative is discovering it was HTML from a
        // login page after the real database has gone.
        if (file_get_contents($staged, false, null, 0, 15) !== 'SQLite format 3') {
            @unlink($staged);

            $transfer->forceFill([
                'last_error' => 'What arrived was not a database. Nothing was replaced.',
            ])->save();

            return false;
        }

        if (! $this->putInPlace($staged, $target)) {
            // Kept, not deleted. What arrived is correct — it downloaded,
            // unpacked and passed the header check — and throwing it away
            // means fetching the whole catalogue again to retry a rename.
            $transfer->forceFill([
                'last_error' => $this->truncate(
                    'The catalogue arrived but could not be put in place. Something else '
                    . 'still has the database open — stop the app and the queue worker, '
                    . 'then resume. It is kept at ' . basename($staged) . '.',
                ),
            ])->save();

            Log::error('A catalogue could not be put in place', [
                'transfer' => $transfer->id,
                'staged' => $staged,
                'target' => $target,
            ]);

            return false;
        }

        // The old write-ahead log belongs to the catalogue that was just
        // replaced. SQLite checks it against the database header and should
        // reject a mismatched one, but leaving several megabytes of another
        // database's pending writes beside a fresh file is not something to
        // rely on being ignored.
        foreach (['-wal', '-shm'] as $sidecar) {
            if (is_file($target . $sidecar)) {
                @unlink($target . $sidecar);
            }
        }

        Log::warning('This catalogue was replaced by another server\'s', [
            'transfer' => $transfer->id,
            'source' => $transfer->source_url,
            'backup' => $backup,
        ]);

        return true;
    }

    /**
     * Swaps the arrived catalogue in for the live one.
     *
     * `rename()` is the right way to do this and is atomic, so it is tried
     * first. It cannot be the only way: on Windows a rename over a file
     * another process holds open fails with "Access is denied", and the
     * catalogue is held open by every part of the app that is running —
     * including the queue worker running this, through its own connection.
     * That is why the connection is dropped first, and why there is a second
     * route at all.
     *
     * The fallback writes over the existing file rather than replacing it,
     * which Windows does permit. It is not atomic, which is exactly why it is
     * second: an interrupted write leaves a corrupt catalogue, and the only
     * thing standing behind that is the backup taken before any of this.
     */
    private function putInPlace(string $staged, string $target): bool
    {
        // This process holds the catalogue open too, so without dropping it
        // the rename cannot succeed on Windows however much else is stopped.
        //
        // Only when the live connection really is the file being replaced.
        // Read from the connection rather than from config(): the two differ
        // whenever something has pointed config elsewhere, and disconnecting
        // an `:memory:` connection destroys the database rather than releasing
        // a handle on it.
        $live = \DB::connection()->getConfig('database');

        if (is_string($live)
            && $live !== ':memory:'
            && realpath($live) !== false
            && realpath($live) === realpath($target)) {
            try {
                \DB::disconnect();
            } catch (\Throwable $e) {
                Log::warning('The catalogue connection could not be dropped before the swap', [
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        if (@rename($staged, $target)) {
            return true;
        }

        // Everything still running holds the catalogue open — the app, the
        // queue worker, the scheduler. Windows refuses a rename over any of
        // them, so the contents are written into the existing file instead.
        Log::warning('The catalogue could not be renamed into place, writing over it instead', [
            'target' => $target,
            'note' => 'Every process using this catalogue must be restarted.',
        ]);

        $in = @fopen($staged, 'rb');
        $out = @fopen($target, 'wb');

        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }

            if ($out !== false) {
                fclose($out);
            }

            return false;
        }

        $written = 0;

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 512);

            if ($chunk === false) {
                break;
            }

            $bytes = fwrite($out, $chunk);

            if ($bytes === false) {
                break;
            }

            $written += $bytes;
        }

        fclose($in);
        fclose($out);

        // Short means the live catalogue is now part old and part new, which
        // is worse than either. Said plainly rather than reported as success.
        if ($written !== filesize($staged)) {
            Log::error('The catalogue was only partly written', [
                'written' => $written,
                'expected' => filesize($staged),
                'target' => $target,
            ]);

            return false;
        }

        @unlink($staged);

        return true;
    }

    /**
     * Copies the current database somewhere safe.
     *
     * @return string|null the path, or null if it could not be done — in which
     *                     case nothing is replaced.
     */
    private function backupExisting(): ?string
    {
        $source = $this->databaseFile();

        if ($source === null) {
            // Nothing on disk to lose — an in-memory database, which is every
            // test run.
            return 'none';
        }

        if (! is_file($source)) {
            // Nothing to lose. A fresh install receiving its first catalogue.
            return 'none';
        }

        $directory = storage_path('app/backups');

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true)) {
            return null;
        }

        $path = $directory . '/before-transfer-' . now()->format('Y-m-d_His') . '.sqlite';

        // Folded in before copying. In WAL mode recent writes live in
        // `database.sqlite-wal` rather than the file being copied — 4.3 MB of
        // it on the machine this was found on — so a copy of the main file
        // alone is a backup missing whatever was written most recently, which
        // is the part least likely to exist anywhere else.
        try {
            \DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $e) {
            // Worth saying, not worth refusing over: the copy below is still a
            // better backup than none, it is just potentially short of the tail.
            Log::warning('The catalogue could not be checkpointed before backing it up', [
                'reason' => $e->getMessage(),
            ]);
        }

        return @copy($source, $path) ? $path : null;
    }

    /**
     * The SQLite file this instance is actually using.
     *
     * Read from the connection rather than assumed to be
     * database_path('database.sqlite'), which is a hardcoded path that ignores
     * configuration entirely — under test the connection is :memory: and that
     * assumption wrote to the real library's database and destroyed it.
     *
     * Returns null when there is no file to replace, which is the case in
     * memory and the case where refusing is correct.
     */
    private function databaseFile(): ?string
    {
        $path = config('database.connections.' . config('database.default') . '.database');

        if (! is_string($path) || $path === ':memory:' || $path === '') {
            return null;
        }

        return $path;
    }

    /** Whether the file is already here and correct. */
    private function alreadyHave(TransferItem $item, string $destination): bool
    {
        if (! is_file($destination)) {
            return false;
        }

        // No hash from the source is not proof of anything, so fall back to
        // size — weaker, but it still catches a half-copied file.
        if (blank($item->expected_hash)) {
            return $item->expected_bytes > 0
                && filesize($destination) === (int) $item->expected_bytes;
        }

        return hash_file(self::HASH, $destination) === $item->expected_hash;
    }

    /**
     * Checks what arrived before letting it become the real file.
     *
     * The part file is deleted on a mismatch rather than kept: half a film
     * that looks like a film is worse than no film, because nothing will ever
     * tell you it is wrong.
     */
    private function verifyAndPlace(TransferItem $item, string $temporary, string $destination): bool
    {
        if (! is_file($temporary)) {
            $item->markFailed('Nothing arrived.');

            return false;
        }

        $size = filesize($temporary);

        if ($item->expected_bytes > 0 && $size !== (int) $item->expected_bytes) {
            @unlink($temporary);
            $item->markFailed("Short: expected {$item->expected_bytes} bytes, got {$size}.");

            return false;
        }

        if (filled($item->expected_hash) && hash_file(self::HASH, $temporary) !== $item->expected_hash) {
            @unlink($temporary);
            $item->markFailed('The file arrived corrupt — its hash did not match.');

            return false;
        }

        if (! @rename($temporary, $destination)) {
            $item->markFailed('Could not move it into place.');

            return false;
        }

        $item->forceFill([
            'state' => TransferItem::COMPLETE,
            'bytes_received' => $size,
        ])->save();

        return true;
    }

    /** @param array<string, mixed> $query */
    private function get(Transfer $transfer, string $path, array $query = []): ?\Illuminate\Http\Client\Response
    {
        try {
            $response = $this->http()->withToken($transfer->token)
                ->acceptJson()
                ->timeout(60)
                ->get($this->url($transfer, $path), $query);

            if (! $response->successful()) {
                $reason = $response->json('message') ?: trim(strip_tags($response->body()));

                $transfer->forceFill([
                    'last_error' => $this->truncate(
                        $path . ' answered ' . $response->status() . '.'
                        . (is_string($reason) && $reason !== ''
                            ? ' The server said: ' . trim(preg_replace('/\s+/', ' ', $reason))
                            : ''),
                    ),
                ])->save();

                Log::warning('A transfer request was refused', [
                    'transfer' => $transfer->id,
                    'path' => $path,
                    'status' => $response->status(),
                    'reason' => is_string($reason) && $reason !== '' ? $reason : null,
                ]);

                return null;
            }

            return $response;
        } catch (\Throwable $e) {
            $transfer->forceFill(['last_error' => $e->getMessage()])->save();

            Log::warning('A transfer request failed', [
                'transfer' => $transfer->id,
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function url(Transfer $transfer, string $path): string
    {
        return rtrim($transfer->source_url, '/') . '/api/v1/' . $path;
    }

    /**
     * HTTP client for transfer calls.
     *
     * cURL/OpenSSL trust can differ between long-running PHP processes on
     * Windows. When a CA bundle is configured or found beside the running PHP
     * binary, force that bundle so transfer requests do not fail with
     * "unable to get local issuer certificate".
     */
    /**
     * How long to wait for the other machine to answer the phone.
     *
     * The default is ten seconds, and three failures in one transfer were
     * `cURL error 28: Connection timed out after 10014 milliseconds` — two of
     * them while the source was up and thirty-one other files were arriving
     * fine. Both machines were on a *relayed* tailnet rather than a direct
     * connection, which is slower to establish and occasionally slower than
     * ten seconds.
     *
     * Only the connection. The request timeouts are unchanged, because a
     * server that has accepted a connection and then gone quiet is a
     * different problem and should still be given up on.
     */
    private const CONNECT_SECONDS = 30;

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $ca = $this->caBundlePath();

        return Http::withOptions([
            'verify' => filled($ca) ? $ca : true,
        ])->connectTimeout(self::CONNECT_SECONDS);
    }

    private function caBundlePath(): ?string
    {
        $configured = config('services.transfer.ca_bundle');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $nextToPhp = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($nextToPhp)) {
            return $nextToPhp;
        }

        return null;
    }
}

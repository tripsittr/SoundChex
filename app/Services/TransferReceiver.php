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
        $bytes = 0;

        do {
            $response = $this->get($transfer, 'transfer/manifest', ['page' => $page]);

            if ($response === null) {
                return false;
            }

            foreach ($response->json('items', []) as $item) {
                TransferItem::updateOrCreate(
                    ['transfer_id' => $transfer->id, 'remote_id' => $item['id']],
                    [
                        'path' => $item['path'],
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
        } while ($files < $total && $response->json('items') !== []);

        $transfer->forceFill([
            'total_files' => $files,
            'total_bytes' => $bytes,
        ])->save();

        return true;
    }

    /**
     * Fetches one file, verifying before and after.
     *
     * Before, because a file already present with the right hash needs no
     * fetching — which is what makes a resumed transfer cheap and a repeated
     * one free. After, because a truncated file that looks present is worse
     * than one plainly absent.
     */
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

            $response = $this->http()->withToken($transfer->token)
                ->withHeaders($from > 0 ? ['Range' => "bytes={$from}-"] : [])
                ->timeout(600)
                ->sink($from > 0 ? fopen($temporary, 'ab') : $temporary)
                ->get($this->url($transfer, 'transfer/file/' . $item->remote_id));

            if (! $response->successful() && $response->status() !== 206) {
                $item->markFailed('The server answered ' . $response->status() . '.');

                return false;
            }
        } catch (\Throwable $e) {
            $item->markFailed($e->getMessage());

            return false;
        }

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

        return $this->unpackDatabase($transfer, $temporary, $backup);
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

        if (! @rename($staged, $target)) {
            @unlink($staged);

            $transfer->forceFill(['last_error' => 'Could not put the new catalogue in place.'])->save();

            return false;
        }

        Log::warning('This catalogue was replaced by another server\'s', [
            'transfer' => $transfer->id,
            'source' => $transfer->source_url,
            'backup' => $backup,
        ]);

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
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $ca = $this->caBundlePath();

        if (filled($ca)) {
            return Http::withOptions(['verify' => $ca]);
        }

        return Http::withOptions(['verify' => true]);
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

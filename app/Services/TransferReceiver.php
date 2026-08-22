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

        return 'Could not reach that server: ' . $message;
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

        $temporary = storage_path('app/transfer-incoming.sqlite.gz');

        try {
            $response = $this->http()->withToken($transfer->token)
                ->timeout(300)
                ->sink($temporary)
                ->get($this->url($transfer, 'transfer/database'));

            if (! $response->successful()) {
                $transfer->forceFill([
                    'last_error' => 'The catalogue could not be read (' . $response->status() . ').',
                ])->save();

                return false;
            }
        } catch (\Throwable $e) {
            $transfer->forceFill(['last_error' => 'The catalogue transfer failed: ' . $e->getMessage()])->save();

            return false;
        }

        return $this->unpackDatabase($transfer, $temporary, $backup);
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
                $transfer->forceFill([
                    'last_error' => $path . ' answered ' . $response->status() . '.',
                ])->save();

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

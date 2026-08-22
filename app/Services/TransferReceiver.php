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
            $response = Http::acceptJson()->timeout(20)->post(
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
                'last_error' => 'Could not reach that server: ' . $e->getMessage(),
            ])->save();

            return false;
        }
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
            $response = Http::acceptJson()->timeout(15)->get(
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

            $response = Http::withToken($transfer->token)
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
            $response = Http::withToken($transfer->token)
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
}

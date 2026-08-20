<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the desktop apps look for a new version.
 *
 * Served by the library itself: a self-hosted application has no reason to ask
 * a third party whether it has an update, and the server is already the one
 * thing every client talks to.
 *
 * Desktop only, and not by choice — iOS forbids an app installing its own
 * binary, so the plugin has no implementation there. It matters less than it
 * sounds: almost nothing about this app is compiled into the client, so a
 * deploy already reaches a phone. Only the native shell needs this.
 *
 * Releases are files dropped in storage/app/updates. There is no build pipeline
 * behind it, because for one household there does not need to be.
 */
class UpdateController extends Controller
{
    public function show(Request $request, string $target, string $arch, string $currentVersion): JsonResponse
    {
        $release = $this->latestFor($target, $arch);

        // 204 is what the updater expects for "you are up to date". A 404 would
        // read as a broken endpoint and be retried.
        if ($release === null || ! $this->isNewer($release['version'], $currentVersion)) {
            return response()->json([], 204);
        }

        return response()->json([
            'version' => $release['version'],
            'notes' => $release['notes'],
            'pub_date' => $release['pub_date'],
            'url' => route('api.updates.download', [
                'target' => $target,
                'arch' => $arch,
                'file' => $release['file'],
            ]),
            // The detached signature. Without it the client refuses the update,
            // which is the point: this endpoint is reachable by anything on the
            // tailnet and a bundle it serves gets installed.
            'signature' => $release['signature'],
        ]);
    }

    public function download(string $target, string $arch, string $file)
    {
        $path = $this->releaseDirectory($target, $arch) . '/' . basename($file);

        abort_unless(is_file($path), 404);

        return response()->download($path);
    }

    /**
     * @return array{version: string, notes: string, pub_date: string, file: string, signature: string}|null
     */
    private function latestFor(string $target, string $arch): ?array
    {
        $directory = $this->releaseDirectory($target, $arch);
        $manifest = $directory . '/latest.json';

        if (! is_file($manifest)) {
            return null;
        }

        $release = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($release) || blank($release['version'] ?? null)) {
            return null;
        }

        $signature = $directory . '/' . basename($release['file'] ?? '') . '.sig';

        return [
            'version' => (string) $release['version'],
            'notes' => (string) ($release['notes'] ?? ''),
            'pub_date' => (string) ($release['pub_date'] ?? now()->toIso8601String()),
            'file' => (string) ($release['file'] ?? ''),
            'signature' => is_file($signature) ? trim((string) file_get_contents($signature)) : '',
        ];
    }

    private function releaseDirectory(string $target, string $arch): string
    {
        // Segments come from the URL, so anything that could climb out of the
        // directory is stripped rather than trusted.
        return storage_path('app/updates/'
            . preg_replace('/[^a-z0-9_-]/i', '', $target) . '/'
            . preg_replace('/[^a-z0-9_-]/i', '', $arch));
    }

    private function isNewer(string $candidate, string $current): bool
    {
        return version_compare($candidate, $current, '>');
    }
}

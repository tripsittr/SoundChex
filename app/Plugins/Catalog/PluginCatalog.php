<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a plugin repository — a JSON catalog at a URL (S-264 Phase 4b).
 *
 * The Emby/Jellyfin model: an admin adds a repository URL, the server fetches
 * its `manifest.json` (an array of plugins, each with a `versions` list newest
 * first), and this turns that feed into installable candidates — each narrowed
 * to the newest version this server can actually run, so an incompatible plugin
 * is shown as such rather than offered and then failing.
 *
 * A repository is untrusted input from the network: a fetch that fails or
 * returns junk yields an empty list and a logged warning, never an exception up
 * into the request.
 */
class PluginCatalog
{
    /**
     * The installable plugins a repository lists, compatible with this server.
     *
     * @return array<int, CatalogEntry>
     */
    public function fetch(string $repositoryUrl): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get($repositoryUrl);

            if (! $response->successful()) {
                Log::warning('Plugin repository returned an error', [
                    'url' => $repositoryUrl,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $plugins = $response->json();
        } catch (\Throwable $e) {
            Log::warning('Plugin repository could not be reached', [
                'url' => $repositoryUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($plugins)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($plugin): ?CatalogEntry => is_array($plugin) ? $this->entry($plugin) : null,
            $plugins,
        )));
    }

    /**
     * Builds one catalog entry, choosing the newest version this server can run.
     *
     * @param  array<string, mixed>  $plugin
     */
    private function entry(array $plugin): ?CatalogEntry
    {
        $id = $plugin['id'] ?? $plugin['guid'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $versions = array_values(array_filter(
            (array) ($plugin['versions'] ?? []),
            fn ($v): bool => is_array($v) && isset($v['version'], $v['sourceUrl']),
        ));

        return new CatalogEntry(
            id: $id,
            name: is_string($plugin['name'] ?? null) ? $plugin['name'] : $id,
            description: is_string($plugin['description'] ?? null) ? $plugin['description'] : null,
            author: is_string($plugin['author'] ?? null) ? $plugin['author'] : null,
            versions: $versions,
            installable: $this->newestCompatible($versions),
        );
    }

    /**
     * The newest version whose `targetAbi` / `requiresPhp` this server satisfies.
     *
     * Versions are expected newest-first; each is checked in turn and the first
     * that fits wins, so one catalog can serve many server versions at once —
     * the same gate the loader applies to an installed plugin, applied before
     * download so nothing incompatible is ever fetched.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @return array<string, mixed>|null
     */
    private function newestCompatible(array $versions): ?array
    {
        $server = config('soundchex.version', '0.0.0');

        foreach ($versions as $version) {
            $abi = $version['targetAbi'] ?? null;
            $php = $version['requiresPhp'] ?? null;

            if (is_string($abi) && version_compare($server, $abi, '<')) {
                continue;
            }

            if (is_string($php) && version_compare(PHP_VERSION, $php, '<')) {
                continue;
            }

            return $version;
        }

        return null;
    }
}

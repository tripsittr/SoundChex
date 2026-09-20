<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Catalog;

use App\Plugins\Exceptions\PluginInstallException;
use App\Plugins\PluginLoader;
use App\Plugins\PluginManifest;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Downloads and installs a plugin from a catalog entry (S-264 Phase 4b).
 *
 * The one place the server pulls third-party code over the network, so it is the
 * one place the checks live: verify the download's checksum, confirm the zip
 * carries a valid, compatible manifest, and extract it without letting an entry
 * escape the plugins directory (zip-slip). Only then does it become an installed
 * plugin — still disabled, because installing is not enabling.
 *
 * Every failure throws PluginInstallException with a reason the UI can show;
 * nothing is left half-extracted.
 */
class PluginInstaller
{
    public function __construct(private readonly PluginLoader $loader) {}

    /**
     * Installs the entry's compatible version, returning the plugin id.
     *
     * @throws PluginInstallException
     */
    public function install(CatalogEntry $entry): string
    {
        if (! $entry->isInstallable()) {
            throw new PluginInstallException("No version of {$entry->name} is compatible with this server.");
        }

        $zipBytes = $this->download((string) $entry->sourceUrl());
        $this->verifyChecksum($zipBytes, $entry->checksum());

        $zipPath = $this->stage($zipBytes);

        try {
            $manifest = $this->manifestFromZip($zipPath);

            if (! $manifest->isCompatibleWith(config('soundchex.version', '0.0.0'), PHP_VERSION)) {
                throw new PluginInstallException("{$manifest->name} needs a newer server or PHP than this one.");
            }

            $target = rtrim((string) config('soundchex.plugins.path'), '/').'/'.$this->safeDirName($manifest->id);

            $this->extract($zipPath, $target);
        } finally {
            @unlink($zipPath);
        }

        // Re-scan so the new folder becomes an installed_plugins row (disabled).
        $this->loader->discover();

        return $manifest->id;
    }

    /**
     * @throws PluginInstallException
     */
    private function download(string $url): string
    {
        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new PluginInstallException("Could not download the plugin: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new PluginInstallException("The plugin download failed (HTTP {$response->status()}).");
        }

        return $response->body();
    }

    /**
     * Integrity, not authenticity: a checksum catches a corrupted or tampered-
     * in-transit download. It cannot prove who built the plugin — that is what
     * the curated repository and the enable-time warning are for. When the
     * catalog gives no checksum, we cannot check, and say so by simply skipping.
     *
     * @throws PluginInstallException
     */
    private function verifyChecksum(string $bytes, ?string $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        // Accept "sha256:…" or a bare hash; match on whichever algorithm's length
        // the expected value looks like.
        $expected = strtolower(trim($expected));
        [$algo, $hash] = str_contains($expected, ':')
            ? explode(':', $expected, 2)
            : [strlen($expected) === 64 ? 'sha256' : 'md5', $expected];

        $actual = hash($algo, $bytes);

        if (! hash_equals($hash, $actual)) {
            throw new PluginInstallException('The plugin download did not match its checksum — refusing to install.');
        }
    }

    /** Writes the download to a temp file for ZipArchive to open. */
    private function stage(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scx-plugin-').'.zip';
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * Reads the manifest out of the zip without extracting anything, so an
     * invalid or incompatible plugin is rejected before a single file lands.
     *
     * @throws PluginInstallException
     */
    private function manifestFromZip(string $zipPath): PluginManifest
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new PluginInstallException('The download is not a readable zip archive.');
        }

        // The manifest may sit at the root or one level down (a zip that wraps
        // everything in a top folder is common); take the shallowest match.
        $index = $zip->locateName('plugin.json', ZipArchive::FL_NODIR);
        $contents = $index !== false ? $zip->getFromIndex($index) : false;

        if ($contents === false) {
            // Try a nested plugin.json.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with((string) $zip->getNameIndex($i), '/plugin.json')) {
                    $contents = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if (! is_string($contents)) {
            throw new PluginInstallException('The plugin archive has no plugin.json manifest.');
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new PluginInstallException('The plugin manifest is not valid JSON.');
        }

        return PluginManifest::fromArray($decoded);
    }

    /**
     * Extracts the zip into the target directory, refusing any entry whose path
     * escapes it (zip-slip). Replaces an existing install of the same id.
     *
     * @throws PluginInstallException
     */
    private function extract(string $zipPath, string $target): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new PluginInstallException('The download is not a readable zip archive.');
        }

        // A zip may wrap its files in a single top-level folder; detect that so
        // the plugin's own plugin.json ends up at the target root either way.
        $prefix = $this->commonPrefix($zip);

        $base = rtrim($target, '/').'/';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            $relative = $prefix !== '' && str_starts_with($name, $prefix)
                ? substr($name, strlen($prefix))
                : $name;

            if ($relative === '' || str_ends_with($relative, '/')) {
                continue; // directory entry
            }

            $destination = $base.$relative;

            // Zip-slip guard: the resolved path must stay under the target.
            if (! $this->within($base, $destination)) {
                $zip->close();
                throw new PluginInstallException('The plugin archive tried to write outside its directory — refusing to install.');
            }

            @mkdir(dirname($destination), 0755, true);
            $stream = $zip->getStream($name);

            if ($stream === false) {
                continue;
            }

            file_put_contents($destination, stream_get_contents($stream));
            fclose($stream);
        }

        $zip->close();
    }

    /**
     * A single top-level directory every entry shares, if there is one — so a
     * zip of `my-plugin/plugin.json` and a zip of `plugin.json` both install the
     * same way.
     */
    private function commonPrefix(ZipArchive $zip): string
    {
        $top = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $first = explode('/', $name)[0] ?? '';

            if ($first === '' || ! str_contains($name, '/')) {
                return ''; // a file at the root means no shared wrapper folder
            }

            if ($top === null) {
                $top = $first;
            } elseif ($top !== $first) {
                return '';
            }
        }

        return $top === null ? '' : $top.'/';
    }

    /**
     * Whether $path resolves to somewhere inside $base, collapsing any `..`
     * first so an entry cannot climb out of the plugins directory. Works on
     * paths that do not exist yet (the files are about to be written).
     */
    private function within(string $base, string $path): bool
    {
        $collapse = static function (string $p): string {
            $parts = [];

            foreach (explode('/', str_replace('\\', '/', $p)) as $segment) {
                if ($segment === '..') {
                    array_pop($parts);
                } elseif ($segment !== '.' && $segment !== '') {
                    $parts[] = $segment;
                }
            }

            return implode('/', $parts);
        };

        $baseResolved = $collapse($base);
        $pathResolved = $collapse($path);

        return $pathResolved === $baseResolved
            || str_starts_with($pathResolved, $baseResolved.'/');
    }

    /** A filesystem-safe directory name from a plugin id. */
    private function safeDirName(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $id) ?? $id;
    }
}

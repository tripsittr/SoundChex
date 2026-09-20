<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins;

use App\Plugins\Exceptions\InvalidManifestException;

/**
 * A parsed, validated `plugin.json` (S-264).
 *
 * The manifest is the plugin's self-description: who it is, what version of the
 * server it needs, which seams it uses, and the class the loader instantiates.
 * Modelled on Jellyfin's manifest and WordPress's header block — identity plus
 * a compatibility gate, so an incompatible plugin is refused rather than loaded
 * and left to fail deep inside a request.
 *
 * @phpstan-type ManifestData array{
 *     id: string, name: string, version: string, entrypoint: string,
 *     author?: string, description?: string, minSoundChexVersion?: string,
 *     requiresPhp?: string, provides?: array<int, string>, configPage?: string,
 *     license?: string
 * }
 */
class PluginManifest
{
    /**
     * @param  array<int, string>  $provides  the extension seams the plugin declares
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $entrypoint,
        public readonly ?string $author = null,
        public readonly ?string $description = null,
        public readonly ?string $minSoundChexVersion = null,
        public readonly ?string $requiresPhp = null,
        public readonly array $provides = [],
        public readonly ?string $configPage = null,
        public readonly ?string $license = null,
    ) {}

    /**
     * Builds a manifest from decoded JSON, refusing anything missing a field the
     * loader cannot work without.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidManifestException
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'version', 'entrypoint'] as $required) {
            if (! isset($data[$required]) || ! is_string($data[$required]) || trim($data[$required]) === '') {
                throw new InvalidManifestException("Manifest is missing the required \"{$required}\" field.");
            }
        }

        // The id namespaces every registration and keys the install table, so a
        // stray slash or space would break look-ups later — pin the shape now.
        if (! preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/i', $data['id'])) {
            throw new InvalidManifestException("Plugin id \"{$data['id']}\" is not a valid slug (letters, digits, . _ -).");
        }

        $provides = array_values(array_filter(
            (array) ($data['provides'] ?? []),
            fn ($v): bool => is_string($v) && $v !== '',
        ));

        return new self(
            id: $data['id'],
            name: $data['name'],
            version: $data['version'],
            entrypoint: $data['entrypoint'],
            author: self::stringOrNull($data['author'] ?? null),
            description: self::stringOrNull($data['description'] ?? null),
            minSoundChexVersion: self::stringOrNull($data['minSoundChexVersion'] ?? null),
            requiresPhp: self::stringOrNull($data['requiresPhp'] ?? null),
            provides: $provides,
            configPage: self::stringOrNull($data['configPage'] ?? null),
            license: self::stringOrNull($data['license'] ?? null),
        );
    }

    /**
     * Reads and parses a `plugin.json` file.
     *
     * @throws InvalidManifestException
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidManifestException("No manifest at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new InvalidManifestException("Manifest at {$path} is not valid JSON.");
        }

        return self::fromArray($decoded);
    }

    /**
     * Whether this plugin can run on the given server and PHP versions.
     *
     * A plugin that needs a newer server than is installed is refused rather
     * than loaded — the same gate as Jellyfin's targetAbi. A missing constraint
     * means "no requirement", so an unconstrained plugin always passes.
     */
    public function isCompatibleWith(string $serverVersion, string $phpVersion): bool
    {
        if ($this->minSoundChexVersion !== null
            && version_compare($serverVersion, $this->minSoundChexVersion, '<')) {
            return false;
        }

        if ($this->requiresPhp !== null
            && version_compare($phpVersion, $this->requiresPhp, '<')) {
            return false;
        }

        return true;
    }

    /** Whether the plugin declares that it provides the given seam. */
    public function provides(string $seam): bool
    {
        return in_array($seam, $this->provides, true);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }
}

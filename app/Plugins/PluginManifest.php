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
        public readonly ?string $targetApi = null,
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
            targetApi: self::stringOrNull($data['targetApi'] ?? null),
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
     * Whether this plugin can run on the given server, plugin API and PHP.
     *
     * Three gates, and the middle one is what makes a plugin *survive versions*:
     *
     *   - minSoundChexVersion: the server must be at least this old — a plugin
     *     that needs a feature added in 0.4 will not load on 0.3.
     *   - targetApi: the plugin's contract version. It keeps working as long as
     *     the server's plugin API is the **same major** and at least the same
     *     minor it was built against. So a plugin built for API 1.2 runs on 1.2
     *     through 1.9 (minors only add), and is refused on 2.0 (the deliberate
     *     overhaul). A plugin with no targetApi is assumed current-major and
     *     takes its chances on a future overhaul.
     *   - requiresPhp: the runtime floor.
     *
     * A missing constraint means "no requirement" and passes.
     */
    public function isCompatibleWith(string $serverVersion, string $pluginApiVersion, string $phpVersion): bool
    {
        return $this->incompatibilityReason($serverVersion, $pluginApiVersion, $phpVersion) === null;
    }

    /**
     * The reason this plugin cannot run here, or null when it can — so the admin
     * UI can say *why* an incompatible plugin is refused, not just that it is.
     */
    public function incompatibilityReason(string $serverVersion, string $pluginApiVersion, string $phpVersion): ?string
    {
        if ($this->minSoundChexVersion !== null
            && version_compare($serverVersion, $this->minSoundChexVersion, '<')) {
            return "needs SoundChex {$this->minSoundChexVersion} or newer (this is {$serverVersion})";
        }

        if ($this->requiresPhp !== null
            && version_compare($phpVersion, $this->requiresPhp, '<')) {
            return "needs PHP {$this->requiresPhp} or newer (this is {$phpVersion})";
        }

        if ($this->targetApi !== null) {
            $wantMajor = $this->majorOf($this->targetApi);
            $haveMajor = $this->majorOf($pluginApiVersion);

            // A different major is the overhaul — old plugins are refused until
            // updated for the new contract, newer plugins expect a contract this
            // server does not have.
            if ($wantMajor !== $haveMajor) {
                return "was built for plugin API {$wantMajor}.x; this server is {$pluginApiVersion} — the plugin needs updating"
                    .($wantMajor < $haveMajor ? '' : ' (this server is older)');
            }

            // Same major, but the plugin was built against a newer minor than
            // this server offers — it may use a seam/event not present yet.
            if (version_compare($pluginApiVersion, $this->targetApi, '<')) {
                return "was built for plugin API {$this->targetApi}; this server offers {$pluginApiVersion} — update the server";
            }
        }

        return null;
    }

    /** The major component of a SemVer string ("1.4.2" -> 1). */
    private function majorOf(string $version): int
    {
        return (int) explode('.', $version)[0];
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

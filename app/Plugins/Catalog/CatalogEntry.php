<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Catalog;

/**
 * One plugin as a catalog lists it (S-264 Phase 4b).
 *
 * A catalog is a JSON document at a URL — the Emby/Jellyfin repository model:
 * an array of plugins, each with a `versions` list (newest first). This is one
 * such plugin, already narrowed to the newest version compatible with this
 * server, so the UI shows an installable candidate rather than a raw feed.
 *
 * @phpstan-type VersionData array{version: string, sourceUrl: string, targetAbi?: string,
 *     checksum?: string, changelog?: string, requiresPhp?: string, timestamp?: string}
 */
class CatalogEntry
{
    /**
     * @param  array<int, array<string, mixed>>  $versions  every published version, newest first
     * @param  array<string, mixed>|null  $installable  the newest version compatible with this server, or null
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $author,
        public readonly array $versions,
        public readonly ?array $installable,
    ) {}

    /** Whether any published version can run on this server. */
    public function isInstallable(): bool
    {
        return $this->installable !== null;
    }

    /** The installable version string, or null when nothing is compatible. */
    public function version(): ?string
    {
        return $this->installable['version'] ?? null;
    }

    public function sourceUrl(): ?string
    {
        return $this->installable['sourceUrl'] ?? null;
    }

    public function checksum(): ?string
    {
        return $this->installable['checksum'] ?? null;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

/**
 * Asserts the PHP runtime has the extensions SoundChex needs.
 *
 * A wrong PHP build disables features *silently* — tag reading returns nothing,
 * HTTPS calls fail, image work throws deep in a job — with no hint that the
 * cause is a missing extension. That is the whole risk the bundled server (see
 * Documentation & Planning/BundledServer.md) is meant to remove: the runtime is
 * ours, so its extension set is ours to guarantee.
 *
 * This is the single source of truth for that set. The `server:check-extensions`
 * command and the Filament health surface both read it, so there is one list to
 * keep in step with `composer.lock` and the bundled-build flags.
 */
class RuntimeHealth
{
    /**
     * Extensions the app genuinely needs to function, each with why — so a
     * failure message can say what breaks, not just what is absent.
     *
     * Audited against composer.lock's transitive `ext-*` requires plus direct
     * runtime use. Kept in step with the bundled build's extension flags
     * (server/, .github/workflows/build-server.yml).
     *
     * @var array<string, string>
     */
    public const REQUIRED = [
        'curl' => 'HTTPS calls to metadata providers and the relay',
        'openssl' => 'TLS for every outbound request and token signing',
        'mbstring' => 'multibyte-safe string handling throughout',
        'intl' => 'locale-aware sorting and number/date formatting',
        'fileinfo' => 'MIME detection when placing and serving files',
        'pdo_sqlite' => 'the database driver',
        'sqlite3' => 'direct SQLite access (catalogue, metadata)',
        'gd' => 'artwork resizing and cover generation',
        'exif' => 'reading embedded artwork/orientation from media tags',
        'zip' => 'library import/export archives',
        'dom' => 'XML/HTML parsing (metadata, subtitles)',
        'xml' => 'XML parsing (metadata feeds)',
        'xmlreader' => 'streaming XML parse for large metadata',
        'xmlwriter' => 'writing XML (exports, sitemaps)',
        'iconv' => 'character-set conversion in tag reading',
        'tokenizer' => 'required by the framework',
        'session' => 'authenticated sessions',
        'phar' => 'archive handling and some vendored tools',
        'filter' => 'input validation',
        'ctype' => 'character-class checks used across parsers',
    ];

    /**
     * Extensions that are POSIX-only or otherwise not present on every platform
     * (Windows has no php-fpm/pcntl). Their absence is a warning, not a failure:
     * the app degrades rather than breaks.
     *
     * @var array<string, string>
     */
    public const RECOMMENDED = [
        'pcntl' => 'lets the queue worker handle signals/timeouts (POSIX only)',
        'gmp' => 'faster UUID generation (ramsey/uuid); not built on Windows',
        'opcache' => 'bytecode caching — a large performance win in production',
        'sodium' => 'modern crypto primitives',
    ];

    /**
     * The required extensions that are missing, as [extension => reason].
     *
     * @return array<string, string>
     */
    public function missingRequired(): array
    {
        return array_filter(
            self::REQUIRED,
            fn (string $ext): bool => ! $this->loaded($ext),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The recommended-but-absent extensions, as [extension => reason].
     *
     * @return array<string, string>
     */
    public function missingRecommended(): array
    {
        return array_filter(
            self::RECOMMENDED,
            fn (string $ext): bool => ! $this->loaded($ext),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** True when every required extension is present. */
    public function isHealthy(): bool
    {
        return $this->missingRequired() === [];
    }

    /**
     * `extension_loaded` is case-insensitive but opcache reports as
     * "Zend OPcache"; normalise the couple of special cases.
     */
    protected function loaded(string $ext): bool
    {
        if ($ext === 'opcache') {
            return extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
        }

        return extension_loaded($ext);
    }
}
